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

use function Vvveb\getSetting;
use Vvveb\Sql\Region_groupSQL;

if (! defined('V_VERSION')) {
	die('Invalid request!');
}

/**
 * Settings are now stored the same way core's own `payment`/`shipping`
 * plugins store theirs: as a JSON-array-per-namespace, one row per numeric
 * key (0, 1, 2...) under the 'paypal_smart_buttons' namespace, each row a
 * whole "instance" (its own environment, live/sandbox credentials, currency,
 * region restriction, etc.) — rather than one flat row per individual field.
 * This lets a site configure more than one PayPal profile (e.g. a different
 * currency/environment/region) the same way the built-in plugins let you
 * configure multiple shipping/payment methods.
 *
 * At most one instance is ever *active* for a given checkout though — same
 * rule the core plugins use: the first instance whose region_group_id
 * matches the buyer's address wins, falling back to the first catch-all
 * (region_group_id 0) instance. Multiple instances are for regional
 * variants, not for showing several PayPal buttons at once.
 */
class SettingsResolver {
	const NAMESPACE_KEY = 'paypal_smart_buttons';

	/**
	 * All configured instances, in saved order, still encrypted at rest.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(?int $siteId = null): array {
		$rows = $siteId !== null
			? getSetting(self::NAMESPACE_KEY, null, [], $siteId)
			: getSetting(self::NAMESPACE_KEY, null, []);

		if (! is_array($rows)) {
			return [];
		}

		// every row should be an array (one whole instance) — but a site
		// upgrading from this plugin's original single-flat-row storage can
		// still have old rows keyed by field name with plain scalar values
		// sitting under this same namespace until the admin settings page
		// has been opened once (admin/controller/settings.php migrates them
		// there, since that's the only place with the site_id/permissions to
		// safely rewrite them). Until then, just ignore them here rather
		// than crash the checkout page — the method stays hidden instead.
		return array_values(array_filter($rows, 'is_array'));
	}

	/**
	 * Picks the instance that applies to a checkout at the given
	 * country/region, or null if none are configured (or none match and none
	 * are catch-all).
	 *
	 * @param array<int, array<string, mixed>> $instances
	 */
	public static function resolve(array $instances, ?int $countryId, ?int $regionId): ?array {
		$fallback = null;

		foreach ($instances as $instance) {
			$regionGroupId = (int) ($instance['region_group_id'] ?? 0);

			if ($regionGroupId === 0) {
				$fallback = $fallback ?? $instance;

				continue;
			}

			try {
				$region  = new Region_groupSQL();
				$matches = $region->isRegion([
					'region_group_id' => $regionGroupId,
					'country_id'      => (int) $countryId,
					'region_id'       => (int) $regionId,
				]);

				if (! isset($matches['count']) || $matches['count'] != 0) {
					return $instance;
				}
			} catch (\Throwable $e) {
				error_log('paypal_smart_buttons: region check failed, skipping instance: ' . $e->getMessage());
			}
		}

		return $fallback;
	}

	/**
	 * Convenience: resolve straight from a checkout-form-shaped array
	 * (billing_address[...]/shipping_address[...], as posted by the theme or
	 * saved in the plugin's pending-session snapshot).
	 */
	public static function resolveFromForm(array $form, ?int $siteId = null): ?array {
		$address = $form['shipping_address'] ?? $form['billing_address'] ?? [];

		$countryId = is_array($address) && ! empty($address['country_id']) ? (int) $address['country_id'] : null;
		$regionId  = is_array($address) && ! empty($address['region_id']) ? (int) $address['region_id'] : null;

		return self::resolve(self::all($siteId), $countryId, $regionId);
	}
}
