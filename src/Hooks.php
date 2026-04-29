<?php
namespace StoreEngineSquare;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Server-side glue for the Square gateway:
 *
 *   1. Expose Square's client config (application_id, location_id, sandbox flag)
 *      to both checkout surfaces via the unified
 *      `storeengine/checkout/gateway/square/data` filter.
 *   2. Copy the Square `square_payment_token` from the `/checkout/place` REST
 *      payload onto $_POST so the existing GatewaySquare::process_payment()
 *      (which reads $_POST['square_payment_token']) keeps working.
 */
class Hooks {

	protected static ?Hooks $instance = null;
	protected static GatewaySquare $gateway;

	public static function init( GatewaySquare $gateway ): ?Hooks {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$gateway  = $gateway;

			add_filter( 'storeengine/checkout/gateway/square/data', [ __CLASS__, 'expose_gateway_data' ], 10, 2 );
			add_action( 'storeengine/checkout/before_place_order_payload/square', [ __CLASS__, 'remap_payment_payload' ] );
		}

		return self::$instance;
	}

	/**
	 * Same shape the legacy `storeengine/frontend_scripts_payment_method_data`
	 * filter used to emit, but addressed by the new per-gateway hook so both
	 * legacy `/checkout/` and React Quick Checkout pick it up automatically.
	 */
	public static function expose_gateway_data( array $data, $gateway ): array {
		if ( ! self::$gateway->is_available() ) {
			return $data;
		}

		$service = SquareService::init( self::$gateway );

		return array_merge( $data, [
			'application_id' => $service->get_application_id(),
			'location_id'    => $service->get_location_id(),
			'is_sandbox'     => ! $service->is_live(),
		] );
	}

	/**
	 * REST `/checkout/place` posts a JSON body — gateway code paths that read
	 * scalars via $_POST need them piped in. Core already does this for every
	 * scalar in `payment_payload`, so this hook only exists as a safety net /
	 * extension point. Keep it lightweight.
	 */
	public static function remap_payment_payload( array $payment_data ): void {
		if ( ! empty( $payment_data['square_payment_token'] ) && ! isset( $_POST['square_payment_token'] ) ) {
			$_POST['square_payment_token']    = sanitize_text_field( (string) $payment_data['square_payment_token'] );
			$_REQUEST['square_payment_token'] = $_POST['square_payment_token'];
		}
	}
}
