# PayPal Smart Buttons for Vvveb

A [Vvveb CMS](https://www.vvveb.com) payment plugin that adds PayPal's Smart Buttons (PayPal, Pay Later and
debit/credit card) to the checkout, using the PayPal JavaScript SDK and the Orders REST API v2.

Card details are entered in PayPal's own hosted fields, never on your site, which keeps the checkout within
PCI DSS SAQ A. The PayPal order is created on the server from the cart total (the browser never sends an
amount), and the payment is verified and captured on the server before the Vvveb order is placed.

## Features

- PayPal, Pay Later and card buttons on the checkout page, only shown when the plugin is configured.
- Several PayPal profiles, each limited to a Geo Region (region group), with an "All Regions" fallback.
  The buttons switch profile when the shopper changes their address.
- Sandbox and Live credentials per profile, stored encrypted with the site's app key.
- Currency per profile (GBP, USD or EUR), payment status on success, sort order, checkout name and message.
- Full and partial refunds from the admin order page.
- Hidden for subscription carts (PayPal Smart Buttons can't bill repeat payments).
- Debug mode per profile: extra detail in the browser console and checkout error messages
  (full errors always go to the PHP error log).

## Requirements

- Vvveb 1.0.9.0 or later, PHP 8 with cURL.
- A PayPal Business account and a REST app (Client ID and Secret) from the
  [PayPal Developer Dashboard](https://developer.paypal.com/dashboard/applications).

## Installation

1. Download `paypal-smart-buttons.zip` from the [latest release](../../releases/latest).
2. In the Vvveb admin go to **Plugins › Add new**, upload the zip and activate **PayPal Smart Buttons Checkout**.
3. Open the plugin's **Settings**, enter your Sandbox (or Live) Client ID and Secret, choose the environment
   and click **Save settings**.

Manual install: copy the files to `plugins/paypal-smart-buttons/` and the `public/` folder to
`public/plugins/paypal-smart-buttons/`, then activate the plugin. The folder must be named
`paypal-smart-buttons` (the plugin's slug).

## Content Security Policy

On the checkout page the plugin sends its own Content Security Policy header: scripts only from your site,
PayPal's hosts, and the one small inline config script, which carries a per-request nonce. The checkout
script is loaded with a Subresource Integrity hash.

This strict policy also blocks any inline `<script>` and inline `onclick` handlers in the theme's checkout
page. See the version history and the issues list for the status of Landing theme compatibility.

The plugin adds three routes: `/paypal-smart-create`, `/paypal-smart-capture` and `/paypal-smart-resolve`.

## Version history

- **0.3** (Nov 2026): settings page updated for the Vvveb 1.0.9.0 admin (SVG icons, new header layout).
  Copyright notice and LICENSE added.
- Earlier versions: multi-profile PayPal Smart Buttons checkout with server-side order creation and capture.

## Licence

Copyright (C) 2026 Ezyweb.uk. Released under the GNU Affero General Public License v3 or later
(see LICENSE). Built for Vvveb CMS by Ziadin Givan, which is also AGPLv3. The settings page is based on
Vvveb's bundled payment plugin, Copyright (C) 2022 Ziadin Givan.
