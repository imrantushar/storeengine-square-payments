<?php
/**
 * SquareService
 *
 * Wraps the official square/square Composer SDK (v40.x).
 * All Square API calls go through this class — no raw HTTP anywhere else.
 *
 * INSTALL
 *   composer require square/square  (^40.0)
 *
 * SDK NAMESPACE MAP (v40)
 *   Square\SquareClient                              main client
 *   Square\Environment                               Production / Sandbox string constants
 *   Square\Exceptions\ApiException                   all API-level errors
 *   Square\Models\Money                              { amount:int, currency:string }
 *   Square\Models\Address                            address value object
 *   Square\Models\Card                               card response object
 *   Square\Models\Payment                            payment response object
 *   Square\Models\CreatePaymentRequest               POST /v2/payments
 *   Square\Models\RefundPaymentRequest               POST /v2/refunds
 *   Square\Models\CreateCustomerRequest              POST /v2/customers
 *   Square\Models\CreateCardRequest                  POST /v2/cards
 *
 * CLIENT RESOURCE METHODS
 *   $client->getPaymentsApi()   PaymentsApi  (createPayment, getPayment, cancelPayment, completePayment)
 *   $client->getRefundsApi()    RefundsApi   (refundPayment, getPaymentRefund)
 *   $client->getCustomersApi()  CustomersApi (createCustomer, retrieveCustomer, searchCustomers, updateCustomer, deleteCustomer)
 *   $client->getCardsApi()      CardsApi     (createCard, retrieveCard, disableCard, listCards)
 *   $client->getLocationsApi()  LocationsApi (retrieveLocation, listLocations, createLocation, updateLocation)
 *
 * @package StoreEngineSquare
 */

namespace StoreEngineSquare;

