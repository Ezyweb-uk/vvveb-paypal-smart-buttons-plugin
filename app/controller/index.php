<?php

/**
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
 *
 */

namespace Vvveb\Plugins\PaypalSmartButtons\Controller;

use function Vvveb\__;
use Vvveb\Controller\Base;
use function Vvveb\email;
use function Vvveb\model;
use function Vvveb\prefixArrayKeys;
use function Vvveb\siteSettings;
use function Vvveb\url;
use Vvveb\Plugins\PaypalSmartButtons\System\Crypto;
use Vvveb\Plugins\PaypalSmartButtons\System\PaypalApi;
use Vvveb\Plugins\PaypalSmartButtons\System\SettingsResolver;
use Vvveb\System\CacheManager;
use Vvveb\System\Cart\Cart;
use Vvveb\System\Cart\Order;
use Vvveb\System\Core\Request;
use Vvveb\System\Event;
use Vvveb\System\Locale;
use Vvveb\System\PaymentLedger;
use Vvveb\System\PaymentMethod;
use Vvveb\System\Shipping;
use Vvveb\System\User\User;

if (! defined('V_VERSION')) {
	die('Invalid request!');
}

/**
 * Front-end AJAX endpoints for the PayPal Smart Buttons flow.
 *
 * Modelled directly on the Stripe plugin's app/controller/index.php
 * (client()/return()), adapted for PayPal's create-order/approve/capture
 * flow: because Smart Buttons approval happens in-page (a popup), there's
 * no redirect-back "return" action needed — capture() does what Stripe's
 * return() does, synchronously, in response to onApprove.
 */
class Index extends Base {
	const NAMESPACE_KEY = 'paypal_smart_buttons';
	const SESSION_KEY    = 'paypal_smart_buttons_pending';
	const CURRENCIES     = ['GBP', 'USD', 'EUR'];

	/**
	 * Snapshot the checkout form so capture() can build the order without
	 * the buyer having submitted the main checkout form (approval happens
	 * in a PayPal popup, not a form submit). Only address/contact fields
	 * are kept, never payment credentials.
	 */
	private function savePendingSnapshot(array $data): void {
		$post     = $this->request->post ?? [];
		$checkout = [];

		foreach (['billing_address', 'shipping_address'] as $key) {
			if (isset($post[$key]) && is_array($post[$key])) {
				$checkout[$key] = $post[$key];
			}
		}

		foreach (['first_name', 'last_name', 'email', 'phone_number', 'notes', 'shipping_method', 'payment_method', 'register'] as $key) {
			if (isset($post[$key])) {
				$checkout[$key] = $post[$key];
			}
		}

		$this->session->set(self::SESSION_KEY, [
			'user_id'  => $this->global['user_id'] ?? 0,
			'checkout' => $checkout,
			'created'  => time(),
		] + $data);
	}

	/**
	 * Shipping method plugins (both core's own default "shipping" plugin and
	 * any custom ones, e.g. an order-value/destination-based shipping
	 * plugin) register their methods into Vvveb\System\Shipping lazily, only
	 * when Vvveb\Controller\Base's 'init' event fires on a request whose
	 * route/module looks like the real checkout or cart page — "load only
	 * on cart and checkout page for performance", per the shipping plugin's
	 * own comment. Our /paypal-smart-create and /paypal-smart-capture routes
	 * never match that check, so — confirmed live in this project — NO
	 * shipping methods are registered at all during our request: not just
	 * the one the customer picked, ALL of them, for ANY shipping plugin
	 * using this same lazy-registration pattern. Shipping::getMethods()
	 * then always returns [], and Shipping::setMethod($key) silently does
	 * nothing (core's own setMethod() only acts `if
	 * (isset($this->instances[$method]))` — no match, no exception, no
	 * total, nothing) — which is exactly why setMethod() appeared to
	 * "succeed" while adding £0 shipping.
	 *
	 * Fix: replay that same 'init' event ourselves, with the request's
	 * route/module temporarily spoofed to look like the checkout page, so
	 * every installed shipping plugin's lazy registration runs exactly as
	 * it would on a real checkout page load — before we ever call
	 * Shipping::getMethods()/setMethod(). Mirrors the same
	 * replay-the-event-core-would-have-fired approach already used in
	 * captureAction() for the 'add:after' order event.
	 */
	private function ensureShippingMethodsRegistered(): void {
		static $done = false;

		if ($done) {
			return;
		}

		$done = true;

		try {
			$request = Request::getInstance();
		} catch (\Throwable $e) {
			return;
		}

		$originalGet = $request->get ?? [];
		$request->get = ['route' => '/checkout', 'module' => 'checkout/checkout/index'] + $originalGet;

		try {
			$site = [];

			try {
				$site = siteSettings();
			} catch (\Throwable $e) {
			}

			Event::trigger('Vvveb\Controller\Base', 'init', $site);

			if ($this->debugEnabled()) {
				try {
					$prop = new \ReflectionProperty(Shipping::getInstance(), 'methods');
					$prop->setAccessible(true);
					error_log('paypal_smart_buttons [ensureShippingMethodsRegistered] registered method keys: ' . json_encode(array_keys($prop->getValue(Shipping::getInstance()))));
				} catch (\Throwable $e) {
					error_log('paypal_smart_buttons [ensureShippingMethodsRegistered] could not inspect registered methods: ' . $e->getMessage());
				}
			}
		} catch (\Throwable $e) {
			if ($this->debugEnabled()) {
				error_log('paypal_smart_buttons [ensureShippingMethodsRegistered] Event::trigger threw: ' . $e->getMessage());
			}
		} finally {
			$request->get = $originalGet;
		}
	}

