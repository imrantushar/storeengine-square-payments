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

		if ( ! Helper::is_checkout() && ! Helper::is_add_payment_method_page() ) {
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
