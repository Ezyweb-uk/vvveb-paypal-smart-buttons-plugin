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

namespace Vvveb\Plugins\PaypalSmartButtons;

use Vvveb\Plugins\PaypalSmartButtons\System\Crypto;
use Vvveb\Plugins\PaypalSmartButtons\System\Csp;
use Vvveb\Plugins\PaypalSmartButtons\System\PaypalApi;
use Vvveb\Plugins\PaypalSmartButtons\System\SettingsResolver;
use Vvveb\System\Locale;
use Vvveb\System\PaymentMethod;

if (! defined('V_VERSION')) {
	die('Invalid request!');
}

/**
 * Registered payment method. Only responsible for:
 *  - deciding whether to show the button (client id configured)
 *  - exposing config to the checkout page template (public/paypal-smart-buttons.html
 *    reads it back out via `{$this->checkout.paypal_smart_buttons.*}`)
 *  - refunds from the admin order screen
 *
 * The actual order-creation/capture flow does NOT go through purchase()/capture()
 * here — like the Stripe plugin, PayPal Smart Buttons is a JS-SDK-driven flow that
 * can't fit into a single synchronous checkout form POST, so it's handled entirely
 * by app/controller/index.php (create + capture actions), which places the Vvveb
 * order itself once the payment is verified. purchase() below only covers the
 * (unsupported) no-JS fallback.
 *
 * authorize/capture/completeAuthorize/completePurchase/void are deliberately
 * NOT overridden: core's PaymentMethod::supports($operation) is reflection-based
 * (true only when the concrete class itself declares the method), and drives
 * which buttons the admin order screen shows. Overriding one of these just to
 * fail() would make supports() wrongly report it as available. The inherited
 * default (return false) already covers "unsupported" correctly.
 */
class Payment extends PaymentMethod {
	private $namespace = 'paypal_smart_buttons';

	public function init(): void {
	}

	public function setMethod(): void {
	}

	/**
	 * Resolves whichever configured instance applies to this checkout (see
	 * system/settings-resolver.php) and merges in its decrypted, environment-
	 * selected credentials. Every caller here must go through this rather
	 * than reading client_id/client_secret off a settings array directly.
	 *
	 * @return array{0: array<string, mixed>, 1: PaypalApi}|null
	 */
	private function api(array $checkoutInfo = []): ?array {
		//order records (this is called from refund(), not checkout) carry
		//flat *_country_id/*_region_id keys, not nested billing_address[...]
		//arrays — same shape getMethod() below already reads.
		$countryId = $checkoutInfo['shipping_country_id'] ?? ($checkoutInfo['billing_country_id'] ?? null);
		$regionId  = $checkoutInfo['shipping_region_id'] ?? ($checkoutInfo['billing_region_id'] ?? null);

		$instance = SettingsResolver::resolve(
			SettingsResolver::all(),
			$countryId !== null ? (int) $countryId : null,
			$regionId !== null ? (int) $regionId : null
		);

		if (! $instance) {
			return null;
		}

		$creds = Crypto::credentials($instance);

		if ($creds['client_id'] === '' || $creds['client_secret'] === '') {
			return null;
		}

		return [$creds + $instance, new PaypalApi()];
	}

	public function getMethod(array &$checkoutInfo = [], array $options = []): array|false {
		//current core creates a pending subscription row itself for a
		//subscription cart, and expects the payment method to actually be able
		//to bill it (createSubscription/charge on a schedule). PayPal Smart
		//Buttons is a one-off checkout capture only — it can't do that, so
		//hide the method rather than let a subscription order get "paid" once
		//and never billed again.
		if (method_exists($this->cart, 'getSubscription') && $this->cart->getSubscription()) {
			error_log('paypal_smart_buttons: hidden — cart contains a subscription product; PayPal Smart Buttons cannot bill subscriptions');

			return false;
		}

		$instances = SettingsResolver::all();

		$countryId = $checkoutInfo['shipping_country_id'] ?? ($checkoutInfo['billing_country_id'] ?? null);
		$regionId  = $checkoutInfo['shipping_region_id'] ?? ($checkoutInfo['billing_region_id'] ?? null);

		$settings = SettingsResolver::resolve($instances, $countryId !== null ? (int) $countryId : null, $regionId !== null ? (int) $regionId : null);

		if (! $settings) {
			//nothing saved yet (fresh install, or Save was never clicked), or
			//no configured instance's region matches this checkout: hide the
			//method rather than show a broken button. Logged because a
			//silently-hidden method is otherwise indistinguishable from a bug.
			error_log('paypal_smart_buttons: hidden — no configured PayPal instance applies to this checkout');

			return false;
		}

		$clientId = trim(Crypto::credentials($settings)['client_id']);

		if ($clientId === '') {
			error_log('paypal_smart_buttons: hidden — client_id is not configured (save the plugin settings first)');

			return false;
		}

		//client id is URL-safe; anything else means bad/tampered config
		if (! preg_match('/^[A-Za-z0-9_\-]+$/', $clientId)) {			

			return false;
		}

		$region_group_id = (int) ($settings['region_group_id'] ?? 0);

		$methodData = [
			'name'            => $this->namespace,
			'title'           => trim((string) ($settings['name'] ?? '')) ?: 'PayPal / Debit or Credit Card',
			'description'     => (string) ($settings['message'] ?? ''),
			'cost'            => 0,
			'tax'             => 1,
			'region_id'       => 0,
			'region_group_id' => $region_group_id,
			'sort_order'      => (int) ($settings['sort_order'] ?? 0),
		];

		$template = 'plugins/paypal-smart-buttons/paypal-smart-buttons-form.html';

		if (APP == 'app') {
			$methodData['render'] = file_get_contents(DIR_PUBLIC . $template);
		}

		$currency = strtoupper((string) ($settings['currency'] ?? Locale::getCurrency()));

		if (! in_array($currency, ['GBP', 'USD', 'EUR'], true)) {
			$currency = 'GBP';
		}

		$checkoutInfo['paypal_smart_buttons'] = [
			'client_id' => $clientId,
			'currency'  => $currency,
			//same request-cached nonce plugin.php's CSP header uses
			//(Csp::nonce() memoizes in a static property) -- the inline
			//config script below carries this as its own nonce="..."
			//attribute so it matches the header's 'nonce-{$nonce}' source.
			'csp_nonce' => Csp::nonce(),
			//exposes the instance's own "Debug mode" setting to the checkout
			//page JS, so its diagnostic console.debug() calls stay silent for
			//real shoppers by default and only appear when the same toggle
			//that already gates the server-side error_log() calls is on.
			//Rendered as a bare JS literal (true/false), not a PHP bool, so
			//it works directly in the template's raw {$this->checkout...}
			//interpolation.
			'debug'     => ! empty($settings['debug']) ? 'true' : 'false',
			//SRI hash of the externalized checkout script, recomputed whenever the
			//file's mtime changes so it never goes stale after an edit/deploy —
			//cached in-process (static var) so it's not re-hashed on every request.			
			'script_integrity' => self::scriptIntegrity(),
		];

		return $methodData;
	}

