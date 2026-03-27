<?php
namespace StoreEngineSquare;

use StoreEngine\Utils\Helper;
use StoreEngineSquare\GatewaySquare;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Assets {

	protected static GatewaySquare $gateway;

	public static function init( GatewaySquare $gateway ): void {
		self::$gateway = $gateway;
		add_action( 'storeengine/enqueue_frontend_scripts', [ __CLASS__, 'enqueue_frontend' ] );
	}

	public static function enqueue_frontend(): void {
		if ( ! self::$gateway->is_available() ) {
			return;
		}

		if ( ! Helper::is_checkout() && ! Helper::is_add_payment_method_page() ) {
			return;
		}

		// Official Square Web Payments SDK CDN.
		wp_enqueue_script(
			'square-web-payments-sdk',
			'https://web.squarecdn.com/v1/square.js',
			[],
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NoExplicitVersion
			true
		);

		if ( file_exists( SE_SQUARE_DIR . 'assets/js/square-checkout.js' ) ) {
			wp_enqueue_script(
				'storeengine-square-checkout',
				SE_SQUARE_URL . 'assets/js/square-checkout.js',
				[ 'storeengine-frontend', 'square-web-payments-sdk' ],
				SE_SQUARE_VERSION,
				true
			);
		}
	}
}