	/**
	 * Apply the snapshot's shipping method to the cart singleton so totals
	 * (and the stored shipping title) include shipping. Same approach as
	 * the Stripe plugin's applyShippingMethod().
	 *
	 * @return array<string, mixed> Selected shipping method data (empty when none).
	 */
	private function applyShippingMethod(Cart $cart, array $form): array {
		if (! $cart->hasShipping()) {
			return [];
		}

		$this->ensureShippingMethodsRegistered();

		$rateInfo = $form;

		if (empty($rateInfo['shipping_address']) && ! empty($rateInfo['billing_address'])) {
			$rateInfo['shipping_address'] = $rateInfo['billing_address'];
		}

		foreach (['billing' => 'billing_address', 'shipping' => 'shipping_address'] as $prefix => $addressKey) {
			$address = $rateInfo[$addressKey] ?? [];

			if (is_array($address)) {
				if (! empty($address['country_id'])) {
					$rateInfo["{$prefix}_country_id"] = $address['country_id'];
				}

				if (! empty($address['region_id'])) {
					$rateInfo["{$prefix}_region_id"] = $address['region_id'];
				}
			}
		}

		if (empty($rateInfo['shipping_country_id']) && empty($rateInfo['billing_country_id'])) {
			try {
				$site = siteSettings();
			} catch (\Throwable $e) {
				$site = [];
			}

			if (! empty($site['country_id'])) {
				$rateInfo['billing_country_id']  = $rateInfo['billing_country_id'] ?? $site['country_id'];
				$rateInfo['shipping_country_id'] = $rateInfo['shipping_country_id'] ?? $site['country_id'];
			}

			if (! empty($site['region_id'])) {
				$rateInfo['billing_region_id']  = $rateInfo['billing_region_id'] ?? $site['region_id'];
				$rateInfo['shipping_region_id'] = $rateInfo['shipping_region_id'] ?? $site['region_id'];
			}
		}

		$shipping  = null;
		$available = [];

		try {
			$shipping  = Shipping::getInstance();
			$available = $shipping->getMethods($rateInfo, $cart);
		} catch (\Throwable $e) {
			if ($this->debugEnabled()) {
				error_log('paypal_smart_buttons [applyShippingMethod] getMethods() threw: ' . $e->getMessage());
			}
		}

		if (! $shipping) {
			try {
				$shipping = Shipping::getInstance();
			} catch (\Throwable $e) {
				return [];
			}
		}

		$postedKey = (string) ($form['shipping_method'] ?? $rateInfo['shipping_method'] ?? '');
		$key       = $postedKey;

		if (! isset($available[$key])) {
			$key = (string) (array_key_first($available) ?? '');
		}

		if ($this->debugEnabled()) {
			error_log('paypal_smart_buttons [applyShippingMethod] resolution: ' . json_encode([
				'post_keys'             => array_keys($form),
				'shipping_address_keys' => is_array($form['shipping_address'] ?? null) ? array_keys($form['shipping_address']) : null,
				'billing_address_keys'  => is_array($form['billing_address'] ?? null) ? array_keys($form['billing_address']) : null,
				'rateInfo_country_ids'  => [
					'billing_country_id'  => $rateInfo['billing_country_id'] ?? null,
					'shipping_country_id' => $rateInfo['shipping_country_id'] ?? null,
					'billing_region_id'   => $rateInfo['billing_region_id'] ?? null,
					'shipping_region_id'  => $rateInfo['shipping_region_id'] ?? null,
				],
				'available_method_keys' => array_keys($available),
				'posted_shipping_method_raw' => $postedKey !== '' ? $postedKey : '(not set)',
				'resolved_key_from_getMethods' => $key,
			]));
		}

		//getMethods() re-derives eligibility from $rateInfo (country/region
		//zone matching), and in practice this has come back empty even for a
		//shipping_method the customer legitimately selected on the checkout
		//page itself (getMethods()'s zone-matching apparently needs more/
		//different context than this snapshot reliably has — never fully
		//root-caused). Since the customer could only have selected a real,
		//currently-valid method key in the first place, trust the POSTED key
		//directly with setMethod() first — that call does its own lookup by
		//key from the canonical shipping-method source, independent of
		//getMethods()'s own (possibly stricter/different) eligibility check —
		//and only fall back to whatever getMethods() found if there was no
		//posted key at all, or the posted key itself turns out to be invalid.
		$tryOrder = [];

		if ($postedKey !== '') {
			$tryOrder[] = $postedKey;
		}

		if ($key !== '' && $key !== $postedKey) {
			$tryOrder[] = $key;
		}

		foreach ($tryOrder as $tryKey) {
			try {
				$shipping->setMethod($tryKey);
			} catch (\Throwable $e) {
				if ($this->debugEnabled()) {
					error_log('paypal_smart_buttons [applyShippingMethod] setMethod(' . $tryKey . ') threw: ' . $e->getMessage());
				}

				continue;
			}

			if ($this->debugEnabled()) {
				error_log('paypal_smart_buttons [applyShippingMethod] setMethod(' . $tryKey . ') succeeded');
			}

			return $available[$tryKey] ?? ['id' => $tryKey];
		}

		return [];
	}

