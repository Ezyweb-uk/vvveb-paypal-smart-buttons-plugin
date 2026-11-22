<?php

/**
 * PayPal Smart Buttons for Vvveb
 *
 * Copyright (C) 2022  Ziadin Givan
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
use function Vvveb\deleteSetting;
use function Vvveb\getSetting;
use function Vvveb\setMultiSetting;
use Vvveb\Plugins\PaypalSmartButtons\System\Crypto;
use Vvveb\Sql\Payment_StatusSQL;
use Vvveb\Sql\Region_GroupSQL;
use Vvveb\System\CacheManager;

/**
 * Settings storage now matches core's own `payment`/`shipping` plugins: an
 * array of "instances" saved under the 'paypal_smart_buttons' namespace with
 * numeric keys (0, 1, 2...), each a JSON blob of one complete settings
 * profile, rather than one flat row per field. See system/settings-resolver.php
 * for how a specific instance is chosen at checkout time.
 *
 * The one thing those core plugins don't have to worry about that we do:
 * encrypted secrets. A blank secret field on save means "keep what's already
 * stored", but since instances can be reordered/added/removed by the admin
 * UI's tabs, array position alone isn't a safe way to match a submitted
 * instance back to its saved encrypted secret — so every instance carries a
 * stable `instance_id` (set once, on first save) used for that matching
 * instead.
 */
class Settings extends Base {
	private $namespace = 'paypal_smart_buttons';

