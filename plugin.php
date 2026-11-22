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

/*
Name: PayPal Smart Buttons Checkout
Slug: paypal-smart-buttons
Category: payment
Url: https://github.com/Ezyweb-uk/vvveb-paypal-smart-buttons-plugin
Description: SAQ A PCI compliant PayPal integration using the JavaScript SDK and REST API v2. Orders are created and verified server-side.
Author: Ezyweb.uk
Version: 0.4
Thumb: paypal-smart-buttons.svg
Author url: https://ezyweb.uk
Settings: /admin/index.php?module=plugins/paypal-smart-buttons/settings
*/

use Vvveb\Plugins\PaypalSmartButtons\Payment;
use Vvveb\Plugins\PaypalSmartButtons\System\Csp;
use Vvveb\Plugins\PaypalSmartButtons\System\SettingsResolver;
use Vvveb\System\Event;
use Vvveb\System\Payment as PaymentApi;
use Vvveb\System\Routes;

if (! defined('V_VERSION')) {
	die('Invalid request!');
}

class PaypalSmartButtons {
	/** Our own custom routes, in the order they should be checked. */
	private const CUSTOM_ROUTES = [
		'/paypal-smart-create',
		'/paypal-smart-capture',
		'/paypal-smart-resolve',
	];

	public function app() {
		$routes = [
			'checkout/checkout/index',
		];

		//Second half of the route-cache bug fix below
		//(ensureRoutesCacheIncludes): even once our routes genuinely
		//make it into Routes::$routes/$urls, they land at the very END
		//of the array — core's own config/app-routes.php is merged in
		//FIRST (during the earliest Routes::init() call, which runs
		//before any plugin loads, see that method's docblock), and our
		//addRoute() calls below only APPEND new keys afterward.
		//Routes::match() does a plain sequential foreach, first match
		//wins, with NO precedence/specificity sorting — so the generic
		//catch-all pattern '/{slug}' (core's CMS page/post route,
		//module content/post/index) is checked BEFORE our specific,
		//literal paths, and matches them FIRST every time (confirmed
		//live: /paypal-smart-resolve matched route '/{slug}' with
		//slug=paypal-smart-resolve, never reaching our own entry at
		//all, even once it was genuinely present in the table).
		//
		//Fix: hook the SAME core event Routes::init()'s cache-miss
		//branch already fires right before it rebuilds Routes::$urls
		//from Routes::$routes (`list(self::$routes) =
		//Event::trigger('Vvveb\\System\\Routes', 'init', self::$routes)`)
		//and move our known paths to the FRONT of that array. Since
		//this runs INSIDE Routes::init() itself, before the foreach
		//that builds $urls, it fixes the match ORDER for that rebuild
		//AND for whatever gets cached afterward — so once this fires
		//on one genuine cache-miss rebuild (which
		//ensureRoutesCacheIncludes() below guarantees happens whenever
		//needed), every later cache-HIT request loads the
		//already-correctly-ordered table with no further cost.
		Event::on('Vvveb\System\Routes', 'init', __CLASS__, function ($routes) {
			self::resetRoutesUrlsCache();

			return [self::moveRoutesToFront($routes, self::CUSTOM_ROUTES)];
		});

		//only load the SDK + button script on the checkout page
		Event::on('Vvveb\System\Core\View', 'compile', __CLASS__, function ($template, $htmlFile, $tplFile, $vTpl, $view) use ($routes) {
			if ($url = Routes::getUrlData()) {
				if (in_array($url['module'], $routes)) {
					$vTpl->loadTemplateFile(__DIR__ . '/app/template/common.tpl');
				}
			}

			return [$template, $htmlFile, $tplFile, $vTpl, $view];
		});
		
		Event::on('Vvveb\Controller\Base', 'init', __CLASS__, function ($site) use ($routes) {
			if (! headers_sent() && ($url = Routes::getUrlData()) && in_array($url['module'], $routes) && SettingsResolver::sendCheckoutCsp()) {
				$nonce = Csp::nonce();

				//Which configured instance will actually apply to this
				//checkout isn't known yet at this point in the request
				//(SettingsResolver::resolve() needs the buyer's
				//country/region, which only exists once the checkout
				//form/cart has an address -- this header is sent up
				//front, before that). So rather than guess one instance,
				//include the sandbox connect-src host if ANY configured
				//instance uses the sandbox environment -- safe for
				//whichever instance ends up actually resolving, and
				//dropped entirely once every configured instance is
				//'live' (the PayPal SDK's own sandbox-only telemetry
				//beacon, https://www.sandbox.paypal.com/xoplatform/
				//logger/..., is otherwise a connect-src violation in
				//sandbox mode -- confirmed live via a real CSP violation
				//report; harmless in report-only mode, but would be
				//blocked outright in enforce mode).
				$connectSrc = "'self' https://www.paypal.com";

				foreach (SettingsResolver::all() as $instance) {
					if (($instance['environment'] ?? 'sandbox') !== 'live') {
						$connectSrc .= ' https://www.sandbox.paypal.com';

						break;
					}
				}

				header(
					"Content-Security-Policy: " .
					"default-src 'self'; " .
					"script-src 'self' 'nonce-{$nonce}' https://www.paypal.com https://www.paypalobjects.com; " .
					"style-src 'self' 'unsafe-inline'; " .
					"img-src 'self' data: https://www.paypalobjects.com https://*.paypal.com; " .
					"font-src 'self' data:; " .
					"frame-src https://www.paypal.com https://www.sandbox.paypal.com; " .
					"connect-src {$connectSrc}; " .
					"frame-ancestors 'self'; " .
					"base-uri 'self'; " .
					"form-action 'self'; " .
					"object-src 'none'"
				);

				//Out-of-line Speculation Rules header for the checkout page:
				//MOVED to the CSP Manager plugin (system/csp.php's
				//applySpeculationRules(), called from plugin.php's 'render'
				//hook) as of October 2026. It was briefly sent from here,
				//but that tied an unrelated prefetch/prerender optimization
				//to whether THIS payment plugin specifically was installed
				//— if a site runs CSP Manager with a different (or no)
				//payment plugin, the header should still go out. See CSP
				//Manager's Csp::applySpeculationRules() docblock for the
				//full history (including the earlier, broken
				//<script type="speculationrules" src="..."> attempt this
				//plugin also tried first) and for why CSP Manager is the
				//right, decoupled home for it.
			}

			return [$site];
		});

		//create the PayPal order server-side from the cart total (browser sends no amount)
		Routes::addRoute('/paypal-smart-create', ['module' => 'plugins/paypal-smart-buttons/index/create']);

		//verify + capture the approved PayPal order and place the Vvveb order
		Routes::addRoute('/paypal-smart-capture', ['module' => 'plugins/paypal-smart-buttons/index/capture']);

		//resolve which configured instance applies to the CURRENT (not-yet-
		//saved) checkout form address, so the browser can tell whether the
		//already-loaded PayPal SDK script's client-id/currency is still
		//correct after the buyer changes address — see resolve() docblock
		Routes::addRoute('/paypal-smart-resolve', ['module' => 'plugins/paypal-smart-buttons/index/resolve']);

		self::ensureRoutesCacheIncludes(self::CUSTOM_ROUTES);
	}