	/**
	 * Resolves whichever configured instance applies to $form's address (see
	 * system/settings-resolver.php — settings are now stored as an array of
	 * instances, same shape as core's payment/shipping plugins, so more than
	 * one PayPal profile can be configured per site), with client_id/
	 * client_secret/environment overwritten by the decrypted, environment-
	 * selected (live vs sandbox) credential pair. Every caller in this file
	 * must go through this, never read client_id/client_secret off a raw
	 * settings row directly — those are encrypted at rest and there are
	 * separate live/sandbox pairs.
	 *
	 * @param array<string, mixed> $form billing_address/shipping_address-shaped array
	 */
	private function settings(array $form): ?array {
		$instance = SettingsResolver::resolveFromForm($form);

		if (! $instance) {
			return null;
		}

		$creds = Crypto::credentials($instance);

		if ($creds['client_id'] === '' || $creds['client_secret'] === '') {
			return null;
		}

		return $creds + $instance;
	}

	/**
	 * Named resolveCurrency() rather than currency() because Base already
	 * declares a protected currency() method — overriding it with a private
	 * one narrows its visibility, which PHP rejects with a fatal error
	 * ("Access level ... must be protected ... or weaker").
	 */
	private function resolveCurrency(array $settings): string {
		$currency = strtoupper((string) ($settings['currency'] ?? Locale::getCurrency()));

		return in_array($currency, self::CURRENCIES, true) ? $currency : 'GBP';
	}

	private function sendJson(array $payload, int $status = 200): void {
		http_response_code($status);
		$this->response->setType('json');
		$this->response->output($payload);
	}

	/**
	 * Logs full exception detail (class, message, file:line, trace) to the
	 * PHP error log. Core only ever logs a generic "plugin triggers fatal
	 * error" line with none of this, so anything thrown inside create()/
	 * capture() needs to be caught and logged here to be diagnosable at all.
	 */
	private function logException(string $action, \Throwable $e): void {
		error_log(sprintf(
			"paypal_smart_buttons [%s]: %s: %s in %s:%d\n%s",
			$action,
			get_class($e),
			$e->getMessage(),
			$e->getFile(),
			$e->getLine(),
			$e->getTraceAsString()
		));
	}

	/**
	 * Whether to include the caught exception's message in the JSON error
	 * response sent to the browser (in addition to always logging the full
	 * detail server-side). Off by default — internals shouldn't normally be
	 * shown to shoppers — turn it on temporarily via the plugin settings
	 * ("Debug mode") while diagnosing an issue, then switch it back off.
	 */
	/**
	 * Debug mode only. Logs what the order/new email template's Order
	 * component depends on, so a "blank order details in the email" report can
	 * be pinned on site/theme resolution, the request ids, or the component
	 * itself.
	 */
	private function debugEmailContext(int $orderId, array $orderData): void {
		try {
			$request = Request::getInstance();
			$theme   = '';

			try {
				$theme = (string) \Vvveb\System\Core\View::getInstance()->getTheme();
			} catch (\Throwable $e) {
			}

			$loaded = 'n/a';

			try {
				$component = new \Vvveb\Component\Order(['order_id' => $orderId, 'customer_order_id' => $orderData['customer_order_id'] ?? null]);
				$results   = $component->results();
				$loaded    = 'order=' . (empty($results['order']) ? 'EMPTY' : 'found')
					. ' products=' . count($results['products'] ?? [])
					. ' shipping_address_1=' . var_export($results['order']['shipping_address_1'] ?? null, true);
			} catch (\Throwable $e) {
				$loaded = 'component threw: ' . $e->getMessage();
			}

			error_log('paypal_smart_buttons [email] ctx: order_id=' . $orderId
				. ' customer_order_id=' . ($orderData['customer_order_id'] ?? '')
				. ' SITE_ID=' . (defined('SITE_ID') ? SITE_ID : 'undef')
				. ' SITE_URL=' . (defined('SITE_URL') ? SITE_URL : 'undef')
				. ' APP=' . (defined('APP') ? APP : 'undef')
				. ' theme=' . $theme
				. ' request[order_id]=' . var_export($request->request['order_id'] ?? null, true)
				. ' get[order_id]=' . var_export($request->get['order_id'] ?? null, true)
				. ' component: ' . $loaded);
		} catch (\Throwable $e) {
			error_log('paypal_smart_buttons [email] ctx logging failed: ' . $e->getMessage());
		}
	}