	/**
	 * No-JS fallback: the real flow is handled entirely by
	 * app/controller/index.php (create + capture), which creates the order
	 * itself once payment is verified, so this path always fails closed.
	 */
	public function purchase(array &$checkoutInfo = []): bool {
		$this->recordLedger('purchase', $checkoutInfo, 'failed', '', 'handled by app/controller/index.php create+capture', 'paypal_smart_buttons');

		return $this->fail('PayPal payment requires JavaScript! Please enable JavaScript and try again.');
	}

	public function refund(array &$checkoutInfo = []): bool {
		//resolves against the order's own billing/shipping address, same as
		//at checkout time — in the common single-instance case this is just
		//"the" configured instance; with more than one, refunds assume the
		//order's address still resolves to the same instance it was charged
		//under (true unless region settings were edited after the sale).
		if (! ($api = $this->api($checkoutInfo))) {
			return $this->fail('PayPal client ID/secret is not configured!');
		}

		[$creds, $paypal] = $api;

		$paymentData = json_decode($checkoutInfo['payment_data'] ?? '', true) ?: [];
		$captureId   = $paymentData['capture_id'] ?? '';

		if (! $captureId) {
			$this->recordLedger('refund', $checkoutInfo, 'failed', '', 'no capture id on order', 'paypal_smart_buttons');

			return $this->fail('PayPal refund failed: no capture reference found on this order!');
		}

		$token = $paypal->getAccessToken($creds['client_id'], $creds['client_secret'], $creds['environment']);

		if (! $token) {
			return $this->fail('Could not connect to PayPal.');
		}

		$partial = $this->partialAmountSafe($checkoutInfo);
		$currency = strtoupper($checkoutInfo['currency'] ?? ($paymentData['currency'] ?? 'GBP'));

		$res = $paypal->refundCapture(
			$captureId,
			$partial !== null ? number_format($partial, 2, '.', '') : null,
			$currency,
			$token,
			$creds['environment']
		);

		if ($res['status'] >= 300 || ($res['data']['status'] ?? '') !== 'COMPLETED') {
			$this->recordLedger('refund', $checkoutInfo, 'failed', $captureId, '', 'paypal_smart_buttons');

			return $this->fail('PayPal refund failed: ' . ($res['data']['message'] ?? 'unknown error'));
		}

		$paymentData['refunds'][] = $res['data'];
		$checkoutInfo['payment_data'] = json_encode($paymentData);
		//core's own PaymentMethod::storePaymentData() persists payment_data +
		//payment_status_id to the order via Order::getInstance()->edit(), the
		//same way core's own checkout flow does — no need for our own
		//model('order')->edit() shape here any more.
		$this->storePaymentData($checkoutInfo, $paymentData, 8); // 8 = refunded, matches the Stripe plugin's convention

		$this->recordLedger('refund', $checkoutInfo, 'succeeded', (string) ($res['data']['id'] ?? $captureId), '', 'paypal_smart_buttons');

		return true;
	}

	/**
	 * Guarded wrapper around core's PaymentMethod::partialAmount(), confirmed
	 * present in current core (reads the `amount` key, returns null for a
	 * full refund). Kept guarded rather than called directly in case this
	 * plugin is ever installed on an older core that predates it.
	 */
	private function partialAmountSafe(array $checkoutInfo): ?float {
		if (method_exists($this, 'partialAmount')) {
			return $this->partialAmount($checkoutInfo);
		}

		return $checkoutInfo['amount'] ?? null;
	}
	
	private static function scriptIntegrity(): string {
		static $cached = null;
		static $cachedMtime = null;

		$path = DIR_PUBLIC . 'plugins/paypal-smart-buttons/paypal-smart-buttons.js';

		if (! is_file($path)) {
			return '';
		}

		$mtime = filemtime($path);

		if ($cached === null || $cachedMtime !== $mtime) {
			$cached      = base64_encode(hash_file('sha384', $path, true));
			$cachedMtime = $mtime;
		}

		return $cached;
	}	
}