	/**
	 * Moves the given keys to the front of $routes, preserving their
	 * relative order and leaving every other key where it was. Used to put
	 * our literal, specific route paths ahead of core's generic/
	 * parameterized patterns (like '/{slug}') in Routes::match()'s plain
	 * sequential, first-match-wins scan — see the Event::on('...Routes',
	 * 'init', ...) registration above for why this is needed at all.
	 */
	private static function moveRoutesToFront(array $routes, array $keys): array {
		$prefix = [];

		foreach ($keys as $key) {
			if (array_key_exists($key, $routes)) {
				$prefix[$key] = $routes[$key];
				unset($routes[$key]);
			}
		}

		return $prefix + $routes;
	}

	/**
	 * Clears Vvveb\System\Routes' private static $urls array via
	 * Reflection, right before core's own Routes::init() rebuilds it.
	 *
	 * Reordering Routes::$routes (moveRoutesToFront() above) alone isn't
	 * enough: Routes::init()'s cache-miss branch rebuilds $urls by calling
	 * processRoute() for every entry in $routes, but processRoute() just
	 * does `self::$urls[$route] = [...]` — and reassigning a value to an
	 * ALREADY-EXISTING array key does not change that key's position in a
	 * PHP array, it only updates the value in place. Because $urls already
	 * contains every one of these keys by this point in the request (core's
	 * own earlier, pre-plugin Routes::init() call already populated it —
	 * see ensureRoutesCacheIncludes()'s docblock — and our own addRoute()
	 * calls appended ours after that), simply re-running processRoute() in
	 * our new, reordered $routes order has NO effect on $urls' actual
	 * iteration order (confirmed live: $routes correctly showed our paths
	 * first after this event fired, but $urls still listed core's routes
	 * first immediately afterward). Clearing $urls first forces the
	 * upcoming foreach to insert every key fresh, in the SAME order it now
	 * iterates the reordered $routes — which is the only way to actually
	 * change $urls' match order.
	 */
	private static function resetRoutesUrlsCache(): void {
		try {
			$prop = new \ReflectionProperty(\Vvveb\System\Routes::class, 'urls');
			$prop->setAccessible(true);
			$prop->setValue(null, []);
		} catch (\Throwable $e) {
			error_log('paypal_smart_buttons [route-order-fix-error] ' . $e->getMessage());
		}
	}

