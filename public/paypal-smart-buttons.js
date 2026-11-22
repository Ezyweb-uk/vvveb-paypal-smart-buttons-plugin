/*
 * PayPal Smart Buttons for Vvveb
 *
 * Copyright (C) 2026  Ezyweb.uk
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

(function () {
	// Mirrors each instance's own "Debug mode" admin setting (same flag the
	// server-side error_log() calls already check), so these diagnostic
	// console lines stay silent for real shoppers by default and only
	// appear when that toggle is on — turn it on temporarily in the plugin
	// settings while troubleshooting, then switch it back off.
	//
	// This file is externalized (not inline) so it can be covered by a
	// Content-Security-Policy without 'unsafe-inline' and by ordinary SRI
	// (compute a sha384 hash of this file at deploy time and add
	// integrity="sha384-..." to the <script src="...paypal-smart-buttons.js">
	// tag in paypal-smart-buttons.html — recompute it whenever this file
	// changes). The only per-request value this plugin needs (the "Debug
	// mode" flag) is handed in via a small separate inline config script —
	// see paypal-smart-buttons.html — rather than templated into this file,
	// since a static file can't itself be rendered with vTpl substitutions.
	var DEBUG = !!(window.PaypalSmartButtonsConfig && window.PaypalSmartButtonsConfig.debug);

	function debugLog() {
		if (DEBUG && window.console && console.debug) console.debug.apply(console, arguments);
	}

	var RADIO_SELECTOR = 'input[name=payment_method][value=paypal_smart_buttons]';

	// The checkout theme's address flow is multi-step client-side (pick
	// country -> AJAX-fetch that country's regions -> pick region), and each
	// step swaps DOM / fires its own mutation, not just the final one. If we
	// react to every intermediate mutation immediately, we can call
	// /paypal-smart-resolve with a country set but region not yet chosen
	// (or vice-versa) and wrongly conclude "not configured" — confirmed live
	// in this project as a spurious "PayPal could not be loaded" error while
	// mid-way through changing address. DEBOUNCE_MS waits for things to
	// settle before resolving; RESOLVE_MAX_ATTEMPTS/RESOLVE_RETRY_MS then
	// retry a couple more times before believing a failure, in case the
	// address is still settling even after the debounce.
	var DEBOUNCE_MS = 350;
	var RESOLVE_MAX_ATTEMPTS = 4;
	var RESOLVE_RETRY_MS = 400;
	var debounceTimer = null;

	// Rate-limits the last-resort full-page-reload fallback (see
	// reloadAllowed()/clearReloadAttempts() below) across however many times
	// the page actually reloads, not just within one page load.
	var RELOAD_WINDOW_MS = 60000; // 1 minute
	var RELOAD_MAX_IN_WINDOW = 2;
	var RELOAD_STORAGE_KEY = 'paypalSmartButtonsReloadTimestamps';

	function getContainer() {
		return document.getElementById('paypal-button-container');
	}

	// dataset.paypalRendered is only meaningful while the container still
	// actually holds the buttons it marks itself as having. If the theme
	// updates the payment-option in place — clearing this same div's
	// contents (e.g. to redraw the label/fields for a newly-resolved
	// instance) rather than replacing the div itself with a fresh one — the
	// flag would otherwise survive from the very first render and block
	// every future one, leaving an empty container with no error: exactly
	// "arrow expands, no buttons". So the flag only counts when there's
	// still real content backing it; a flagged-but-empty container is
	// treated as never having been rendered.
	function isActuallyRendered(container) {
		return container.dataset.paypalRendered === '1' && container.childElementCount > 0;
	}

	function getErrorBox() {
		return document.getElementById('paypal-error-message');
	}

	function getCheckoutForm() {
		return document.getElementById('checkout-form');
	}

	function getSdkScript() {
		return document.getElementById('paypal-sdk-script');
	}

	// Targeted by the data-v-url attribute the core theme already puts on
	// the "Place order" button (data-v-url="checkout/checkout/confirm"), so
	// no theme edit is required; if your theme doesn't have that attribute,
	// add id="checkout-place-order" to the button and this will pick that
	// up too. Re-queried each time rather than cached, for the same reason
	// the container/radio below are — the checkout theme can replace this
	// element too.
	function getPlaceOrderBtn() {
		return document.querySelector('[data-v-url="checkout/checkout/confirm"]') || document.getElementById('checkout-place-order');
	}

	function syncPlaceOrderVisibility() {
		var placeOrderBtn = getPlaceOrderBtn();
		if (!placeOrderBtn) return;
		var checked = document.querySelector('input[name=payment_method]:checked');
		placeOrderBtn.style.display = (checked && checked.value === 'paypal_smart_buttons') ? 'none' : '';
	}

	function showError(message, detail) {
		if (detail) console.error('PayPal Smart Buttons error detail:', detail);
		var errorBox = getErrorBox();
		if (!errorBox) return;
		errorBox.textContent = message || 'A payment error occurred. Please try again.';
		errorBox.style.display = 'block';
	}

	function clearError() {
		var errorBox = getErrorBox();
		if (!errorBox) return;
		errorBox.textContent = '';
		errorBox.style.display = 'none';
	}

	function post(path, body, isJson) {
		return fetch(window.VvvebApp.path + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: isJson
				? { 'Content-Type': 'application/json' }
				: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body,
		}).then(function (response) {
			return response.json().catch(function () {
				throw new Error('Unexpected server response.');
			});
		});
	}

	// What client-id/currency the CURRENTLY loaded PayPal SDK <script> was
	// actually initialized with, read straight off its own src rather than
	// tracked separately — so this is always accurate even after
	// reloadSdk() below has swapped the tag out from under us.
	function getLoadedCredentials() {
		var script = getSdkScript();
		if (!script || !script.src) return null;

		try {
			var url = new URL(script.src);
			return {
				clientId: url.searchParams.get('client-id'),
				currency: url.searchParams.get('currency'),
			};
		} catch (e) {
			return null;
		}
	}

	// Removes the currently-loaded PayPal SDK and its global `paypal`
	// object, then loads a fresh one for the given client-id/currency,
	// calling `done()` once it's ready (or failed to load).
	function reloadSdk(clientId, currency, done) {
		var oldScript = getSdkScript();
		if (oldScript && oldScript.parentNode) oldScript.parentNode.removeChild(oldScript);

		try {
			delete window.paypal;
		} catch (e) {
			window.paypal = undefined;
		}

		var script = document.createElement('script');
		script.id = 'paypal-sdk-script';
		// PayPal's SDK injects inline scripts into about:blank iframes, which
		// inherit this page's CSP; data-csp-nonce lets it nonce them.
		if (window.PaypalSmartButtonsConfig && window.PaypalSmartButtonsConfig.cspNonce) {
			script.setAttribute('data-csp-nonce', window.PaypalSmartButtonsConfig.cspNonce);
		}
		script.src = 'https://www.paypal.com/sdk/js?client-id=' + encodeURIComponent(clientId)
			+ '&currency=' + encodeURIComponent(currency)
			+ '&components=buttons&intent=capture';
		script.onload = function () { done(true); };
		script.onerror = function () { done(false); };
		document.head.appendChild(script);
	}

	// The PayPal JS SDK <script> tag is rendered server-side ONCE, from
	// whichever PayPal profile SettingsResolver resolved for that page
	// load's address. If the buyer then changes address client-side (no
	// full page reload) and a DIFFERENT profile now applies — a different
	// client-id, maybe a different currency — that script tag goes stale.
	//
	// A full page reload was tried as the fix first and confirmed NOT
	// reliable: the checkout theme's own address-change handling doesn't
	// necessarily persist the new address to session synchronously with
	// the DOM update this script observes, so a reload can race ahead of
	// it and land back on the OLD address/profile — confirmed live in this
	// project (switching UK to France and reloading still showed the UK
	// Sandbox client-id).
	//
	// Resolving straight from the CURRENT checkout FORM instead (same
	// SettingsResolver::resolveFromForm() call /paypal-smart-create already
	// uses to build the real order) sidesteps that timing question
	// entirely — it doesn't matter whether the session has caught up yet,
	// because this reads the address the buyer has actually entered right
	// now. Swaps in a fresh SDK script only when the resolved client-id/
	// currency actually differ from what's currently loaded.
	function ensureCorrectSdk(callback) {
		attemptResolve(1, callback);
	}

	// Retries /paypal-smart-resolve a few times before giving up: right
	// after an address change the theme's own region-select AJAX may not
	// have finished yet, so an early attempt can legitimately come back
	// "not configured" (e.g. country set, region not yet) even though the
	// address is actually fine a few hundred ms later.
	function attemptResolve(attempt, callback) {
		var checkoutForm = getCheckoutForm();

		if (!checkoutForm) {
			debugLog('[paypal-smart-buttons] attemptResolve #' + attempt + ': no #checkout-form in DOM');
			callback(false);
			return;
		}

		var body = new URLSearchParams(new FormData(checkoutForm));

		post('/paypal-smart-resolve', body, false).then(function (res) {
			debugLog('[paypal-smart-buttons] attemptResolve #' + attempt + ': response', res);

			if (!res || !res.configured || !res.client_id) {
				if (attempt < RESOLVE_MAX_ATTEMPTS) {
					setTimeout(function () { attemptResolve(attempt + 1, callback); }, RESOLVE_RETRY_MS);
					return;
				}

				debugLog('[paypal-smart-buttons] attemptResolve: giving up after ' + attempt + ' attempts, not configured');
				callback(false);
				return;
			}

			var loaded = getLoadedCredentials();
			debugLog('[paypal-smart-buttons] attemptResolve #' + attempt + ': loaded SDK credentials', loaded, 'vs resolved', { clientId: res.client_id, currency: res.currency });

			if (loaded && loaded.clientId === res.client_id && loaded.currency === res.currency) {
				callback(true);
				return;
			}

			debugLog('[paypal-smart-buttons] attemptResolve #' + attempt + ': credentials differ, swapping SDK script');
			reloadSdk(res.client_id, res.currency, function (loadOk) {
				debugLog('[paypal-smart-buttons] reloadSdk finished', { ok: loadOk });
				callback(loadOk);
			});
		}).catch(function (err) {
			debugLog('[paypal-smart-buttons] attemptResolve #' + attempt + ': request failed', err);

			if (attempt < RESOLVE_MAX_ATTEMPTS) {
				setTimeout(function () { attemptResolve(attempt + 1, callback); }, RESOLVE_RETRY_MS);
				return;
			}

			callback(false);
		});
	}

	// Whether a last-resort reload may fire right now, and records this
	// attempt if so. Persisted in sessionStorage rather than a plain JS
	// variable — window.location.reload() starts an entirely new page load,
	// which resets every JS variable, so a `reloadFallbackUsed = true`
	// closure variable guards at most ONE reload per page load and nothing
	// more: if the underlying cause is a genuinely broken resolve (e.g. the
	// server's own call out to PayPal failing every time — confirmed as the
	// live cause of a reload loop in this project), every fresh reload sees
	// a fresh `false` and reloads again, forever, with no way to tell it
	// ever happened before. sessionStorage survives the reload (same tab,
	// same session), so attempts actually accumulate across it.
	//
	// Windowed (not just "N reloads ever") so a shopper who hits this once,
	// waits, and comes back later still gets the fallback's benefit instead
	// of being permanently locked out of it for the rest of their session.
	function reloadAllowed() {
		try {
			var raw = sessionStorage.getItem(RELOAD_STORAGE_KEY);
			var timestamps = raw ? JSON.parse(raw) : [];
			if (!Array.isArray(timestamps)) timestamps = [];

			var now = Date.now();
			timestamps = timestamps.filter(function (t) { return typeof t === 'number' && now - t < RELOAD_WINDOW_MS; });

			if (timestamps.length >= RELOAD_MAX_IN_WINDOW) {
				sessionStorage.setItem(RELOAD_STORAGE_KEY, JSON.stringify(timestamps));
				debugLog('[paypal-smart-buttons] reloadAllowed: limit reached (' + timestamps.length + '/' + RELOAD_MAX_IN_WINDOW + ' in window), refusing further reloads');
				return false;
			}

			timestamps.push(now);
			sessionStorage.setItem(RELOAD_STORAGE_KEY, JSON.stringify(timestamps));
			return true;
		} catch (e) {
			// sessionStorage unavailable or throwing (private browsing, quota,
			// disabled storage) — fail CLOSED. Showing the static error
			// message once is far better than risking an uncounted,
			// unbounded reload loop with no way to track attempts at all.
			debugLog('[paypal-smart-buttons] reloadAllowed: sessionStorage unavailable, refusing reload', e);
			return false;
		}
	}

	// Clears the reload-attempt counter once the SDK has actually loaded
	// successfully, so an earlier transient failure (resolved on retry)
	// doesn't count against a later, unrelated failure much further into
	// the same tab session.
	function clearReloadAttempts() {
		try {
			sessionStorage.removeItem(RELOAD_STORAGE_KEY);
		} catch (e) {
			// nothing to do — same storage-unavailable case as above
		}
	}

	// The checkout theme replaces the whole payment-method-list HTML
	// (radio, label AND #paypal-button-container all included) whenever the
	// buyer's address changes — a brand new DOM node each time, not the one
	// this script queried when the page first loaded. A click listener
	// bound once to the original radio, or a `rendered` flag closed over
	// the original container, both go stale the moment that swap happens —
	// confirmed live in this project.
	//
	// Fix: never cache the container/radio, and mark "already rendered" on
	// the container ELEMENT itself (a data attribute) rather than in a
	// closure variable, so a genuinely fresh container still gets its own
	// paypal.Buttons() render, while re-running this on an already-rendered
	// container is a safe no-op. The SDK credentials are re-verified (see
	// ensureCorrectSdk above) before every render, not just the first.
	function renderButtons() {
		var container = getContainer();

		if (!container) {
			debugLog('[paypal-smart-buttons] renderButtons: no container in DOM, skipping');
			return;
		}

		if (isActuallyRendered(container)) {
			debugLog('[paypal-smart-buttons] renderButtons: container already has rendered buttons, skipping');
			return;
		}

		if (container.dataset.paypalRendered === '1') {
			// flag says rendered, but the container is empty: the theme
			// cleared this same element in place rather than replacing it.
			// Clear the stale flag so this attempt isn't skipped.
			debugLog('[paypal-smart-buttons] renderButtons: stale rendered flag on an empty container, clearing it and re-rendering');
			delete container.dataset.paypalRendered;
		}

		debugLog('[paypal-smart-buttons] renderButtons: attempting render');

		// Clear any error left over from an earlier, premature resolve
		// attempt (e.g. mid-way through the buyer picking country then
		// region) — this attempt is starting fresh and retries internally,
		// so a stale message shouldn't linger on screen while it does.
		clearError();

		ensureCorrectSdk(function (ok) {
			// re-check: address/DOM may have moved on again while the
			// /paypal-smart-resolve round trip was in flight
			container = getContainer();
			if (!container) {
				debugLog('[paypal-smart-buttons] renderButtons: container gone after resolve, aborting');
				return;
			}
			if (isActuallyRendered(container)) {
				debugLog('[paypal-smart-buttons] renderButtons: container was rendered by another attempt while resolving, aborting');
				return;
			}
			delete container.dataset.paypalRendered;

			debugLog('[paypal-smart-buttons] renderButtons: ensureCorrectSdk resolved', { ok: ok, paypalSdkLoaded: typeof paypal !== 'undefined' });

			if (!ok || typeof paypal === 'undefined') {
				// Last resort: the dynamic in-place SDK swap has failed even
				// after retrying (see attemptResolve). Rather than leave the
				// buyer stuck on an error, fall back to a full page reload —
				// by now the address change is well past the original
				// race-with-session-save concern, since several retry rounds
				// have already elapsed. Rate-limited across reloads (see
				// reloadAllowed()) rather than "once per page load": if the
				// resolve is going to keep failing (e.g. the server's own
				// outbound call to PayPal is down), reloading can't fix that,
				// and without a limit that survives the reload itself this
				// becomes an infinite refresh loop — confirmed as the live
				// cause of exactly that in this project.
				if (reloadAllowed()) {
					window.location.reload();
					return;
				}

				showError('PayPal could not be loaded. Please refresh the page or choose another payment method.');
				return;
			}

			clearReloadAttempts();
			container.dataset.paypalRendered = '1';

			paypal.Buttons({
				style: { layout: 'vertical', color: 'gold', shape: 'rect', label: 'paypal' },

				// The server builds the order from the stored cart total; no amount is ever sent from the browser.
				createOrder: function () {
					clearError();

					var form = getCheckoutForm();

					if (!form) {
						showError('Checkout form not found. Please refresh the page and try again.');
						return Promise.reject(new Error('checkout form missing'));
					}

					// This flow reads the checkout form directly via FormData
					// rather than a native form submit, so the browser's own
					// required-field validation (the shipping_method radios
					// carry `required`) never runs here. With more than one
					// shipping option the buyer still has to click one (no
					// radio is pre-checked), but with exactly ONE option the
					// theme never forces that click — confirmed live in this
					// project as the reason shipping_method came back empty
					// on an order that auto-applied a single shipping tier.
					// Mirror what a native submit would have required: if
					// there's only one option and nothing is checked yet,
					// check it before reading FormData.
					ensureShippingMethodSelected(form);

					var orderBody = new URLSearchParams(new FormData(form));

					return post('/paypal-smart-create', orderBody, false).then(function (res) {
						if (!res || res.error || !res.id) {
							showError((res && res.error) || 'Could not start PayPal payment.', res && res.error_detail);
							throw new Error((res && res.error) || 'create failed');
						}

						return res.id;
					});
				},

				onApprove: function (data, actions) {
					return post('/paypal-smart-capture', JSON.stringify({ paypal_order_id: data.orderID }), true).then(function (res) {
						if (res && res.success) {
							window.location.href = res.redirect_url;
							return;
						}

						if (res && res.restart) {
							return actions.restart(); // let the buyer pick another funding source
						}

						showError((res && res.error) || 'Payment authorization failed.', res && res.error_detail);
					});
				},

				onCancel: function () {
					clearError();
				},

				onError: function (err) {
					console.error('PayPal SDK Error:', err);
					var errorBox = getErrorBox();
					if (errorBox && errorBox.style.display === 'none') {
						showError('A transaction error occurred. Please try again.');
					}
				},
			}).render('#paypal-button-container');
		});
	}

	// If exactly one shipping method is on the page and none of its radios
	// is checked, select it — mirrors what the browser's own `required`
	// constraint would have forced a native form submit to do, which this
	// fetch()-based flow otherwise bypasses entirely. With more than one
	// shipping option, nothing is auto-selected: the buyer still has to
	// make a real choice, same as before.
	function ensureShippingMethodSelected(form) {
		var radios = form.querySelectorAll('input[name=shipping_method]');

		if (!radios.length) {
			return; // no shipping on this cart — nothing to do
		}

		for (var i = 0; i < radios.length; i++) {
			if (radios[i].checked) {
				return; // buyer (or the theme) already picked one
			}
		}

		if (radios.length === 1) {
			radios[0].checked = true;
			debugLog('[paypal-smart-buttons] ensureShippingMethodSelected: only one shipping method and none checked, auto-selecting', radios[0].value);
		} else {
			debugLog('[paypal-smart-buttons] ensureShippingMethodSelected: ' + radios.length + ' shipping methods and none checked — leaving as-is, buyer must choose');
		}
	}

	// The checkout theme's accordion isn't standard Bootstrap collapse (no
	// data-bs-toggle/data-bs-target on the header) — it's the theme's own JS
	// toggling a plain "show" class on the sibling .collapse panel. When it
	// redraws the payment-method list (e.g. after an address change), the
	// radio stays checked but the panel comes back WITHOUT "show" — the
	// buttons are still fully rendered inside, just hidden, which is exactly
	// "arrow shows, no buttons visible" until the header is clicked to
	// re-toggle it. Confirmed live in this project by comparing the DOM
	// before/after: only the panel's class differs, nothing about the
	// selection changed. Since our JS is what's redrawing the buttons
	// inside it, we also restore the panel's own open state to match.
	function ensureAccordionExpanded(radio) {
		var item = radio && radio.closest ? radio.closest('.accordion-item') : null;
		if (!item) return;

		var panel = item.querySelector('.collapse');
		if (panel && !panel.classList.contains('show')) {
			debugLog('[paypal-smart-buttons] ensureAccordionExpanded: panel missing "show" class after redraw, re-adding it');
			panel.classList.add('show');
		}

		var header = item.querySelector('.accordion-button');
		if (header && header.getAttribute('aria-expanded') !== 'true') {
			header.setAttribute('aria-expanded', 'true');
		}
	}

	function maybeRenderIfSelected() {
		var radio = document.querySelector(RADIO_SELECTOR);

		// with a radio present, only render once it's the selected method;
		// with no radio at all, it's the only payment method configured —
		// render immediately, same as before
		if (!radio || radio.checked) {
			if (radio) ensureAccordionExpanded(radio);
			renderButtons();
		} else {
			debugLog('[paypal-smart-buttons] maybeRenderIfSelected: radio present but not checked, skipping');
		}
	}

	// Coalesces bursts of rapid-fire 'change'/mutation events (the theme's
	// multi-step address flow fires several in quick succession) into one
	// render attempt after things settle, instead of one attempt per event.
	function debouncedMaybeRenderIfSelected() {
		if (debounceTimer) clearTimeout(debounceTimer);
		debounceTimer = setTimeout(function () {
			debounceTimer = null;
			maybeRenderIfSelected();
		}, DEBOUNCE_MS);
	}

	// Event delegation, not a listener bound to today's radio node: the
	// radio itself gets replaced along with the rest of the payment-method
	// list on an address change, so a direct listener would go stale the
	// same way the old cached `container`/`paypalBtn` variables did.
	document.addEventListener('change', function (e) {
		if (!e.target || !e.target.matches || !e.target.matches('input[name=payment_method]')) return;

		syncPlaceOrderVisibility();

		if (e.target.matches(RADIO_SELECTOR)) {
			ensureAccordionExpanded(e.target);
			debouncedMaybeRenderIfSelected();
		}
	});

	// Covers every other way the payment-method list can end up showing a
	// fresh, unrendered #paypal-button-container — an address-driven
	// re-fetch that swaps in a new container already marked selected/
	// checked, without necessarily firing a 'change' event on it.
	var observer = new MutationObserver(function () {
		debugLog('[paypal-smart-buttons] MutationObserver fired');
		syncPlaceOrderVisibility();
		debouncedMaybeRenderIfSelected();
	});
	observer.observe(document.body, { childList: true, subtree: true });

	syncPlaceOrderVisibility();
	maybeRenderIfSelected();
})();