	/**
	 * Every row under this namespace SHOULD be an array (one whole instance),
	 * but a site upgrading from this plugin's original single-flat-row
	 * storage still has old rows keyed by field name with plain scalar
	 * values (e.g. 'environment' => 'sandbox', 'client_id' => '<encrypted>')
	 * sitting under the very same namespace — getSetting() happily returns
	 * those alongside (or instead of) real instances, and treating one as an
	 * instance array blows up with "Cannot access offset of type string on
	 * string". This runs once (triggered just by loading the settings page)
	 * to fold any such legacy rows into one proper instance and replace the
	 * old rows on disk, so it's self-healing rather than a manual migration
	 * step.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadInstances(): array {
		$raw = (array) getSetting($this->namespace, null, [], $this->global['site_id']);

		if (! $raw) {
			return [];
		}

		$instances = [];
		$legacy    = [];

		foreach ($raw as $key => $value) {
			if (is_array($value)) {
				$instances[] = $value;
			} else {
				$legacy[$key] = $value;
			}
		}

		if ($legacy) {
			$instances[] = [
				'instance_id'               => bin2hex(random_bytes(8)),
				'name'                      => $legacy['name'] ?? 'PayPal',
				'environment'               => $legacy['environment'] ?? 'sandbox',
				'client_id'                 => $legacy['client_id'] ?? '',
				'client_secret'             => $legacy['client_secret'] ?? '',
				'sandbox_client_id'         => $legacy['sandbox_client_id'] ?? '',
				'sandbox_client_secret'     => $legacy['sandbox_client_secret'] ?? '',
				'currency'                  => $legacy['currency'] ?? 'GBP',
				'region_group_id'           => $legacy['region_group_id'] ?? 0,
				'success_payment_status_id' => $legacy['success_payment_status_id'] ?? 4,
				'sort_order'                => $legacy['sort_order'] ?? 0,
				'message'                   => $legacy['message'] ?? '',
				'debug'                     => $legacy['debug'] ?? 0,
			];

			$instances = array_values($instances);

			deleteSetting($this->namespace, null, $this->global['site_id']);
			setMultiSetting($this->namespace, $instances, $this->global['site_id']);
			CacheManager::delete();
		}

		return array_values($instances);
	}

	public function save() {
		$posted = $this->request->post['methods'] ?? false;
		$errors = [];

		if ($posted) {
			$existingById = [];

			foreach ($this->loadInstances() as $row) {
				if (! empty($row['instance_id'])) {
					$existingById[$row['instance_id']] = $row;
				}
			}

			$methods = [];

			foreach ($posted as $method) {
				if (! is_array($method)) {
					continue;
				}

				$name        = trim((string) ($method['name'] ?? '')) ?: 'PayPal';
				$environment = ($method['environment'] ?? 'sandbox') === 'live' ? 'live' : 'sandbox';
				$currency    = strtoupper((string) ($method['currency'] ?? 'GBP'));

				if (! in_array($currency, ['GBP', 'USD', 'EUR'], true)) {
					$errors[] = sprintf(__('Invalid currency for "%s".'), $name);
				}

				$submittedId = (string) ($method['instance_id'] ?? '');
				$existing    = $existingById[$submittedId] ?? [];
				$instanceId  = $existing ? $submittedId : bin2hex(random_bytes(8));

				foreach (Crypto::ID_FIELDS as $field) {
					$method[$field] = trim((string) ($method[$field] ?? ''));

					if ($method[$field] !== '' && ! preg_match('/^[A-Za-z0-9_\-]+$/', $method[$field])) {
						$errors[] = sprintf(__('Client ID may only contain letters, numbers, dashes and underscores ("%s").'), $name);
					}
				}

				$activeIdField     = Crypto::ID_FIELDS[$environment];
				$activeSecretField = Crypto::SECRET_FIELDS[$environment];

				if ($method[$activeIdField] === '') {
					$errors[] = sprintf(__('%s Client ID is required for "%s".'), ucfirst($environment), $name);
				}

				// blank secret = keep the one already stored for this instance
				// (still encrypted); anything actually typed gets encrypted fresh
				foreach (Crypto::SECRET_FIELDS as $field) {
					if (($method[$field] ?? '') === '') {
						if (isset($existing[$field])) {
							$method[$field] = $existing[$field];
						} else {
							unset($method[$field]);
						}
					} else {
						$method[$field] = Crypto::encrypt($method[$field]);
					}
				}

				if (empty($method[$activeSecretField])) {
					$errors[] = sprintf(__('%s Client Secret is required for "%s".'), ucfirst($environment), $name);
				}

				foreach (Crypto::ID_FIELDS as $field) {
					$method[$field] = Crypto::encrypt($method[$field]);
				}

				$method['instance_id'] = $instanceId;
				$method['name']        = $name;
				$method['environment'] = $environment;
				$method['currency']    = $currency;
				$method['debug']       = isset($method['debug']) ? 1 : 0;

				$methods[] = $method;
			}

			if ($errors) {
				$this->view->errors = $errors;
			} else {
				deleteSetting($this->namespace, null, $this->global['site_id']);
				$methods = array_values($methods);
				setMultiSetting($this->namespace, $methods, $this->global['site_id']);

				CacheManager::delete();
				$this->view->success[] = __('Settings saved!');
			}
		}

		return $this->index();
	}

	public function index() {
		$geoRegion = new Region_GroupSQL();
		$regions   = $geoRegion->getAll($this->global);

		$region_group_id = [];

		if (isset($regions['region_group'])) {
			foreach ($regions['region_group'] as $region_group) {
				$region_group_id[$region_group['region_group_id']] = $region_group['name'];
			}
		}

		$this->view->region_group = $region_group_id;

		$paymentStatus = new Payment_StatusSQL();
		$statuses      = $paymentStatus->getAll($this->global);

		$payment_status_id = [];

		if (isset($statuses['payment_status'])) {
			foreach ($statuses['payment_status'] as $status) {
				$payment_status_id[$status['payment_status_id']] = $status['name'];
			}
		}

		$this->view->success_payment_status = $payment_status_id;

		$methods = $this->loadInstances();

		// decrypt client ids for display; secrets are never sent back to the
		// browser, only whether one is currently saved (has_client_secret /
		// has_sandbox_client_secret), so the form can show "leave blank to keep"
		foreach ($methods as &$method) {
			foreach (Crypto::ID_FIELDS as $field) {
				$method[$field] = Crypto::decrypt($method[$field] ?? '');
			}

			foreach (Crypto::SECRET_FIELDS as $field) {
				$method['has_' . $field] = ! empty($method[$field]);
				unset($method[$field]);
			}
		}

		unset($method);

		// always at least one (empty) instance so the settings page has
		// something to render on a fresh install
		if (! $methods) {
			$methods = [[
				'instance_id' => '',
				'name'        => 'PayPal',
				'environment' => 'sandbox',
				'currency'    => 'GBP',
			]];
		}

		$this->view->methods = $methods;
	}
}
