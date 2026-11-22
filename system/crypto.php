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
 * Encrypts/decrypts settings values at rest, using the same mechanism core
 * already uses for the encrypted cart id (Vvveb\System\Cart\Cart::getEncryptedId()):
 * Vvveb\encrypt()/Vvveb\decrypt() keyed on the app's own encryption key
 * (Vvveb\getConfig('app.key')). This is not a separate secret to manage —
 * it reuses the key Vvveb already has configured for the site.
 */
class Crypto {
	private static function key(): string {
		return (string) \Vvveb\getConfig('app.key');
	}

	public static function encrypt(?string $value): string {
		$value = (string) $value;

		if ($value === '') {
			return '';
		}

		$key = self::key();

		//no app key configured: degrade to storing plaintext rather than
		//silently losing the value (also logged so it's not missed).
		if ($key === '') {
			error_log('paypal_smart_buttons: app.key is not configured, storing credentials unencrypted!');

			return $value;
		}

		$encrypted = \Vvveb\encrypt($key, $value);

		return $encrypted !== false && $encrypted !== null ? (string) $encrypted : $value;
	}

	public static function decrypt(?string $value): string {
		$value = (string) $value;

		if ($value === '') {
			return '';
		}

		$key = self::key();

		if ($key === '') {
			return $value;
		}

		$decrypted = \Vvveb\decrypt($key, $value);

		//decrypt() failing (e.g. a value stored before app.key existed, or
		//stored as plaintext by an older version of this plugin) should not
		//silently hand back garbage to be used as a PayPal credential.
		return $decrypted !== false && $decrypted !== null ? (string) $decrypted : '';
	}

	/** Which settings field holds the id/secret for each environment. */
	const ID_FIELDS = [
		'live'    => 'client_id',
		'sandbox' => 'sandbox_client_id',
	];

	const SECRET_FIELDS = [
		'live'    => 'client_secret',
		'sandbox' => 'sandbox_client_secret',
	];

	/**
	 * Picks the live or sandbox credential pair from the plugin's raw
	 * (encrypted-at-rest) settings array, based on $settings['environment'],
	 * and decrypts it. Used everywhere the plugin needs to actually talk to
	 * PayPal or hand the public client id to the browser — never read
	 * client_id/client_secret directly off the settings array.
	 *
	 * @return array{environment: string, client_id: string, client_secret: string}
	 */
	public static function credentials(array $settings): array {
		$environment = ($settings['environment'] ?? 'sandbox') === 'live' ? 'live' : 'sandbox';

		$idField     = self::ID_FIELDS[$environment];
		$secretField = self::SECRET_FIELDS[$environment];

		return [
			'environment'   => $environment,
			'client_id'     => self::decrypt($settings[$idField] ?? ''),
			'client_secret' => self::decrypt($settings[$secretField] ?? ''),
		];
	}
}
