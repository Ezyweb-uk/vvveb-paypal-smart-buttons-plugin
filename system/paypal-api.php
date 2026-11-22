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

namespace Vvveb\Plugins\PaypalSmartButtons\System;

if (! defined('V_VERSION')) {
	die('Invalid request!');
}

/**
 * Thin wrapper around the PayPal REST API v2 (Orders + Payments).
 * Mirrors the shape of the Stripe plugin's system/stripe.php helper class.
 */
class PaypalApi {
	private function baseUrl(string $environment): string {
		return $environment === 'live'
			? 'https://api-m.paypal.com'
			: 'https://api-m.sandbox.paypal.com';
	}

	/**
	 * OAuth2 client-credentials token.
	 */
	public function getAccessToken(string $clientId, string $clientSecret, string $environment): ?string {
		$ch = curl_init($this->baseUrl($environment) . '/v1/oauth2/token');

		curl_setopt_array($ch, [
			CURLOPT_POST           => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => 20,
			CURLOPT_USERPWD        => $clientId . ':' . $clientSecret,
			CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
			CURLOPT_HTTPHEADER     => ['Accept: application/json'],
		]);

		$raw = curl_exec($ch);
		curl_close($ch);

		$data = json_decode((string) $raw, true);

		return $data['access_token'] ?? null;
	}

	/**
	 * Create a PayPal order (intent=CAPTURE) for the given amount.
	 *
	 * @param  ?array $payer Optional PayPal `payer` object (name/email_address/
	 *                       address) to pre-fill PayPal's own checkout screen
	 *                       with the shopper's already-entered billing details.
	 *                       Never trusted for the charge amount — that always
	 *                       comes from the server-computed cart total above.
	 * @return array{status:int,data:array}
	 */
	public function createOrder(string $referenceId, string $customId, string $amount, string $currency, string $token, string $environment, ?array $payer = null): array {
		$body = [
			'intent'         => 'CAPTURE',
			'purchase_units' => [[
				'reference_id' => $referenceId,
				'custom_id'    => $customId,
				'amount'       => [
					'currency_code' => $currency,
					'value'         => $amount,
				],
			]],
		];

		if ($payer) {
			$body['payer'] = $payer;
		}

		return $this->request('POST', '/v2/checkout/orders', $token, $environment, $body);
	}

	/**
	 * Capture an approved PayPal order. PayPal-Request-Id makes a retried
	 * capture safe (no double charge on the same order id).
	 *
	 * @return array{status:int,data:array}
	 */
	public function captureOrder(string $paypalOrderId, string $token, string $environment): array {
		return $this->request(
			'POST',
			'/v2/checkout/orders/' . rawurlencode($paypalOrderId) . '/capture',
			$token,
			$environment,
			new \stdClass(),
			['PayPal-Request-Id: capture-' . $paypalOrderId]
		);
	}

	/**
	 * Refund a capture (full or partial).
	 *
	 * @return array{status:int,data:array}
	 */
	public function refundCapture(string $captureId, ?string $amount, string $currency, string $token, string $environment): array {
		$body = new \stdClass();

		if ($amount !== null) {
			$body = [
				'amount' => [
					'value'         => $amount,
					'currency_code' => $currency,
				],
			];
		}

		return $this->request(
			'POST',
			'/v2/payments/captures/' . rawurlencode($captureId) . '/refund',
			$token,
			$environment,
			$body,
			['PayPal-Request-Id: refund-' . $captureId . '-' . ($amount ?? 'full')]
		);
	}

	/**
	 * @return array{status:int,data:array}
	 */
	private function request(string $method, string $path, string $token, string $environment, $body = null, array $extraHeaders = []): array {
		$ch = curl_init($this->baseUrl($environment) . $path);

		$headers = array_merge([
			'Content-Type: application/json',
			'Accept: application/json',
			'Authorization: Bearer ' . $token,
		], $extraHeaders);

		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_HTTPHEADER     => $headers,
		]);

		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
		}

		$raw    = curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$data = json_decode((string) $raw, true);

		return ['status' => $status, 'data' => is_array($data) ? $data : []];
	}
}
