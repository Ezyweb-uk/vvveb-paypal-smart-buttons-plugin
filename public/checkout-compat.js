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

/*
 * Theme compatibility for the checkout page's Content Security Policy.
 *
 * When the plugin sends its checkout CSP (script-src 'self' + nonce + PayPal),
 * the browser blocks every inline <script> and inline onclick="" handler in
 * the theme's checkout page. In the Landing theme (Oct 2026) that stops:
 *   - <script>let regionsUrl = '...';</script>: the Region list no longer
 *     reloads when the country changes (core checkout.js needs regionsUrl);
 *   - onclick="toggleShippingAddress(this)" and the login / register /
 *     password-eye toggles;
 *   - <script>AOS.init({ once: true });</script> (scroll animations).
 *
 * This file is loaded only when that CSP is sent and enforced by this plugin
 * without CSP Manager, or by a CSP Manager older than 0.5 in enforce mode
 * (CSP Manager 0.5+ adds its own copy), and does the same
 * work from an allowed external file. Each part only acts when the inline
 * version didn't: regionsUrl only if it isn't defined, handlers only for
 * elements that still carry an onclick attribute (themes or CSP Manager that
 * already moved them out leave nothing to do), AOS only if nothing was
 * initialised. The speculation rules block is left alone (prefetch only).
 */
(function () {
	'use strict';

	// Runs once per page: CSP Manager (0.5+) ships the same script, and only
	// one copy may act, or every toggle would run twice (open, then closed).
	if (window.vvvebInlineCompat) {
		return;
	}

	window.vvvebInlineCompat = true;

	var script = document.currentScript;

	// 1. Region list URL for core /js/app/checkout.js
	if (typeof regionsUrl === 'undefined') {
		var url = script && script.getAttribute('data-regions-url');

		if (url) {
			window.regionsUrl = url;
		}
	}

	// 2. Inline onclick handlers. Only these theme/core functions, called the
	// way Landing writes them: name(), name(this) or name(this, 'text').
	var ALLOWED = [
		'toggleShippingAddress',
		'toggleBillingAddress',
		'toggleRegister',
		'toggleLoginForm',
		'togglePasswordInput'
	];
	var CALL   = /^\s*([A-Za-z_$][\w$]*)\s*\(([^()]*)\)\s*;?\s*$/;
	var STRING = /^(['"])([^'"\\]*)\1$/;

	function parseHandler(code) {
		var match = CALL.exec(code || '');

		if (!match || ALLOWED.indexOf(match[1]) === -1) {
			return null;
		}

		var args = [];
		var list = match[2].trim();

		if (list !== '') {
			var parts = list.split(',');

			for (var i = 0; i < parts.length; i++) {
				var part = parts[i].trim();
				var str  = STRING.exec(part);

				if (part === 'this' || part === 'event') {
					args.push({ keyword: part });
				} else if (str) {
					args.push({ value: str[2] });
				} else {
					return null; // anything else: leave it alone
				}
			}
		}

		return { name: match[1], args: args };
	}

	// Delegated, so it also covers checkout sections the theme re-renders.
	document.addEventListener('click', function (event) {
		var element = event.target && event.target.closest ? event.target.closest('[onclick]') : null;

		if (!element) {
			return;
		}

		var handler = parseHandler(element.getAttribute('onclick'));
		var fn      = handler && window[handler.name];

		if (typeof fn !== 'function') {
			return;
		}

		fn.apply(element, handler.args.map(function (arg) {
			if (arg.keyword === 'this') return element;
			if (arg.keyword === 'event') return event;
			return arg.value;
		}));
	});

	// 3. Scroll animations
	window.addEventListener('load', function () {
		if (window.AOS && typeof window.AOS.init === 'function' && !document.querySelector('.aos-init')) {
			window.AOS.init({ once: true });
		}
	});
})();