	/**
	 * Works around a confirmed Vvveb core routing/caching bug — found live
	 * in this project while tracking down why /paypal-smart-resolve (and
	 * every other custom route this plugin registers) 404s on every single
	 * request, even immediately after clearing every cache:
	 *
	 * - system/core/startup.php's start() calls Sites::getSiteData() before
	 *   Plugins::loadPlugins() — and getSiteData() triggers the FIRST
	 *   Routes::init() call of the request (for site/language routing),
	 *   which — on a cache miss — builds the route table from
	 *   config/app-routes.php + core's own 'Routes::init' event ONLY, since
	 *   no plugin has run yet, then WRITES that plugin-free table to the
	 *   'routes' cache namespace.
	 * - Plugins::loadPlugins() then runs (still within the same request),
	 *   and THIS plugin's Routes::addRoute() calls above correctly append
	 *   our custom paths to Routes' in-memory $routes/$urls.
	 * - But system/core/frontcontroller.php's dispatch() then calls
	 *   Routes::init(APP, $routesSiteId) AGAIN, unconditionally (not
	 *   guarded by Routes' own $init flag the way match()/getRouteData()
	 *   are) — and since step 1 already wrote a cache entry under the same
	 *   key, this is a cache HIT: Routes::init()'s cache-hit branch does a
	 *   bare `static::$routes = $result['routes']` (no merge), which
	 *   OVERWRITES our just-added routes with the stale, plugin-free table
	 *   from step 1 — right before Routes::match() runs to resolve THIS
	 *   request's own module. Confirmed via direct instrumentation of
	 *   Routes::match() on a fresh Vvveb install: total registered routes
	 *   stayed at the core's own count across every match() call in the
	 *   request, both before AND after this plugin's app() ran.
	 * - Because step 1 always reprimes the SAME stale, plugin-free cache
	 *   entry before plugins get a chance on every subsequent request too,
	 *   clearing the cache (Tools → Clear cache, or deleting
	 *   storage/cache/* by hand) does NOT fix this even temporarily — the
	 *   very next request rebuilds the identical plugin-free table before
	 *   Plugins::loadPlugins() ever runs.
	 *
	 * Fix: after registering our routes, check the cache entry that step 3
	 * above is about to blindly reload. If it's missing any of our paths,
	 * delete it — forcing frontcontroller's Routes::init() call to be a
	 * genuine cache MISS instead of a HIT. Its cache-miss path does
	 * `self::$routes += include $routesConfig` — array union, which
	 * preserves keys already in self::$routes (ours, just added by
	 * addRoute() above) rather than discarding them — so it rebuilds the
	 * FULL, correct table (core routes + ours) and re-caches THAT version.
	 * From the very next request onward, step 1's own cache-hit is already
	 * correct, so this self-heals after a single request and adds no
	 * per-request cost once healed (the cheap isset() check below is all
	 * that runs on every normal request afterward).
	 *
	 * Reaches into Vvveb\System\Cache directly (the same cache
	 * namespace/key Routes::init() itself uses) since Routes has no public
	 * API to invalidate just its own cache entry. Wrapped in try/catch —
	 * this is purely a cache-freshness nicety; it must never be able to
	 * break the request if the cache driver behaves unexpectedly.
	 */
	private static function ensureRoutesCacheIncludes(array $paths): void {
		try {
			$cache = \Vvveb\System\Cache::getInstance();

			//Routes::init()'s own cache key is $site_id if truthy, else
			//$app ('app') — we don't know which one frontcontroller's call
			//resolved to without reaching into its private state, so check
			//(and bust) every key it could plausibly be under.
			$candidateKeys = array_values(array_unique(array_filter([
				defined('APP') ? APP : null,
				defined('SITE_ID') ? (string) SITE_ID : null,
			])));

			foreach ($candidateKeys as $key) {
				$cached = $cache->get('routes', $key);

				if (! is_array($cached) || ! isset($cached['urls']) || ! is_array($cached['urls'])) {
					continue; // nothing cached yet under this key — core will build it fresh, nothing to fix
				}

				foreach ($paths as $path) {
					if (! isset($cached['urls'][$path])) {
						$cache->delete('routes', $key);

						break; // this key's cache is stale; move to the next candidate key
					}
				}
			}
		} catch (\Throwable $e) {
			error_log('paypal_smart_buttons [route-cache-fix-error] ' . $e->getMessage());
		}
	}

	public function __construct() {
		if (\Vvveb\isEditor()) {
			return;
		}

		//current core's PaymentMethod no longer declares authorize() as
		//abstract (it now has a default `return false` implementation) — this
		//plugin's Payment class relies on that (it deliberately doesn't
		//override authorize/capture/completeAuthorize/completePurchase/void
		//any more, see payment.php's class docblock). On an older core where
		//these are still abstract, instantiating Payment would fatal with
		//"class contains 1 abstract method" on every single checkout page
		//load. Detect that and skip registration instead, so the store keeps
		//working with every other payment method — just without this one —
		//until the core is updated.
		try {
			$paymentMethodUpToDate = ! (new \ReflectionMethod('Vvveb\System\PaymentMethod', 'authorize'))->isAbstract();
		} catch (\Throwable $e) {
			$paymentMethodUpToDate = false;
		}

		if (! $paymentMethodUpToDate) {
			error_log('paypal_smart_buttons: payment method NOT registered — please update Vvveb core (Vvveb\System\PaymentMethod::authorize() is still abstract on this install)');
		} else {
			$payment = PaymentApi::getInstance();
			$payment->registerMethod('paypal_smart_buttons', Payment::class);
		}

		if (APP == 'app') {
			$this->app();
		}
	}
}

$paypalSmartButtons = new PaypalSmartButtons();
