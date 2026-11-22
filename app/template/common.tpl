// PayPal Smart Buttons for Vvveb
// Copyright (C) 2026  Ezyweb.uk
// Licensed under the GNU Affero General Public License v3 or later, see the LICENSE file

body|append = from(/public/plugins/paypal-smart-buttons/paypal-smart-buttons.html|script)

// Theme compatibility script: only when the checkout CSP is sent and enforced
body|append = <?php if (\Vvveb\Plugins\PaypalSmartButtons\System\SettingsResolver::checkoutInlineBlocked()) { $ppsbCompat = DIR_PUBLIC . 'plugins/paypal-smart-buttons/checkout-compat.js'; echo '<script id="paypal-smart-buttons-compat" src="/plugins/paypal-smart-buttons/checkout-compat.js?v=' . (int) @filemtime($ppsbCompat) . '" data-regions-url="' . htmlspecialchars((string) ($this->regionsUrl ?? '')) . '"></script>'; } ?>
