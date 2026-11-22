// PayPal Smart Buttons for Vvveb
// Copyright (C) 2022  Ziadin Givan
// Copyright (C) 2026  Ezyweb.uk
// Licensed under the GNU Affero General Public License v3 or later, see the LICENSE file

import(common.tpl)

@method = [data-v-methods] [data-v-method]
@method|deleteAllButFirstChild

@method|before = <?php
if (isset($this->methods) && is_array($this->methods))
foreach ($this->methods as $index => $method) { ?>

	[data-v-methods] .tab-pane[data-v-method]|id = <?php echo "tab-option$index";?>
	[data-v-methods] .tab-pane[data-v-method]|addClass = <?php if ($index == 0) echo "active";?>

	@method [data-v-method-payment_method_id]|href = <?php echo "#tab-option$index";?>
	@method [data-v-method-payment_method_id]|addClass = <?php if ($index == 0) echo "active";?>
	@method [data-v-method-name] = <?php echo htmlspecialchars($method['name'] ?? 'PayPal'); ?>

	@method input.method|name = <?php
		$name = '@@__name__@@';
		echo str_replace('methods[0]', "methods[$index]", $name);
	?>

	@method select|name = <?php
		$name = '@@__name__@@';
		echo str_replace('methods[0]', "methods[$index]", $name);
	?>

	@method textarea|name = <?php
		$name = '@@__name__@@';
		echo str_replace('methods[0]', "methods[$index]", $name);
	?>

	@method .instance-id|name = <?php
		$name = '@@__name__@@';
		echo str_replace('methods[0]', "methods[$index]", $name);
	?>
	@method .instance-id|value = <?php echo htmlspecialchars($method['instance_id'] ?? ''); ?>

	@method .field-name|value              = <?php echo htmlspecialchars($method['name'] ?? ''); ?>
	@method .field-client-id|value         = <?php echo htmlspecialchars($method['client_id'] ?? ''); ?>
	@method .field-sandbox-client-id|value = <?php echo htmlspecialchars($method['sandbox_client_id'] ?? ''); ?>
	@method .field-sort-order|value        = <?php echo htmlspecialchars((string) ($method['sort_order'] ?? 0)); ?>
	@method .field-message|innerText       = <?php echo htmlspecialchars($method['message'] ?? ''); ?>

	@method .field-client-secret|data-saved         = <?php echo ! empty($method['has_client_secret']) ? '1' : '0'; ?>
	@method .field-sandbox-client-secret|data-saved = <?php echo ! empty($method['has_sandbox_client_secret']) ? '1' : '0'; ?>

	@method .field-debug|addNewAttribute = <?php if (! empty($method['debug'])) echo 'checked'; ?>

@method|after = <?php
}
?>

.environment-select option[value="live"]|addNewAttribute    = <?php if (isset($method) && ($method['environment'] ?? 'sandbox') === 'live') echo 'selected'; ?>
.environment-select option[value="sandbox"]|addNewAttribute = <?php if (isset($method) && ($method['environment'] ?? 'sandbox') !== 'live') echo 'selected'; ?>

.field-currency option[value="GBP"]|addNewAttribute = <?php if (isset($method) && ($method['currency'] ?? 'GBP') === 'GBP') echo 'selected'; ?>
.field-currency option[value="USD"]|addNewAttribute = <?php if (isset($method) && ($method['currency'] ?? 'GBP') === 'USD') echo 'selected'; ?>
.field-currency option[value="EUR"]|addNewAttribute = <?php if (isset($method) && ($method['currency'] ?? 'GBP') === 'EUR') echo 'selected'; ?>

@region_group = [data-v-region_group_id] [data-v-option]
@region_group|deleteAllButFirstChild

@region_group|before = <?php
if (isset($this->region_group) && is_array($this->region_group))
foreach ($this->region_group as $region_group_index => $region_group) { ?>

	@region_group|innerText = $region_group
	@region_group|value     = $region_group_index
	@region_group|addNewAttribute = <?php if (isset($method['region_group_id']) && (string) $region_group_index === (string) $method['region_group_id']) echo 'selected'; ?>

@region_group|after = <?php
}
?>

@success_payment_status = [data-v-success_payment_status_id] [data-v-option]
@success_payment_status|deleteAllButFirstChild

@success_payment_status|before = <?php
if (isset($this->success_payment_status) && is_array($this->success_payment_status))
foreach ($this->success_payment_status as $status_index => $status_name) { ?>

	@success_payment_status|innerText = $status_name
	@success_payment_status|value     = $status_index
	@success_payment_status|addNewAttribute = <?php if (isset($method['success_payment_status_id']) && (string) $status_index === (string) $method['success_payment_status_id']) echo 'selected'; ?>

@success_payment_status|after = <?php
}
?>
