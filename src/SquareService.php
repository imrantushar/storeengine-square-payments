<?php
/**
 * SquareService
 *
 * Wraps the official square/square Composer SDK (v43+).
 * All Square API calls go through this class — no raw HTTP anywhere else.
 *
 * INSTALL
 *   composer require square/square  (^43.0, PHP ^8.1)
 *
 * SDK NAMESPACE MAP (v41+ full rewrite — breaking change from v40)
 *   Square\SquareClient                              main client
 *   Square\Environments                              Production / Sandbox enum
 *   Square\Exceptions\SquareException                all API-level errors
 *   Square\Types\Money                               { amount:int, currency:string }
 *   Square\Types\Currency                            enum  Currency::Usd->value = 'USD'
 *   Square\Types\Address                             address value object
 *   Square\Types\Card                                card response object
 *   Square\Types\Payment                             payment response object
 *   Square\Payments\Requests\CreatePaymentRequest    POST /v2/payments
 *   Square\Refunds\Requests\RefundPaymentRequest     POST /v2/refunds
 *   Square\Customers\Requests\CreateCustomerRequest  POST /v2/customers
 *   Square\Cards\Requests\CreateCardRequest          POST /v2/cards
 *
 * CLIENT RESOURCE PROPERTIES
 *   $client->payments   PaymentsClient  (create, get, cancel, complete)
 *   $client->refunds    RefundsClient   (refundPayment, getPaymentRefund)
 *   $client->customers  CustomersClient (create, retrieve, search, update, delete)
 *   $client->cards      CardsClient     (create, retrieve, disable, list)
 *   $client->locations  LocationsClient (list, retrieveLocation)
 *
 * @package StoreEngineSquare\Square
 */

namespace StoreEngineSquare;

