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
- Front end: tested with the Landing theme (Oct 2026). Optional: Ezyweb's CSP Manager plugin.
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

The checkout page can carry a Content Security Policy that only allows your site's scripts and PayPal's,
which protects the payment page from injected scripts. The plugin's own policy allows PayPal's hosts and
its one inline config script (by a per-request nonce), and its checkout script has a Subresource
Integrity hash.

**Settings › All profiles › Checkout security policy:**

| Setting | What happens |
|---|---|
| **Automatic** (default) | Sent only when Ezyweb's [CSP Manager](https://ezyweb.uk) plugin (`csp-manager`) is active. CSP Manager merges it into its own policy and applies its report-only or enforce mode. Without CSP Manager, no policy is sent and the theme's checkout works as usual. |
| **Always send** | Sent on every checkout, with or without CSP Manager. |
| **Never send** | Not sent. If CSP Manager is enforcing, its checkout policy then lacks PayPal's hosts and blocks the buttons (the settings page warns about this). |

A strict policy also blocks the theme's inline checkout code. Whenever that code is actually blocked (this
plugin's policy without CSP Manager, or CSP Manager in enforce mode), the plugin loads
`checkout-compat.js`, which does the same work from an allowed file for the Landing theme: the region
list when the country changes, "ship to a different address", and the login, register and password
toggles. It only acts on inline handlers that are still in the page, so themes that already moved them
out are unaffected. CSP Manager's "strip inline handlers" option removes those handlers from the page, so
with stock Landing turn that option off (or use a theme with its own listeners).

The plugin adds three routes: `/paypal-smart-create`, `/paypal-smart-capture` and `/paypal-smart-resolve`.

## Version history

- **0.4** (2026-11-22): checkout security policy setting (Automatic / Always / Never), detecting
  CSP Manager and its mode; `checkout-compat.js` keeps the Landing theme's checkout working (region list,
  shipping address and login/register toggles) when its inline code is blocked. Copyright notice and
  LICENSE added.
- **0.3** (Nov 2026): settings page updated for the Vvveb 1.0.9.0 admin (SVG icons, new header layout).
- Earlier versions: multi-profile PayPal Smart Buttons checkout with server-side order creation and capture.

## Licence

Copyright (C) 2026 Ezyweb.uk. Released under the GNU Affero General Public License v3 or later
(see LICENSE). Built for Vvveb CMS by Ziadin Givan, which is also AGPLv3. The settings page is based on
Vvveb's bundled payment plugin, Copyright (C) 2022 Ziadin Givan.