use Square\Environment;
use Square\Exceptions\ApiException;
use Square\Models\Address;
use Square\Models\Card;
use Square\Models\CreateCardRequest;
use Square\Models\CreateCustomerRequest;
use Square\Models\CreatePaymentRequest;
use Square\Models\Money;
use Square\Models\Payment;
use Square\Models\PaymentRefund;
use Square\Models\RefundPaymentRequest;
use Square\SquareClient;
use StoreEngine\Classes\Exceptions\StoreEngineException;
use StoreEngine\Classes\Order;
use StoreEngine\Payment_Gateways;
use StoreEngine\Utils\Formatting;
use StoreEngine\Utils\Helper;
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
	 * The v40 SDK constructor signature:
	 *   new SquareClient( array $config )
	 *
	 * The 'environment' option accepts a value from the Environment class:
	 *   Environment::PRODUCTION  →  'production'
	 *   Environment::SANDBOX     →  'sandbox'
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

		$this->client = new SquareClient( [
			'accessToken' => $this->access_token,
			'environment' => $this->is_live ? Environment::PRODUCTION : Environment::SANDBOX,
		] );
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

		// Square's CreatePayment API does NOT have a "store card on file" flag —
		// the field setStorePaymentMethodInVault() does not exist.
		// Saving the card is a separate Cards::create() call after the payment
		// succeeds, using the payment ID as the sourceId (Square accepts a
		// completed payment ID in place of a nonce to create a card-on-file).
		//
		// We pass customerId here so Square links the charge to the customer record
		// (required when later charging that customer's card on file).
		$amount_money = new Money();
		$amount_money->setAmount( self::get_square_amount(
			(float) $order->get_total( 'square_payment' ),
			$order->get_currency()
		) );
		$amount_money->setCurrency( strtoupper( $order->get_currency() ) );

		$request = new CreatePaymentRequest( $source_id, $idempotency_key );
		$request->setAmountMoney( $amount_money );
		$request->setAutocomplete( true );
		$request->setLocationId( $this->location_id );
		$request->setReferenceId( (string) $order->get_id() );
		$request->setBuyerEmailAddress( $order->get_billing_email() );
		$request->setBillingAddress( $this->build_address( $order ) );
		$request->setNote( sprintf(
			/* translators: 1: site name 2: order ID */
			__( 'Payment for %1$s – Order #%2$s', 'storeengine-square-payments' ),
			get_bloginfo( 'name' ),
			$order->get_id()
		) );

		if ( $customer_id ) {
			$request->setCustomerId( $customer_id );
		}

		try {
			$response = $this->client->getPaymentsApi()->createPayment( $request );
			return $response->getResult()->getPayment();
		} catch ( ApiException $e ) {
			throw $this->convert_exception( $e, 'square-create-payment-failed' );
		}
	}

	/**
	 * Save a card-on-file from a completed payment.
	 *
	 * Called by GatewaySquare::process_payment() after a successful charge when
	 * the customer opted to save their card. Square accepts a completed payment ID
	 * as the sourceId for card creation — no separate nonce needed.
	 *
	 * Returns null silently on failure so that a card-save error never rolls back
	 * an otherwise successful payment.
	 *
	 * @param string $customer_id  Square Customer ID.
	 * @param string $payment_id   Square Payment ID from a completed charge.
	 *
	 * @return Card|null  The saved Card object, or null if saving failed.
	 */
	public function save_card_from_payment( string $customer_id, string $payment_id ): ?Card {
		if ( ! $customer_id || ! $payment_id ) {
			return null;
		}

		try {
			$idem_key = $this->generate_idempotency_key( 'card_from_payment_' . $payment_id );

			return $this->create_card( $customer_id, $payment_id, $idem_key );
		} catch ( StoreEngineException $e ) {
			// Card save failed — log but do NOT propagate.
			// The charge succeeded; the inability to save the card should
			// not appear as a payment failure to the customer.
			Helper::log_error( $e );

			return null;
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

		$amount_money = new Money();
		$amount_money->setAmount( self::get_square_amount(
			(float) $order->get_total( 'square_payment' ),
			$order->get_currency()
		) );
		$amount_money->setCurrency( strtoupper( $order->get_currency() ) );

		$request = new CreatePaymentRequest( $card_id, $idempotency_key );
		$request->setCustomerId( $customer_id );
		$request->setAmountMoney( $amount_money );
		$request->setAutocomplete( true );
		$request->setLocationId( $this->location_id );
		$request->setReferenceId( (string) $order->get_id() );

		try {
			$response = $this->client->getPaymentsApi()->createPayment( $request );
			return $response->getResult()->getPayment();
		} catch ( ApiException $e ) {
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
			$response = $this->client->getPaymentsApi()->getPayment( $payment_id );
			return $response->getResult()->getPayment();
		} catch ( ApiException $e ) {
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
	 * @return PaymentRefund
	 * @throws StoreEngineException
	 */
	public function refund_payment( string $payment_id, float $amount, string $currency, string $reason = '' ): PaymentRefund {
		$this->assert_client();

		$amount_money = new Money();
		$amount_money->setAmount( self::get_square_amount( $amount, $currency ) );
		$amount_money->setCurrency( strtoupper( $currency ) );

		$request = new RefundPaymentRequest(
			$this->generate_idempotency_key( 'refund_' . $payment_id . '_' . $amount ),
			$amount_money
		);
		$request->setPaymentId( $payment_id );

		if ( $reason ) {
			$request->setReason( sanitize_text_field( mb_substr( $reason, 0, 192 ) ) );
		}

		try {
			$response = $this->client->getRefundsApi()->refundPayment( $request );
			return $response->getResult()->getRefund();
		} catch ( ApiException $e ) {
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

		$idem_key = $this->generate_idempotency_key( 'customer_' . $user_id . '_' . md5( $email ) );

		$request = new CreateCustomerRequest();
		$request->setIdempotencyKey( $idem_key );
		$request->setEmailAddress( sanitize_email( $email ) );

		if ( $name ) {
			$parts = explode( ' ', trim( $name ), 2 );
			$request->setGivenName( $parts[0] );
			$request->setFamilyName( $parts[1] ?? '' );
		}

		try {
			$response    = $this->client->getCustomersApi()->createCustomer( $request );
			$customer    = $response->getResult()->getCustomer();
			$customer_id = $customer->getId();

			// Cache in user-meta for future calls.
			if ( $user_id && $customer_id ) {
				update_user_meta( $user_id, '_square_customer_id', $customer_id );
			}

			return (string) $customer_id;
		} catch ( ApiException $e ) {
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

		$card = new Card();
		$card->setCustomerId( $customer_id );

		$request = new CreateCardRequest( $idempotency_key, $source_id, $card );

		try {
			$response = $this->client->getCardsApi()->createCard( $request );
			return $response->getResult()->getCard();
		} catch ( ApiException $e ) {
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
			$this->client->getCardsApi()->disableCard( $card_id );

			return true;
		} catch ( ApiException | StoreEngineException $e ) {
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
	public static function validate_credentials( string $access_token, string $location_id, bool $is_live ) {
		try {
			$client = new SquareClient( [
				'accessToken' => $access_token,
				'environment' => $is_live ? Environment::PRODUCTION : Environment::SANDBOX,
			] );

			$client->getLocationsApi()->retrieveLocation( $location_id );

			return true;
		} catch ( ApiException $e ) {
			$errors  = [];
			if ( $e->hasResponse() ) {
				$body   = json_decode( $e->getHttpResponse()->getRawBody(), true );
				$errors = $body['errors'] ?? [];
			}
			$first   = $errors[0] ?? null;
			$message = isset( $first['detail'] ) ? $first['detail'] : $e->getMessage();

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
				esc_html__( 'Square is not configured. Please enter your credentials in StoreEngine → Payments → Square.', 'storeengine-square-payments' ),
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
		$address = new Address();

		if ( $order->get_billing_address_1() ) {
			$address->setAddressLine1( $order->get_billing_address_1() );
		}
		if ( $order->get_billing_address_2() ) {
			$address->setAddressLine2( $order->get_billing_address_2() );
		}
		if ( $order->get_billing_city() ) {
			$address->setLocality( $order->get_billing_city() );
		}
		if ( $order->get_billing_state() ) {
			$address->setAdministrativeDistrictLevel1( $order->get_billing_state() );
		}
		if ( $order->get_billing_postcode() ) {
			$address->setPostalCode( $order->get_billing_postcode() );
		}
		if ( $order->get_billing_country() ) {
			$address->setCountry( $order->get_billing_country() );
		}
		if ( $order->get_billing_first_name() ) {
			$address->setFirstName( $order->get_billing_first_name() );
		}
		if ( $order->get_billing_last_name() ) {
			$address->setLastName( $order->get_billing_last_name() );
		}

		return $address;
	}

	/**
	 * Convert an ApiException into a StoreEngineException.
	 *
	 * The ApiException wraps an HTTP response whose body contains one or more
	 * Square API Error objects in JSON form. We surface the first error's detail
	 * string as the message.
	 *
	 * @param ApiException $e
	 * @param string       $code  Internal error code slug.
	 *
	 * @return StoreEngineException
	 */
	private function convert_exception( ApiException $e, string $code ): StoreEngineException {
		$errors      = [];
		$http_status = $e->getCode() ?: 400;

		if ( $e->hasResponse() ) {
			$body   = json_decode( $e->getHttpResponse()->getRawBody(), true );
			$errors = isset( $body['errors'] ) && is_array( $body['errors'] ) ? $body['errors'] : [];
		}

		$first   = $errors[0] ?? null;
		$message = isset( $first['detail'] ) ? $first['detail'] : $e->getMessage();

		Helper::log_error( $e );

		return new StoreEngineException(
			esc_html( $message ),
			$code,
			[
				'square_errors' => array_map(
					static function ( $err ) {
						return [
							'category' => $err['category'] ?? '',
							'code'     => $err['code'] ?? '',
							'detail'   => $err['detail'] ?? '',
						];
					},
					$errors
				),
			],
			(int) $http_status
		);
	}
}

// End of file SquareService.php.