use Square\Cards\Requests\CreateCardRequest;
use Square\Customers\Requests\CreateCustomerRequest;
use Square\Exceptions\SquareException;
use Square\Payments\Requests\CreatePaymentRequest;
use Square\Refunds\Requests\RefundPaymentRequest;
use Square\SquareClient;
use Square\Environments;
use Square\Types\Address;
use Square\Types\Card;
use Square\Types\Money;
use Square\Types\Payment;
use Square\Locations\Requests\GetLocationsRequest;
use StoreEngine\Classes\Exceptions\StoreEngineException;
use StoreEngine\Classes\Order;
use StoreEngine\Payment_Gateways;
use StoreEngine\Utils\Formatting;
use StoreEngine\Utils\Helper;
use StoreEngineSquare\GatewaySquare;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SquareService {

	// ── Currency data ─────────────────────────────────────────────────────────

	/**
	 * Currencies accepted by Square.
	 * @link https://developer.squareup.com/docs/build-basics/working-with-monetary-amounts#countries-and-currencies
	 */
	private static array $supported_currencies = [
		'AUD', 'CAD', 'EUR', 'GBP', 'JPY', 'NZD', 'USD',
	];

	/**
	 * Zero-decimal currencies — the integer amount IS the full unit (no ×100).
	 */
	private static array $zero_decimal_currencies = [
		'JPY',
	];

	/** Minimum charge in the major currency unit (e.g. 1.00 USD = $1.00). */
	private static array $currency_minimum_charges = [
		'AUD' => 1.00,
		'CAD' => 1.00,
		'EUR' => 1.00,
		'GBP' => 0.30,
		'JPY' => 50.0,
		'NZD' => 1.00,
		'USD' => 1.00,
	];

	// ── Instance state ────────────────────────────────────────────────────────

	private bool   $is_live        = false;
	private string $access_token   = '';
	private string $application_id = '';
	private string $location_id    = '';

	private ?GatewaySquare $gateway = null;

	/** @var ?SquareClient  Official SDK client — null until init_settings() succeeds. */
	private ?SquareClient $client = null;

	private static ?SquareService $instance = null;

	// ── Singleton factory ─────────────────────────────────────────────────────

	public static function init( $gateway = null ): SquareService {
		if ( null === self::$instance ) {
			self::$instance = new self( $gateway );
		}

		return self::$instance;
	}

	public function __construct( $gateway = null ) {
		$this->gateway = ( $gateway instanceof GatewaySquare )
			? $gateway
			: Payment_Gateways::get_instance()->get_gateway( 'square' );

		$this->init_settings();
	}

	// ── SDK client initialisation ─────────────────────────────────────────────

	/**
	 * Read gateway settings and build the SquareClient.
	 *
	 * The v41+ SDK constructor signature:
	 *   new SquareClient( token: string, options: array )
	 *
	 * The 'baseUrl' option accepts a value from the Environments enum:
	 *   Environments::Production->value  →  'https://connect.squareup.com'
	 *   Environments::Sandbox->value     →  'https://connect.squareupsandbox.com'
	 */
	private function init_settings(): void {
		if ( ! $this->gateway || ! $this->gateway->is_enabled || 'square' !== $this->gateway->id ) {
			return;
		}

		$this->is_live        = (bool) $this->gateway->get_option( 'is_production', false );
		$prefix               = $this->is_live ? '' : 'sandbox_';
		$this->access_token   = (string) $this->gateway->get_option( $prefix . 'access_token' );
		$this->application_id = (string) $this->gateway->get_option( $prefix . 'application_id' );
		$this->location_id    = (string) $this->gateway->get_option( $prefix . 'location_id' );

		if ( ! $this->access_token ) {
			return;
		}

		$this->client = new SquareClient(
			token  : $this->access_token,
			options: [
				'baseUrl' => $this->is_live
					? Environments::Production->value
					: Environments::Sandbox->value,
			]
		);
	}

	// ── Payment operations ────────────────────────────────────────────────────

	/**
	 * Charge a new card nonce returned by Square Web Payments SDK.
	 *
	 * Card data never touches your server — Square.js tokenises it on the
	 * client and returns a single-use nonce ('source_id').
	 *
	 * @param Order  $order
	 * @param string $source_id        Nonce from Square.js.
	 * @param string $idempotency_key  Unique per payment attempt (prevents double-charge on retry).
	 * @param bool   $save_card        Store the card on-file for future use.
	 * @param string $customer_id      Square Customer ID (required when $save_card is true).
	 *
	 * @return Payment   Full Square Payment object.
	 * @throws StoreEngineException
	 */
	public function create_payment(
		Order $order,
		string $source_id,
		string $idempotency_key,
		bool $save_card = false,
		string $customer_id = ''
	): Payment {
		$this->assert_client();

		$request = new CreatePaymentRequest( [
			// Required fields.
			'idempotencyKey' => $idempotency_key,
			'sourceId'       => $source_id,
			'amountMoney'    => new Money( [
				'amount'   => self::get_square_amount(
					(float) $order->get_total( 'square_payment' ),
					$order->get_currency()
				),
				'currency' => strtoupper( $order->get_currency() ),
			] ),
			// Recommended fields.
			'autocomplete'      => true,
			'locationId'        => $this->location_id,
			'referenceId'       => (string) $order->get_id(),
			'buyerEmailAddress' => $order->get_billing_email(),
			'billingAddress'    => $this->build_address( $order ),
			'note'              => sprintf(
				/* translators: 1: site name 2: order ID */
				__( 'Payment for %1$s – Order #%2$s', 'storeengine-square' ),
				get_bloginfo( 'name' ),
				$order->get_id()
			),
		] );

		// Optionally save the card on-file after successful charge.
		if ( $save_card && $customer_id ) {
			$request->setCustomerId( $customer_id );
			$request->setStorePaymentMethodInVault( 'ON_SUCCESS' );
		}

		try {
			return $this->client->payments->create( request: $request )->getPayment();
		} catch ( SquareException $e ) {
			throw $this->convert_exception( $e, 'square-create-payment-failed' );
		}
	}

	/**
	 * Charge a saved card-on-file (card ID typically starts with 'ccof:…').
	 *
	 * @param Order  $order
	 * @param string $card_id         Square Card ID stored in the StoreEngine payment token.
	 * @param string $customer_id     Square Customer ID the card belongs to.
	 * @param string $idempotency_key
	 *
	 * @return Payment
	 * @throws StoreEngineException
	 */
	public function create_payment_with_saved_card(
		Order $order,
		string $card_id,
		string $customer_id,
		string $idempotency_key
	): Payment {
		$this->assert_client();

		$request = new CreatePaymentRequest( [
			'idempotencyKey' => $idempotency_key,
			'sourceId'       => $card_id,
			'customerId'     => $customer_id,
			'amountMoney'    => new Money( [
				'amount'   => self::get_square_amount(
					(float) $order->get_total( 'square_payment' ),
					$order->get_currency()
				),
				'currency' => strtoupper( $order->get_currency() ),
			] ),
			'autocomplete' => true,
			'locationId'   => $this->location_id,
			'referenceId'  => (string) $order->get_id(),
		] );

		try {
			return $this->client->payments->create( request: $request )->getPayment();
		} catch ( SquareException $e ) {
			throw $this->convert_exception( $e, 'square-saved-card-payment-failed' );
		}
	}

	/**
	 * Retrieve a Square payment by its ID.
	 *
	 * @param string $payment_id
	 *
	 * @return Payment
	 * @throws StoreEngineException
	 */
	public function get_payment( string $payment_id ): Payment {
		$this->assert_client();

		try {
			return $this->client->payments->get( paymentId: $payment_id )->getPayment();
		} catch ( SquareException $e ) {
			throw $this->convert_exception( $e, 'square-get-payment-failed' );
		}
	}

	// ── Refund ────────────────────────────────────────────────────────────────

	/**
	 * Refund a Square payment (full or partial).
	 *
	 * @param string $payment_id   Square Payment ID saved on the order (_square_payment_id).
	 * @param float  $amount       Refund amount in major currency unit (e.g. 9.99 for $9.99).
	 * @param string $currency     ISO 4217 currency code.
	 * @param string $reason       Optional reason (max 192 chars per Square).
	 *
	 * @return \Square\Types\PaymentRefund
	 * @throws StoreEngineException
	 */
	public function refund_payment( string $payment_id, float $amount, string $currency, string $reason = '' ): \Square\Types\PaymentRefund {
		$this->assert_client();

		$args = [
			'idempotencyKey' => $this->generate_idempotency_key( 'refund_' . $payment_id . '_' . $amount ),
			'paymentId'      => $payment_id,
			'amountMoney'    => new Money( [
				'amount'   => self::get_square_amount( $amount, $currency ),
				'currency' => strtoupper( $currency ),
			] ),
		];

		if ( $reason ) {
			$args['reason'] = sanitize_text_field( mb_substr( $reason, 0, 192 ) );
		}

		try {
			return $this->client->refunds->refundPayment( request: new RefundPaymentRequest( $args ) )
			                             ->getRefund();
		} catch ( SquareException $e ) {
			throw $this->convert_exception( $e, 'square-refund-failed' );
		}
	}

	// ── Customer operations ───────────────────────────────────────────────────

	/**
	 * Return an existing Square Customer ID for the WP user, or create one.
	 *
	 * The ID is cached in WP user-meta (_square_customer_id) so we only hit
	 * the Square API once per user.
	 *
	 * @param int    $user_id  WP user ID (0 for guests — no caching).
	 * @param string $email
	 * @param string $name     Full name (split into given/family on first space).
	 *
	 * @return string  Square Customer ID.
	 * @throws StoreEngineException
	 */
	public function get_or_create_customer( int $user_id, string $email, string $name = '' ): string {
		$this->assert_client();

		// Serve from user-meta cache for logged-in users.
		if ( $user_id ) {
			$cached = get_user_meta( $user_id, '_square_customer_id', true );
			if ( $cached ) {
				return (string) $cached;
			}
		}

		$args = [
			'idempotencyKey' => $this->generate_idempotency_key( 'customer_' . $user_id . '_' . md5( $email ) ),
			'emailAddress'   => sanitize_email( $email ),
		];

		if ( $name ) {
			$parts             = explode( ' ', trim( $name ), 2 );
			$args['givenName']  = $parts[0];
			$args['familyName'] = $parts[1] ?? '';
		}

		try {
			$customer    = $this->client->customers->create( request: new CreateCustomerRequest( $args ) )->getCustomer();
			$customer_id = $customer->getId();

			// Cache in user-meta for future calls.
			if ( $user_id && $customer_id ) {
				update_user_meta( $user_id, '_square_customer_id', $customer_id );
			}

			return (string) $customer_id;
		} catch ( SquareException $e ) {
			throw $this->convert_exception( $e, 'square-create-customer-failed' );
		}
	}

	// ── Card-on-file operations ───────────────────────────────────────────────

	/**
	 * Save a card-on-file for a Square customer from a nonce.
	 *
	 * This is called on the "Add Payment Method" page.
	 * The nonce comes from Square.js (single use) — never stored server-side.
	 *
	 * @param string $customer_id      Square Customer ID.
	 * @param string $source_id        Nonce from Square.js.
	 * @param string $idempotency_key
	 *
	 * @return Card  The saved Square Card object (contains id, last_4, card_brand, etc.).
	 * @throws StoreEngineException
	 */
	public function create_card( string $customer_id, string $source_id, string $idempotency_key ): Card {
		$this->assert_client();

		$request = new CreateCardRequest( [
			'idempotencyKey' => $idempotency_key,
			'sourceId'       => $source_id,
			'card'           => new Card( [
				'customerId' => $customer_id,
			] ),
		] );

		try {
			return $this->client->cards->create( request: $request )->getCard();
		} catch ( SquareException $e ) {
			throw $this->convert_exception( $e, 'square-create-card-failed' );
		}
	}

	/**
	 * Disable (delete) a saved card. Square cards cannot be permanently deleted,
	 * only disabled — they can no longer be used after this call.
	 *
	 * @param string $card_id  Square Card ID.
	 *
	 * @return bool
	 */
	public function disable_card( string $card_id ): bool {
		try {
			$this->assert_client();
			$this->client->cards->disable( cardId: $card_id );

			return true;
		} catch ( SquareException | StoreEngineException $e ) {
			Helper::log_error( $e );

			return false;
		}
	}

	// ── Credential validation ─────────────────────────────────────────────────

	/**
	 * Validate credentials by fetching the given location from Square.
	 * Called during admin settings save (verify_config on GatewaySquare).
	 *
	 * @param string $access_token
	 * @param string $location_id
	 * @param bool   $is_live
	 *
	 * @return true|WP_Error
	 */
	public static function validate_credentials( string $access_token, string $location_id, bool $is_live ): true|WP_Error {
		try {
			$client = new SquareClient(
				token  : $access_token,
				options: [
					'baseUrl' => $is_live
						? Environments::Production->value
						: Environments::Sandbox->value,
				]
			);

			$client->locations->get(
				new GetLocationsRequest( [ 'locationId' => $location_id ] )
			);

			return true;
		} catch ( SquareException $e ) {
			$first_error = $e->getErrors()[0] ?? null;
			$message     = $first_error?->getDetail() ?? $e->getMessage();

			return new WP_Error( 'square-invalid-credentials', esc_html( $message ) );
		}
	}

	// ── Amount conversion ─────────────────────────────────────────────────────

	/**
	 * Convert a decimal amount to Square's smallest denomination integer.
	 *
	 * Square always requires amounts as integers:
	 *   USD 9.99  → 999  (cents)
	 *   JPY 500   → 500  (yen, zero-decimal — no conversion)
	 *
	 * @param float  $amount
	 * @param string $currency  ISO 4217 currency code.
	 *
	 * @return int
	 */
	public static function get_square_amount( float $amount, string $currency = '' ): int {
		$currency = strtoupper( $currency ?: Formatting::get_currency() );

		if ( in_array( $currency, self::$zero_decimal_currencies, true ) ) {
			return (int) round( $amount );
		}

		return (int) round( $amount * 100 );
	}

	// ── Static helpers ────────────────────────────────────────────────────────

	public static function get_supported_currencies(): array {
		return self::$supported_currencies;
	}

	public static function get_minimum_amount( string $currency = '' ): float {
		$currency = strtoupper( $currency ?: Formatting::get_currency() );

		return self::$currency_minimum_charges[ $currency ] ?? 1.00;
	}

	/**
	 * Generate a deterministic idempotency key scoped to this site.
	 * Square requires keys ≤ 45 characters.
	 *
	 * @param string $seed  Unique seed string (e.g. 'payment_42_nonce_abc').
	 *
	 * @return string  45-char alphanumeric key.
	 */
	public function generate_idempotency_key( string $seed ): string {
		return substr( md5( home_url() . '_' . $seed ), 0, 45 );
	}

	// ── Accessors (used by Hooks to inject JS params) ─────────────────────────

	public function get_application_id(): string {
		return $this->application_id;
	}

	public function get_location_id(): string {
		return $this->location_id;
	}

	public function is_live(): bool {
		return $this->is_live;
	}

	// ── Internal helpers ──────────────────────────────────────────────────────

	/**
	 * Guard: throw if the SDK client was not initialised (missing access token).
	 *
	 * @throws StoreEngineException
	 */
	private function assert_client(): void {
		if ( ! $this->client instanceof SquareClient ) {
			throw new StoreEngineException(
				esc_html__( 'Square is not configured. Please enter your credentials in StoreEngine → Payments → Square.', 'storeengine-square' ),
				'square-client-not-initialised',
				null,
				503
			);
		}
	}

	/**
	 * Build a Square Address object from a StoreEngine Order.
	 *
	 * @param Order $order
	 *
	 * @return Address
	 */
	private function build_address( Order $order ): Address {
		$args = array_filter( [
			'addressLine1'                 => $order->get_billing_address_1(),
			'addressLine2'                 => $order->get_billing_address_2(),
			'locality'                     => $order->get_billing_city(),
			'administrativeDistrictLevel1' => $order->get_billing_state(),
			'postalCode'                   => $order->get_billing_postcode(),
			'country'                      => $order->get_billing_country(),
			'firstName'                    => $order->get_billing_first_name(),
			'lastName'                     => $order->get_billing_last_name(),
		] );

		return new Address( $args );
	}

	/**
	 * Convert a SquareException into a StoreEngineException.
	 *
	 * The SquareException wraps one or more Square API Error objects.
	 * We surface the first error's detail string as the message.
	 *
	 * @param SquareException $e
	 * @param string          $code  Internal error code slug.
	 *
	 * @return StoreEngineException
	 */
	private function convert_exception( SquareException $e, string $code ): StoreEngineException {
		$errors       = $e->getErrors();
		$first        = $errors[0] ?? null;
		$message      = $first?->getDetail() ?? $e->getMessage();
		$http_status  = $e->getCode() ?: 400;

		Helper::log_error( $e );

		return new StoreEngineException(
			esc_html( $message ),
			$code,
			[
				'square_errors' => array_map(
					static fn( $err ) => [
						'category' => $err->getCategory(),
						'code'     => $err->getCode(),
						'detail'   => $err->getDetail(),
					],
					$errors
				),
			],
			(int) $http_status
		);
	}
}

// End of file SquareService.php.
