<?php
namespace StoreEngineSquare;

use StoreEngineSquare\GatewaySquare;
use StoreEngineSquare\SquareService;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Hooks {

	protected static ?Hooks $instance = null;
	protected static GatewaySquare $gateway;

	public static function init( GatewaySquare $gateway ): ?Hooks {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$gateway  = $gateway;

			if ( $gateway->is_available() ) {
				add_filter( 'storeengine/frontend_scripts_payment_method_data', [ __CLASS__, 'inject_js_params' ] );
			}
		}

		return self::$instance;
	}

	public static function inject_js_params( array $payment_method ): array {
		if ( ! self::$gateway->is_available() ) {
			return $payment_method;
		}
		$service = SquareService::init( self::$gateway );
		$payment_method['square'] = [
			'application_id' => $service->get_application_id(),
			'location_id'    => $service->get_location_id(),
			'is_sandbox'     => ! $service->is_live(),
		];
		return $payment_method;
	}
}
