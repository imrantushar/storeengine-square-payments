<?php
/**
 * Ajax
 *
 * Square AJAX endpoints extending StoreEngine's AbstractAjaxHandler.
 *
 * @package StoreEngineSquare
 */

namespace StoreEngineSquare;

use StoreEngine\Classes\AbstractAjaxHandler;
use StoreEngine\Classes\AbstractRequestHandler;
use StoreEngine\Classes\Exceptions\StoreEngineException;
use StoreEngine\Classes\Order;
use StoreEngine\Payment_Gateways;
use StoreEngine\Utils\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ajax extends AbstractAjaxHandler {

	public static function init(): void {
		$self = new self();
		$self->dispatch_actions();
	}

	public function __construct() {
		$this->actions = [

			// Returns application_id + location_id so the JS can boot Square Web Payments SDK.
			'payment_method/square/init-checkout' => [
				'callback'             => [ $this, 'init_checkout' ],
				'allow_visitor_action' => true,
				'fields'               => [
					'order_id' => AbstractRequestHandler::ABSINT,
				],
			],

			// Sanity-checks a Square payment ID after the JS-side flow completes.
			'payment_method/square/verify-payment' => [
				'callback'             => [ $this, 'verify_payment' ],
				'allow_visitor_action' => true,
				'fields'               => [
					'order_id'          => AbstractRequestHandler::ABSINT,
					'square_payment_id' => AbstractRequestHandler::STRING,
				],
			],
		];
	}

	// ── Handlers ──────────────────────────────────────────────────────────────

	public function init_checkout( array $payload ): array {
		$gateway = Payment_Gateways::get_instance()->get_gateway( 'square' );

		if ( ! $gateway || ! $gateway->is_enabled ) {
			throw new StoreEngineException(
				esc_html__( 'Square gateway is not available.', 'storeengine-square-payments' ),
				'square-gateway-unavailable', null, 503
			);
		}

		$service = SquareService::init( $gateway );

		return [
			'application_id' => $service->get_application_id(),
			'location_id'    => $service->get_location_id(),
			'is_sandbox'     => ! $service->is_live(),
		];
	}

	public function verify_payment( array $payload ): array {
		$order_id          = absint( $payload['order_id'] ?? 0 );
		$square_payment_id = sanitize_text_field( $payload['square_payment_id'] ?? '' );

		if ( ! $order_id || ! $square_payment_id ) {
			throw new StoreEngineException(
				esc_html__( 'Missing order ID or Square payment ID.', 'storeengine-square-payments' ),
				'square-missing-params', null, 400
			);
		}

		$order = Helper::get_order( $order_id );

		if ( is_wp_error( $order ) || ! $order instanceof Order ) {
			throw new StoreEngineException( esc_html__( 'Order not found.', 'storeengine-square-payments' ), 'square-order-not-found', null, 404 );
		}

		$stored = $order->get_meta( '_square_payment_id', true, 'edit' );

		if ( $stored && ! hash_equals( $stored, $square_payment_id ) ) {
			throw new StoreEngineException( esc_html__( 'Payment ID mismatch.', 'storeengine-square-payments' ), 'square-payment-id-mismatch', null, 400 );
		}

		return [
			'verified'    => true,
			'redirect'    => $order->get_checkout_order_received_url(),
			'receipt_url' => $order->get_meta( '_square_receipt_url', true ),
		];
	}
}

// End of file Ajax.php.
