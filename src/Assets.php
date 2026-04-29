<?php
namespace StoreEngineSquare;

use StoreEngine\Utils\Helper;
use StoreEngineSquare\GatewaySquare;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Assets {

	protected static GatewaySquare $gateway;

	public static function init( GatewaySquare $gateway ): void {
		self::$gateway = $gateway;
		add_action( 'storeengine/assets/after_frontend_scripts', [ __CLASS__, 'enqueue_frontend' ] );
	}

	public static function enqueue_frontend(): void {
		if ( ! self::$gateway->is_available() ) {
			return;
		}

		// Enqueue on:
		//   - the legacy /checkout/ page
		//   - the dashboard "Add a payment method" page
		//   - the Instant Checkout React iframe (/?se_checkout=1)
		// The iframe loads same-origin from the store, so it needs the same
		// gateway scripts — the SDK and adapter wouldn't otherwise be present.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_quick_checkout_iframe = ! empty( $_GET['se_checkout'] );

		if ( ! Helper::is_checkout() && ! Helper::is_add_payment_method_page() && ! $is_quick_checkout_iframe ) {
			return;
		}

		// Two separate CDN URLs — Square requires the matching one for your environment
		$SDK_URL_PRODUCTION = 'https://web.squarecdn.com/v1/square.js';
		$SDK_URL_SANDBOX    = 'https://sandbox.web.squarecdn.com/v1/square.js';

		$is_production = (bool) self::$gateway->get_option( 'is_production', false );
		$sdk_url       = $is_production ? $SDK_URL_PRODUCTION : $SDK_URL_SANDBOX;


		// Official Square Web Payments SDK CDN.
		wp_enqueue_script(
			'square-web-payments-sdk',
			$sdk_url,
			[],
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NoExplicitVersion
			true
		);

		// ── Localhost / HTTP dev bypass ───────────────────────────────────────
		// Square's SDK refuses to initialise on non-HTTPS origins.
		// When SQUARE_ALLOW_HTTP is defined (or WP_DEBUG is on) and the current
		// request is NOT over SSL we patch window.isSecureContext to true on
		// Window.prototype before the SDK script runs.
		//
		// Add this to wp-config.php for local development:
		//   define( 'SQUARE_ALLOW_HTTP', true );
		//
		// This patch is NEVER applied on HTTPS or in production environments.
		$allow_http = ( defined( 'SQUARE_ALLOW_HTTP' ) && \SQUARE_ALLOW_HTTP )
		              || ( defined( 'WP_DEBUG' ) && \WP_DEBUG );

		if ( ! is_ssl() && $allow_http ) {
			wp_add_inline_script(
				'square-web-payments-sdk',
				'(function(){try{Object.defineProperty(Window.prototype,"isSecureContext",{get:function(){return true;},configurable:true,enumerable:true});}catch(e){}})();',
				'before'
			);
		}

		$dependencies = include_once SE_SQUARE_DIR . 'assets/build/payments.asset.php';
		wp_enqueue_script(
			'storeengine-square-checkout',
			SE_SQUARE_URL . 'assets/build/payments.js',
			[],
			$dependencies['version'],
			true
		);
	}
}