	private function debugEnabled(): bool {
		foreach (SettingsResolver::all() as $instance) {
			if (! empty($instance['debug'])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Builds a PayPal `payer` object (name/email/address) from the checkout
	 * form's billing_address[...] fields already posted to /paypal-smart-create,
	 * so PayPal's own screen opens pre-filled instead of asking again.
	 *
	 * This never touches PayPal's rendered UI from the browser (that's a
	 * cross-origin frame/overlay PayPal controls — JS can't read or write
	 * into it, by design, same-origin policy). Pre-filling instead happens
	 * server-side, by handing the address to PayPal in the same Orders API
	 * call that creates the order — no iframe access needed or possible.
	 *
	 * @param  array<string, mixed> $post $this->request->post
	 * @return ?array<string, mixed>
	 */
	private function buildPayer(array $post): ?array {
		$address = $post['billing_address'] ?? null;

		if (! is_array($address)) {
			return null;
		}

		$payer = [];

		$givenName = trim((string) ($address['first_name'] ?? ''));
		$surname   = trim((string) ($address['last_name'] ?? ''));

		if ($givenName !== '' || $surname !== '') {
			$payer['name'] = array_filter([
				'given_name' => $givenName !== '' ? $givenName : null,
				'surname'    => $surname !== '' ? $surname : null,
			]);
		}

		$email = trim((string) ($post['email'] ?? ''));

		if ($email !== '') {
			$payer['email_address'] = $email;
		}

		//PayPal requires a valid 2-letter country_code for the address object;
		//without one, omit the address entirely rather than send a partial one
		//PayPal would reject, which would fail the whole create() call
		$countryCode = $this->countryIso((int) ($address['country_id'] ?? 0));

		if ($countryCode) {
			$payerAddress = array_filter([
				'address_line_1' => trim((string) ($address['address_1'] ?? '')) ?: null,
				'address_line_2' => trim((string) ($address['address_2'] ?? '')) ?: null,
				'admin_area_2'   => trim((string) ($address['city'] ?? '')) ?: null,
				'admin_area_1'   => $this->regionName((int) ($address['region_id'] ?? 0)),
				'postal_code'    => trim((string) ($address['post_code'] ?? '')) ?: null,
				'country_code'   => $countryCode,
			]);

			if ($payerAddress) {
				$payer['address'] = $payerAddress;
			}
		}

		return $payer ?: null;
	}

	/**
	 * Vvveb stores addresses by numeric country_id; PayPal needs a 2-letter
	 * ISO country_code. Same lookup the real Stripe plugin uses
	 * (model('country')->get(...)['iso_code_2']) for its own address prefill.
	 */
	private function countryIso(int $countryId): ?string {
		if (! $countryId) {
			return null;
		}

		try {
			$row = model('country')->get(['country_id' => $countryId]);
			$row = $row['country'] ?? $row;

			if (is_array($row) && ! empty($row['iso_code_2'])) {
				return strtoupper((string) $row['iso_code_2']);
			}
		} catch (\Throwable $e) {
			//a failed lookup shouldn't block starting the payment — just
			//falls through to no address prefill
		}

		return null;
	}

	/**
	 * Vvveb stores the county/state as a numeric region_id; PayPal's
	 * admin_area_1 wants free text (a state/province/county name). Tries
	 * model('region') the same way countryIso() tries model('country') —
	 * unlike the country model (confirmed via the real Stripe plugin), the
	 * region model's exact field name for its display name wasn't
	 * confirmed against real code, so this tries a few plausible ones and
	 * fails soft (no admin_area_1) rather than risk an error on a bad key.
	 */
	private function regionName(int $regionId): ?string {
		if (! $regionId) {
			return null;
		}

		try {
			$row = model('region')->get(['region_id' => $regionId]);
			$row = $row['region'] ?? $row;

			if (is_array($row)) {
				foreach (['name', 'region', 'code', 'iso_code'] as $field) {
					if (! empty($row[$field])) {
						return (string) $row[$field];
					}
				}
			}
		} catch (\Throwable $e) {
			//a failed/renamed lookup shouldn't block starting the payment —
			//just falls through to no county/state prefill
		}

		return null;
	}

	/**
	 * POST /paypal-smart-resolve   body: the checkout form (same FormData as
	 * /paypal-smart-create)
	 *
	 * Tells the browser which client-id/currency currently apply to the
	 * address in the checkout form RIGHT NOW — before that address is
	 * necessarily saved to the session/cart. The PayPal JS SDK's <script>
	 * tag is only ever rendered once, server-side, from whichever instance
	 * SettingsResolver resolved at that page load; if the buyer then changes
	 * address client-side (no full page reload), that script tag goes
	 * stale. A full page reload was tried as the fix and confirmed NOT
	 * reliable — the checkout theme's own address-change handling doesn't
	 * necessarily persist the new address to session synchronously with the
	 * DOM update our JS observes, so a reload can still race ahead of it and
	 * come back with the OLD instance. Resolving straight from the posted
	 * form (same SettingsResolver::resolveFromForm() call /paypal-smart-create
	 * already uses for the real order) sidesteps that timing question
	 * entirely: the checkout JS calls this whenever it suspects the address
	 * changed, compares the result to what the currently-loaded SDK script
	 * was initialized with, and only swaps/reloads the SDK when they
	 * actually differ.
	 */
	public function resolve() {
		try {
			$post = $this->request->post ?? [];

			$address = $post['billing_address'] ?? [];

			if (! ($settings = $this->settings($post))) {
				if ($this->debugEnabled()) {
					error_log('paypal_smart_buttons [resolve] not configured for: ' . json_encode([
						'country_id' => $address['country_id'] ?? null,
						'region_id'  => $address['region_id'] ?? null,
					]));
				}

				return $this->sendJson(['configured' => false]);
			}

			if ($this->debugEnabled()) {
				error_log('paypal_smart_buttons [resolve] resolved: ' . json_encode([
					'country_id'  => $address['country_id'] ?? null,
					'region_id'   => $address['region_id'] ?? null,
					'instance_id' => $settings['id'] ?? ($settings['method_id'] ?? null),
					'environment' => $settings['environment'] ?? null,
					'client_id_tail' => substr((string) $settings['client_id'], -6),
					'currency'    => $this->resolveCurrency($settings),
				]));
			}

			return $this->sendJson([
				'configured' => true,
				'client_id'  => $settings['client_id'],
				'currency'   => $this->resolveCurrency($settings),
			]);
		} catch (\Throwable $e) {
			$this->logException('resolve', $e);

			return $this->sendJson(['configured' => false]);
		}
	}

	/**
	 * POST /paypal-smart-create
	 * Creates the PayPal order server-side from the cart total. The browser
	 * never supplies an amount.
	 */
	public function create() {
		try {
			return $this->createAction();
		} catch (\Throwable $e) {
			$this->logException('create', $e);

			return $this->sendJson([
				'error' => __('An unexpected error occurred starting the payment. Please try again.'),
			] + ($this->debugEnabled() ? ['error_detail' => $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()] : []), 500);
		}
	}

	private function createAction() {
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
			return $this->sendJson(['error' => __('Method not allowed.')], 405);
		}

		$post = $this->request->post ?? [];

		if (! ($settings = $this->settings($post))) {
			return $this->sendJson(['error' => __('PayPal is not configured!')], 500);
		}

		$cart = Cart::getInstance();

		if (! $cart->hasProducts()) {
			return $this->sendJson(['error' => __('Cart is empty!')], 400);
		}

		//the cart singleton only includes shipping in its grand total once a
		//shipping method has been applied to it for this request — capture()
		//already did this, create() didn't, which is why the PayPal button
		//was showing an amount that excluded shipping. Same call, same form
		//data, so the two stay consistent with each other.
		$shippingData = $this->applyShippingMethod($cart, $post);

		$currency = $this->resolveCurrency($settings);
		$total    = $cart->getGrandTotal();
		$amount   = number_format((float) $total, 2, '.', '');

		if ($this->debugEnabled()) {
			error_log('paypal_smart_buttons [create] totals: ' . json_encode([
				'hasShipping'        => $cart->hasShipping(),
				'posted_shipping_method' => $post['shipping_method'] ?? null,
				'applied_shipping'   => $shippingData,
				'allTotals'          => $cart->getAllTotals(),
				'grandTotal'         => $total,
				'amount_sent_to_paypal' => $amount,
			]));
		}

		if ((float) $amount <= 0) {
			return $this->sendJson(['error' => __('Invalid order total.')], 400);
		}

		$paypal = new PaypalApi();
		$token  = $paypal->getAccessToken($settings['client_id'], $settings['client_secret'], $settings['environment'] ?? 'sandbox');

		if (! $token) {
			return $this->sendJson(['error' => __('Could not connect to PayPal. Please try again.')], 502);
		}

		//custom_id round-trips through PayPal and is checked again at capture time
		$customId = bin2hex(random_bytes(12));

		//pre-fill PayPal's own screen with the billing details already typed
		//into the checkout form, if any were posted (see buildPayer() docblock
		//for why this is a server-side API field, not a client-side DOM copy)
		$payer = $this->buildPayer($post);

		$res = $paypal->createOrder('cart-' . $customId, $customId, $amount, $currency, $token, $settings['environment'] ?? 'sandbox', $payer);

		if (empty($res['data']['id']) || $res['status'] >= 300) {
			error_log('PayPal create order failed: HTTP ' . $res['status'] . ' ' . json_encode($res['data']));

			return $this->sendJson(['error' => __('PayPal could not start the payment. Please try again.')], 502);
		}

		$this->savePendingSnapshot([
			'paypal_id' => $res['data']['id'],
			'custom_id' => $customId,
			'amount'    => $amount,
			'currency'  => $currency,
		]);

		return $this->sendJson(['id' => $res['data']['id']]);
	}

	/**
	 * POST /paypal-smart-capture   body: {"paypal_order_id": "..."}
	 * Verifies and captures the approved PayPal order, then places the
	 * Vvveb order exactly the way core Checkout::index() would (Smart
	 * Buttons approval happens in-page, so there's no order yet to attach
	 * the payment to).
	 */
	public function capture() {
		try {
			return $this->captureAction();
		} catch (\Throwable $e) {
			$this->logException('capture', $e);

			return $this->sendJson([
				'error' => __('An unexpected error occurred completing the payment. Please try again or contact us.'),
			] + ($this->debugEnabled() ? ['error_detail' => $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()] : []), 500);
		}
	}

	private function captureAction() {
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
			return $this->sendJson(['error' => __('Method not allowed.')], 405);
		}

		$input    = json_decode(file_get_contents('php://input'), true);
		$paypalId = is_array($input) ? (string) ($input['paypal_order_id'] ?? '') : '';

		$pending = $this->session->get(self::SESSION_KEY) ?? [];

		if (! $paypalId || ! $pending || empty($pending['paypal_id']) || ! hash_equals((string) $pending['paypal_id'], $paypalId)) {
			return $this->sendJson(['error' => __('Invalid payment session, please refresh the page and try again.')], 400);
		}

		$user        = User::current();
		$currentUid  = (int) ($user['user_id'] ?? 0);
		$snapshotUid = (int) ($pending['user_id'] ?? 0);

		if ($snapshotUid !== $currentUid) {
			return $this->sendJson(['error' => __('Session expired, please refresh the page and try again.')], 400);
		}

		//resolve against the SAME snapshot address create() used, so capture()
		//never ends up billing a different instance's credentials than the
		//one whose order/token was created
		if (! ($settings = $this->settings($pending['checkout'] ?? []))) {
			return $this->sendJson(['error' => __('PayPal is not configured!')], 500);
		}

		$cart = Cart::getInstance();

		if (! $cart->hasProducts()) {
			return $this->sendJson(['error' => __('Cart is empty!')], 400);
		}

		$form         = $pending['checkout'] ?? [];
		$shippingData = $this->applyShippingMethod($cart, $form);

		//cart may have changed (shipping method, coupon, stock) since create():
		//refuse to capture a payment that no longer matches what will be charged
		$currency       = $this->resolveCurrency($settings);
		$expectedAmount = number_format((float) $cart->getGrandTotal(), 2, '.', '');

		if ($expectedAmount !== (string) $pending['amount'] || $currency !== (string) $pending['currency']) {
			$this->session->delete(self::SESSION_KEY);

			return $this->sendJson(['error' => __('Your order total has changed. Please refresh the page and try again.')], 409);
		}

		$paypal = new PaypalApi();
		$token  = $paypal->getAccessToken($settings['client_id'], $settings['client_secret'], $settings['environment'] ?? 'sandbox');

		if (! $token) {
			return $this->sendJson(['error' => __('Could not connect to PayPal. Please try again.')], 502);
		}

		$res  = $paypal->captureOrder($paypalId, $token, $settings['environment'] ?? 'sandbox');
		$data = $res['data'];

		if ($res['status'] === 422) {
			$issue = $data['details'][0]['issue'] ?? '';

			if ($issue === 'INSTRUMENT_DECLINED') {
				//buyer can pick another funding source: JS calls actions.restart()
				return $this->sendJson(['restart' => true, 'error' => __('Payment method declined. Please choose another.')]);
			}
		}

		if ($res['status'] >= 300 || ($data['status'] ?? '') !== 'COMPLETED') {
			error_log('PayPal capture failed: HTTP ' . $res['status'] . ' ' . json_encode($data));

			return $this->sendJson(['error' => __('Payment could not be completed. You have not been charged.')], 402);
		}

		$unit    = $data['purchase_units'][0] ?? [];
		$captureData = $unit['payments']['captures'][0] ?? [];

		$capAmount   = $captureData['amount']['value'] ?? '';
		$capCurrency = strtoupper($captureData['amount']['currency_code'] ?? '');
		$capCustom   = (string) ($captureData['custom_id'] ?? ($unit['custom_id'] ?? ''));

		$verified = ! empty($captureData['id'])
			&& $capCurrency === $currency
			&& $capAmount === $expectedAmount
			&& $capCustom === (string) $pending['custom_id'];

		if (! $verified) {
			//money moved but doesn't match what we expected: never place the
			//order silently, log for manual reconciliation
			error_log('PayPal capture verification FAILED: PayPal order ' . $paypalId . ': ' . json_encode($data));

			return $this->sendJson(['error' => sprintf(__('We could not verify your payment. Please contact us quoting reference %s.'), $paypalId)], 409);
		}

		if (($captureData['status'] ?? '') !== 'COMPLETED') {
			//e.g. PENDING under PayPal review: funds not yet available, don't place the order
			return $this->sendJson(['error' => sprintf(__('Your payment is pending review. Please contact us quoting reference %s.'), $paypalId)], 402);
		}

		//guest asked for an account: create it with an unknown password and
		//mail a reset link (mirrors the Stripe plugin's registerGuest())
		if (! $snapshotUid && ($form['register'] ?? '') === 'true' && ! empty($form['email'])) {
			$created = $this->registerGuest($form);

			if ($created) {
				$snapshotUid = $created;
				$user        = User::current();
			}
		}

		$paymentData = ['gateway' => 'paypal_smart_buttons', 'title' => 'PayPal', 'capture_id' => $captureData['id'], 'currency' => $capCurrency] + $data;

		$checkoutInfo = [
			'products'          => $cart->getAll(),
			'product_options'   => $cart->getProductOptions(),
			'totals'            => $cart->getAllTotals(),
			'total'             => $cart->getGrandTotal(),
			'currency'          => $currency,
			'currency_id'       => $this->global['currency_id'] ?? 0,
			'currency_value'    => $this->global['currency_value'] ?? 1,
			'language_id'       => $this->global['language_id'] ?? 1,
			'user_id'           => $snapshotUid,
			'user'              => $user,
			'payment_method'    => 'paypal_smart_buttons',
			'payment_data'      => json_encode($paymentData),
			'shipping_method'   => (string) ($shippingData['name'] ?? $form['shipping_method'] ?? ''),
			'shipping_data'     => json_encode($shippingData),
			'payment_status_id' => (int) ($settings['success_payment_status_id'] ?? 4),
			'order_status_id'   => 1,
			'site_id'           => SITE_ID ?? 0,
			'notes'             => $form['notes'] ?? '',
			'first_name'        => $form['first_name'] ?? ($user['first_name'] ?? ''),
			'last_name'         => $form['last_name'] ?? ($user['last_name'] ?? ''),
			'email'             => $form['email'] ?? ($user['email'] ?? ''),
			'phone_number'      => $form['phone_number'] ?? ($user['phone_number'] ?? ''),
		] + $this->global;

		foreach (['billing_address', 'shipping_address'] as $addressKey) {
			if (! empty($form[$addressKey]) && is_array($form[$addressKey])) {
				$checkoutInfo[$addressKey] = $form[$addressKey];
			}
		}

		$shippingAddress = $checkoutInfo['shipping_address'] ?? $checkoutInfo['billing_address'] ?? [];
		$billingAddress  = $checkoutInfo['billing_address'] ?? [];
		$checkoutInfo   += prefixArrayKeys('shipping_', $shippingAddress);
		$checkoutInfo   += prefixArrayKeys('billing_', $billingAddress);

		if (! $checkoutInfo['user_id']) {
			unset($checkoutInfo['user_id']);
		}

		//core's own Checkout::index() replays this event right before creating
		//the order (plugins can adjust $checkoutInfo, e.g. gift cards, coupons
		//locking, etc.). Our own bypass-checkout flow never fired it, so do
		//the same here for parity — take index [0] of the returned array, same
		//as core does with `list($checkoutInfo) = Event::trigger(...)`.
		try {
			$eventResult = Event::trigger('Vvveb\Controller\Checkout\Checkout', 'add', $checkoutInfo);

			if (is_array($eventResult) && array_key_exists(0, $eventResult) && is_array($eventResult[0])) {
				$checkoutInfo = $eventResult[0];
			}
		} catch (\Throwable $e) {
			if ($this->debugEnabled()) {
				error_log('paypal_smart_buttons [capture] add event threw: ' . $e->getMessage());
			}
		}

		$order     = Order::getInstance();
		$orderData = $order->add($checkoutInfo);
		$orderId   = (int) ($orderData['order_id'] ?? 0);

		if (! $orderId) {
			//payment already captured: never silently drop it, this needs a human
			error_log('PayPal captured (id ' . $captureData['id'] . ') but order creation FAILED. Checkout snapshot: ' . json_encode($form));

			return $this->sendJson(['error' => sprintf(__('Payment received but we could not create your order automatically. Please contact us quoting reference %s.'), $captureData['id'])], 500);
		}

		$checkoutInfo['order_id'] = $orderId;

		//snapshot BEFORE the shipping call, same point core's own checkout.php
		//snapshots them, so the post-'add:after' comparison below only fires
		//an extra Order::edit() when something actually changed them.
		$shippingDataBefore = $checkoutInfo['shipping_data'] ?? null;
		$paymentDataBefore  = $checkoutInfo['payment_data'] ?? null;

		//core's checkout.php calls Shipping::getInstance()->ship($checkoutInfo)
		//after the order is created, before 'add:after'. Our own
		//applyShippingMethod() above already replayed the lazy shipping-method
		//registration (ensureShippingMethodsRegistered()) earlier in this same
		//request, so the registered method ship() needs will be there. The
		//payment has already been captured by this point, so a ship() failure
		//is logged for manual handling rather than aborting/refunding.
		if ($cart->hasShipping()) {
			try {
				$shipping   = Shipping::getInstance();
				$shippingOk = $shipping->ship($checkoutInfo);

				if ($shippingOk !== true) {
					error_log('paypal_smart_buttons [capture] Shipping::ship() failed for order ' . $orderId . ': ' . ($shipping->getError() ?? 'unknown error'));
				}
			} catch (\Throwable $e) {
				error_log('paypal_smart_buttons [capture] Shipping::ship() threw for order ' . $orderId . ': ' . $e->getMessage());
			}
		}

		//same post-order contract as the checkout flow (inventory, gift cards,
		//accounting…); this request context, never throw
		try {
			$siteSettings = siteSettings();
		} catch (\Throwable $e) {
			$siteSettings = [];
		}

		try {
			$eventResult = Event::trigger('Vvveb\Controller\Checkout\Checkout', 'add:after', $checkoutInfo, $orderId, $siteSettings);

			if (is_array($eventResult) && array_key_exists(0, $eventResult) && is_array($eventResult[0])) {
				$checkoutInfo = $eventResult[0];
			}
		} catch (\Throwable $e) {
			if ($this->debugEnabled()) {
				error_log('paypal_smart_buttons [capture] add:after event threw: ' . $e->getMessage());
			}
		}

		//mirrors core's own checkout.php: persist shipping_data/payment_data
		//if 'add:after' (or Shipping::ship()) changed them, since the order
		//record was already written before either of those ran.
		if (($checkoutInfo['shipping_data'] ?? null) !== $shippingDataBefore || ($checkoutInfo['payment_data'] ?? null) !== $paymentDataBefore) {
			try {
				Order::getInstance()->edit([
					'shipping_data' => $checkoutInfo['shipping_data'] ?? '',
					'payment_data'  => $checkoutInfo['payment_data'] ?? '',
				], $orderId);
			} catch (\Throwable $e) {
				error_log('paypal_smart_buttons [capture] Order::edit() of shipping_data/payment_data failed for order ' . $orderId . ': ' . $e->getMessage());
			}
		}

		//replay the same notifications core's own checkout.php fires after
		//'add:after' — guarded, since this plugin bypasses core's checkout
		//flow entirely and these aren't guaranteed present on every core.
		if (class_exists('Vvveb\System\Notifications') && method_exists('Vvveb\System\Notifications', 'trigger')) {
			try {
				\Vvveb\System\Notifications::trigger('order.new', $checkoutInfo + ['order_id' => $orderId]);
			} catch (\Throwable $e) {
			}
		}

		if (class_exists('Vvveb\System\Notifications') && method_exists('Vvveb\System\Notifications', 'checkLowStock')) {
			foreach ($checkoutInfo['products'] ?? [] as $product) {
				try {
					if (! empty($product['product_id'])) {
						\Vvveb\System\Notifications::checkLowStock($product);
					}
				} catch (\Throwable $e) {
				}
			}
		}

		$this->session->delete(self::SESSION_KEY);
		$this->session->set('order', $orderData + ['order_id' => $orderId]);

		PaymentLedger::record([
			'order_id'  => $orderId,
			'kind'      => 'purchase',
			'gateway'   => 'paypal_smart_buttons',
			'reference' => $captureData['id'],
			'amount'    => $orderData['total'] ?? 0,
			'currency'  => $orderData['currency'] ?? '',
			'status'    => 'succeeded',
			'note'      => '',
		]);

		$cart->empty();
		CacheManager::clearObjectCache('component', 'notifications');

		$this->request->request['order_id']          = $orderId;
		$this->request->request['customer_order_id'] = $orderData['customer_order_id'] ?? '';

		try {
			$title = sprintf(__('Order confirmation #%s'), $orderData['customer_order_id'] ?? $orderId);
			$site  = siteSettings();

			$customerEmail = trim((string) ($orderData['email'] ?? ''));
			$adminEmail    = trim((string) ($site['admin-email'] ?? ''));

			//\Vvveb\email() calls Email::setTo()+send() exactly once per call, so
			//passing both addresses in one $to array (the old behaviour) sends a
			//SINGLE message with both recipients on it — confirmed by reading
			//system/functions/site.php's email() and system/email.php's send().
			//Call it once per recipient instead, so the customer and the admin
			//each get their own separate email, and skip the admin send entirely
			//if the two addresses happen to be the same (no duplicate to one inbox).
			$emailData = $orderData + ['order_id' => $orderId, 'title' => $title];

			//Debug mode only: what the order/new email template's Order
			//component will see on THIS request (site, theme, request ids, and
			//whether the component can actually load the order).
			if ($this->debugEnabled()) {
				$this->debugEmailContext($orderId, $orderData);
			}

			if ($customerEmail) {
				$sent = email($customerEmail, $title, 'order/new', $emailData);

				if ($this->debugEnabled()) {
					error_log('paypal_smart_buttons [email] customer send returned ' . var_export($sent, true));
				}
			}

			if ($adminEmail && $adminEmail !== $customerEmail) {
				$sent = email($adminEmail, $title, 'order/new', $emailData);

				if ($this->debugEnabled()) {
					error_log('paypal_smart_buttons [email] admin send returned ' . var_export($sent, true));
				}
			}
		} catch (\Throwable $e) {
			//was silently swallowed; a failed confirmation email must never
			//break the order, but it should be visible in the log
			error_log('paypal_smart_buttons [email] confirmation email failed for order ' . $orderId . ': ' . $e->getMessage());
		}

		return $this->sendJson(['success' => true, 'redirect_url' => url('checkout/confirm/index')]);
	}

	/**
	 * Register a guest checkout as a new user with an unknown password, then
	 * mail a password reset link. Mirrors the Stripe plugin's registerGuest().
	 * Never fails the order — the money has already moved.
	 */
	private function registerGuest(array $form): ?int {
		try {
			$userInfo = [];

			foreach (['first_name', 'last_name', 'email', 'phone_number'] as $field) {
				$userInfo[$field] = $form[$field] ?? null;
			}

			if (empty($userInfo['email'])) {
				return null;
			}

			$userInfo['display_name'] = trim(($userInfo['first_name'] ?? '') . ' ' . ($userInfo['last_name'] ?? ''));
			$userInfo['username']     = strtolower(str_replace(' ', '', ($userInfo['first_name'] ?? 'user') . ($userInfo['last_name'] ?? ''))) . '-' . random_int(1000, 999999);
			$userInfo['password']     = bin2hex(random_bytes(24));

			$result = User::add($userInfo);

			if (! $result || ! isset($result['user'])) {
				return null;
			}

			$userId   = (int) $result['user'];
			$userInfo = User::get(['user_id' => $userId]);

			if (! $userInfo) {
				return null;
			}

			\Vvveb\session(['user' => $userInfo]);

			$token = bin2hex(random_bytes(16));
			User::update(['token' => $token], ['email' => $userInfo['email']]);

			$site      = \Vvveb\System\Sites::getSiteData(SITE_ID);
			$reset_url = url('user/reset/reset', [
				'token'  => $token,
				'user'   => $userInfo['username'],
				'host'   => $site['host'] ?? false,
				'scheme' => $_SERVER['REQUEST_SCHEME'] ?? 'http',
			]);

			try {
				email($userInfo['email'], __('Set your account password'), 'user/reset', $userInfo + [
					'token'     => $token,
					'reset_url' => $reset_url,
				]);
			} catch (\Throwable $e) {
			}

			return $userId;
		} catch (\Throwable $e) {
			return null;
		}
	}
}
