<?php
/**
 * GatewaySquare
 *
 * Integrates Square with StoreEngine's payment gateway system.
 * Extends the abstract PaymentGateway using the same pattern as GatewayStripe.
 *
 * All Square API calls are delegated to SquareService, which uses the official
 * square/square Composer SDK.
 *
 * @package StoreEngineSquare
 */

namespace StoreEngineSquare;

use Square\Models\Card;
use Square\Models\Payment;
use StoreEngine\Addons\Subscription\Classes\SubscriptionCollection;
use StoreEngine\Classes\Exceptions\StoreEngineException;
use StoreEngine\Classes\Exceptions\StoreEngineInvalidOrderStatusException;
use StoreEngine\Classes\Exceptions\StoreEngineInvalidOrderStatusTransitionException;
use StoreEngine\Classes\Order;
use StoreEngine\Classes\OrderContext;
use StoreEngine\Classes\OrderStatus\Completed;
use StoreEngine\Classes\OrderStatus\OrderStatus;
use StoreEngine\Classes\PaymentTokens\PaymentToken;
use StoreEngine\Classes\PaymentTokens\PaymentTokens;
use StoreEngine\Payment\Gateways\PaymentGateway;
use StoreEngine\Utils\Formatting;
use StoreEngine\Utils\Helper;
use Throwable;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GatewaySquare extends PaymentGateway {

	public int $index = 10;

	public function __construct() {
		$this->setup();
		$this->init_admin_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->saved_cards = (bool) $this->get_option( 'saved_cards', false );

		// Registers both subscription and installment-plan scheduled payment hooks.
		$this->register_subscription_hooks();
		$this->tokenization_script();
	}

	// ── Setup ─────────────────────────────────────────────────────────────────

	protected function setup(): void {
		$this->id                 = 'square';
		$this->icon               = apply_filters(
			'storeengine/square_icon',
			SE_SQUARE_URL . 'assets/images/squareup.svg'
		);
		$this->method_title       = __( 'Square', 'storeengine-square' );
		$this->method_description = __( 'Accept payments securely via Square Web Payments SDK. Card data never touches your server.', 'storeengine-square' );
		$this->has_fields         = true;
		$this->verify_config      = true;
		$this->supports           = [
			'products',
			'refunds',
			// Saved cards.
			'tokenization',
			'add_payment_method',
			// Subscriptions & installments — StoreEngine scheduler drives billing,
			// Square card-on-file handles the off-session charge each renewal.
			'subscriptions',
			'multiple_subscriptions',
			'subscription_cancellation',
			'subscription_reactivation',
			'subscription_suspension',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscriptions_automatic_payments',
			'subscription_payment_method_change_admin',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change',
			// Installments follow the same renewal flow as subscriptions.
			'gateway_scheduled_payments',
		];
	}

	// ── Admin settings fields ─────────────────────────────────────────────────

	protected function init_admin_fields(): void {
		$this->admin_fields = [
			'title'       => [
				'label'    => __( 'Title', 'storeengine-square' ),
				'type'     => 'safe_text',
				'tooltip'  => __( 'Payment method title shown to customers at checkout.', 'storeengine-square' ),
				'default'  => __( 'Credit / Debit Card (Square)', 'storeengine-square' ),
				'priority' => 0,
			],
			'description' => [
				'label'    => __( 'Description', 'storeengine-square' ),
				'type'     => 'textarea',
				'tooltip'  => __( 'Description shown below the payment method title.', 'storeengine-square' ),
				'priority' => 1,
			],
			'is_production' => [
				'label'    => __( 'Live Mode', 'storeengine-square' ),
				'tooltip'  => __( 'Enable Square production mode. Disable for Sandbox.', 'storeengine-square' ),
				'type'     => 'checkbox',
				'default'  => false,
				'priority' => 2,
			],

			// ── Live credentials ──────────────────────────────────────────────
			'application_id' => [
				'label'      => __( 'Application ID', 'storeengine-square' ),
				'type'       => 'text',
				'tooltip'    => __( 'From Square Developer Dashboard → Applications → Credentials.', 'storeengine-square' ),
				'priority'   => 3,
				'dependency' => [ 'is_production' => true ],
				'required'   => true,
			],
			'access_token'   => [
				'label'      => __( 'Access Token', 'storeengine-square' ),
				'type'       => 'password',
				'tooltip'    => __( 'Production access token from Square Developer Dashboard.', 'storeengine-square' ),
				'priority'   => 4,
				'dependency' => [ 'is_production' => true ],
				'required'   => true,
			],
			'location_id'    => [
				'label'      => __( 'Location ID', 'storeengine-square' ),
				'type'       => 'text',
				'tooltip'    => __( 'Square Location payments will be attributed to.', 'storeengine-square' ),
				'priority'   => 5,
				'dependency' => [ 'is_production' => true ],
				'required'   => true,
			],

			// ── Sandbox credentials ───────────────────────────────────────────
			'sandbox_application_id' => [
				'label'      => __( 'Sandbox Application ID', 'storeengine-square' ),
				'type'       => 'text',
				'priority'   => 3,
				'dependency' => [ 'is_production' => false ],
				'required'   => true,
			],
			'sandbox_access_token'   => [
				'label'      => __( 'Sandbox Access Token', 'storeengine-square' ),
				'type'       => 'password',
				'priority'   => 4,
				'dependency' => [ 'is_production' => false ],
				'required'   => true,
			],
			'sandbox_location_id'    => [
				'label'      => __( 'Sandbox Location ID', 'storeengine-square' ),
				'type'       => 'text',
				'priority'   => 5,
				'dependency' => [ 'is_production' => false ],
				'required'   => true,
			],

			// ── Feature flags ─────────────────────────────────────────────────
			'saved_cards' => [
				'title'       => __( 'Saved Cards', 'storeengine-square' ),
				'label'       => __( 'Enable payment via saved cards', 'storeengine-square' ),
				'type'        => 'checkbox',
				'description' => __( 'Allow logged-in customers to save cards via Square card-on-file.', 'storeengine-square' ),
				'default'     => false,
				'priority'    => 6,
			],
		];
	}

	// ── Credential verification (called on settings save) ─────────────────────

	/**
	 * @param array $config
	 *
	 * @throws StoreEngineException
	 */
	public function verify_config( array $config ): void {
		$is_live     = (bool) ( $config['is_production'] ?? false );
		$prefix      = $is_live ? '' : 'sandbox_';
		$app_id      = $config[ $prefix . 'application_id' ] ?? '';
		$token       = $config[ $prefix . 'access_token' ]   ?? '';
		$location_id = $config[ $prefix . 'location_id' ]    ?? '';

		if ( ! $app_id ) {
			throw new StoreEngineException(
				esc_html__( 'Square Application ID is required.', 'storeengine-square' ),
				'square-application-id-required',
				null, 400
			);
		}

		if ( ! $token ) {
			throw new StoreEngineException(
				esc_html__( 'Square Access Token is required.', 'storeengine-square' ),
				'square-access-token-required',
				null, 400
			);
		}

		if ( ! $location_id ) {
			throw new StoreEngineException(
				esc_html__( 'Square Location ID is required.', 'storeengine-square' ),
				'square-location-id-required',
				null, 400
			);
		}

		if ( ! $this->is_currency_supported() ) {
			throw new StoreEngineException(
				sprintf(
					esc_html__( 'Your store currency (%1$s) is not supported by Square. See %2$sSquare supported currencies%3$s.', 'storeengine-square' ),
					esc_html( Formatting::get_currency() ),
					'<a href="https://developer.squareup.com/docs/build-basics/working-with-monetary-amounts#countries-and-currencies" target="_blank">',
					'</a>'
				),
				'square-currency-not-supported',
				null, 400
			);
		}

		// Live API call — uses the official SDK internally.
		$result = SquareService::validate_credentials( $token, $location_id, $is_live );

		if ( is_wp_error( $result ) ) {
			throw new StoreEngineException(
				esc_html( $result->get_error_message() ),
				'square-invalid-credentials',
				null, 400
			);
		}
	}

	// ── Availability ──────────────────────────────────────────────────────────

	public function is_available(): bool {
		if ( Helper::is_add_payment_method_page() && ! $this->saved_cards ) {
			return false;
		}

		if ( ! Helper::is_dashboard() && \storeengine()->cart ) {
			if ( ! $this->is_currency_supported() ) {
				return false;
			}
		}

		if ( $this->get_option( 'is_production', false ) && ! is_ssl() ) {
			return false; // No live payments over plain HTTP.
		}

		return parent::is_available();
	}

	public function is_currency_supported( string $currency = '' ): bool {
		return in_array(
			strtoupper( $currency ?: Formatting::get_currency() ),
			SquareService::get_supported_currencies(),
			true
		);
	}

	// ── Checkout UI ───────────────────────────────────────────────────────────

	public function payment_fields(): void {
		$description          = (string) $this->get_description();

		// Add sandbox notice.
		if ( ! $this->get_option( 'is_production', false ) ) {
			$description .= PHP_EOL . '<h4>' . esc_html__( 'Sandbox Mode Active', 'storeengine-square' ) . '</h4>';
			$description .= PHP_EOL . '<p>' . sprintf(
				esc_html__( 'Use test card %1$s, any future expiry, any CVC. See all %2$sSquare test cards%3$s.', 'storeengine-square' ),
				'<code>4111 1111 1111 1111</code>',
				'<a href="https://developer.squareup.com/docs/devtools/sandbox/payments#test-payment-values" target="_blank" rel="noopener">',
				'</a>'
			) . '</p>';
		}

		ob_start();
		?>
		<div class="storeengine-payment-method-description storeengine-mb-4">
			<?php echo wpautop( wptexturize( $description ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php
		if ( $this->maybe_display_tokenization() ) {
			$this->saved_payment_methods();
		}
		?>
		<fieldset id="storeengine-square-card-form"
			class="storeengine-square-form storeengine-payment-form storeengine-mt-4"
			style="background:transparent;border:none;padding:0;">
			<!-- Square Web Payments SDK mounts the card widget here. -->
			<div id="storeengine-square-card-element" class="storeengine-square-card-element"></div>
			<div id="storeengine-square-card-errors" class="storeengine-square-errors" role="alert"></div>
		</fieldset>
		<?php
		if ( $this->is_saved_cards_enabled() ) {
			// Force the save checkbox when the cart contains a subscription or
			// installment — the customer must save their card so renewals can
			// be charged automatically without browser interaction.
			$this->save_payment_method_checkbox( $this->maybe_force_save_payment() );
		}

		ob_end_flush();
	}

	// ── Minimum order validation ──────────────────────────────────────────────

	public function validate_minimum_order_amount( Order $order ): void {
		$minimum = SquareService::get_minimum_amount( $order->get_currency() );

		if ( (float) $order->get_total() < $minimum ) {
			throw new StoreEngineException(
				wp_kses_post(
					sprintf(
						__( 'The minimum order total to use Square is %s.', 'storeengine-square' ),
						Formatting::price( $minimum )
					)
				),
				'square-minimum-amount-not-met',
				null, 400
			);
		}
	}

	// ── process_payment ───────────────────────────────────────────────────────

	/**
	 * Main payment handler called by StoreEngine when the customer places an order.
	 *
	 * The nonce (square_payment_token) comes from Square.js on the client
	 * and is a single-use token — card data never passes through your server.
	 *
	 * @param Order $order
	 *
	 * @return array|WP_Error  ['result' => 'success', 'redirect' => url] on success.
	 * @throws StoreEngineException
	 */
	public function process_payment( Order $order ) {
		$this->validate_minimum_order_amount( $order );

		$service       = SquareService::init( $this );
		$order_context = new OrderContext( $order->get_status() );

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$source_id      = sanitize_text_field( wp_unslash( $_POST['square_payment_token'] ?? '' ) );
		$selected_token = $this->get_selected_token_from_request();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$save_card = $this->should_force_save_payment( $order );

		// Subscriptions and installments MUST save the card so that StoreEngine's
		// scheduler can charge it off-session on every renewal.
		$has_subscription = Helper::cart() && Helper::cart()->get_meta( 'has_subscription' );
		if ( $has_subscription || $this->maybe_force_save_payment() ) {
			$save_card = true;
		}

		// Tracks which path was taken so the card-save block below behaves correctly:
		//   Path A (saved token)  → $using_saved = true,  card_id = token itself (ccof:…)
		//   Path B (new nonce)    → $using_saved = false, card_id set after Cards::create()
		$using_saved = false;
		$card_id     = '';       // populated per-path before meta storage

		try {
			// ── Path A: Saved card-on-file ─────────────────────────────────────
			if ( $selected_token && 'new' !== $selected_token ) {
				$token = PaymentTokens::get_token( absint( $selected_token ) );

				if ( ! $token instanceof PaymentToken ) {
					throw new StoreEngineException(
						esc_html__( 'Saved payment token not found.', 'storeengine-square' ),
						'square-token-not-found',
						null, 404
					);
				}

				$customer_id = (string) get_user_meta( get_current_user_id(), '_square_customer_id', true );

				// If customer_id is missing (e.g. old order before this version),
				// fall back to creating/retrieving from Square.
				if ( ! $customer_id && is_user_logged_in() ) {
					$customer_id = $service->get_or_create_customer(
						get_current_user_id(),
						$order->get_billing_email(),
						trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() )
					);
				}

				$idem_key    = $service->generate_idempotency_key( 'saved_' . $order->get_id() . '_' . $token->get_token() );

				/** @var Payment $square_payment */
				$square_payment = $service->create_payment_with_saved_card(
					$order,
					$token->get_token(),
					$customer_id,
					$idem_key
				);

				// The token IS the reusable card-on-file ID (ccof:…).
				// Store it now so the meta-save block below uses it directly
				// and never falls through to the ephemeral payment-response card.
				$card_id     = $token->get_token();
				$using_saved = true;

			// ── Path B: New card nonce from Square.js ──────────────────────────
			} else {
				if ( ! $source_id ) {
					throw new StoreEngineException(
						esc_html__( 'Square payment token is missing. Please re-enter your card details.', 'storeengine-square' ),
						'square-nonce-missing',
						null, 400
					);
				}

				// Create (or retrieve cached) Square Customer for logged-in users.
				$customer_id = '';
				if ( is_user_logged_in() && ( $save_card || $this->saved_cards ) ) {
					$customer_id = $service->get_or_create_customer(
						get_current_user_id(),
						$order->get_billing_email(),
						trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() )
					);
				}

				$idem_key = $service->generate_idempotency_key( 'new_' . $order->get_id() . '_' . $source_id );

				/** @var Payment $square_payment */
				$square_payment = $service->create_payment(
					$order,
					$source_id,
					$idem_key,
					$save_card && (bool) $customer_id,
					$customer_id
				);

				// Card saving happens below after payment_id is known.
			}

			// ── Payment status check ───────────────────────────────────────────
			$status = $square_payment->getStatus() ?? '';

			if ( 'COMPLETED' !== $status ) {
				$order->update_status(
					OrderStatus::PAYMENT_FAILED,
					sprintf(
						/* translators: %s: Square payment status */
						__( 'Square payment failed. Status: %s', 'storeengine-square' ),
						esc_html( $status )
					)
				);

				return new WP_Error(
					'square-payment-not-completed',
					sprintf( __( 'Payment not completed. Square returned status: %s', 'storeengine-square' ), esc_html( $status ) )
				);
			}

			// ── Record payment data on the order ───────────────────────────────
			$payment_id      = (string) $square_payment->getId();
			$receipt_url     = (string) $square_payment->getReceiptUrl();
			$card_details    = $square_payment->getCardDetails();
			$card            = $card_details ? $card_details->getCard() : null;
			$card_brand      = (string) ( $card ? $card->getCardBrand() : '' );
			$last4           = (string) ( $card ? $card->getLast4() : '' );
			$processing_fees = $square_payment->getProcessingFee();
			$first_fee       = isset( $processing_fees[0] ) ? $processing_fees[0] : null;
			$fee_money       = $first_fee ? $first_fee->getAmountMoney() : null;
			$fee_amount      = (int) ( $fee_money ? $fee_money->getAmount() : 0 );
			$sq_customer     = (string) $square_payment->getCustomerId();

			$order->set_transaction_id( $payment_id );
			$order->set_paid_status( 'paid' );
			$order->add_meta_data( '_square_payment_id',      $payment_id,  true );
			$order->add_meta_data( '_square_receipt_url',     $receipt_url, true );
			$order->add_meta_data( '_square_card_brand',      $card_brand,  true );
			$order->add_meta_data( '_square_card_last4',      $last4,       true );
			$order->add_meta_data( '_square_processing_fee',  $fee_amount,  true );

			// ── Save card on-file — Path B only (correct Square flow) ──────────
			//
			// Square's CreatePayment API has NO "store card" flag.
			// The correct flow is a separate Cards::create() call after the payment
			// succeeds, using the completed payment ID as the sourceId.
			//
			// Path A skips this block entirely: $card_id is already set from
			// $token->get_token() above — that IS the real ccof:… card-on-file ID.
			// Calling save_card_from_payment() again in Path A would attempt to
			// re-vault a card that is already saved, wasting an API call and
			// potentially creating a duplicate token.
			if ( ! $using_saved && $save_card && is_user_logged_in() && $sq_customer ) {
				$saved_card = $service->save_card_from_payment( $sq_customer, $payment_id );
				if ( $saved_card ) {
					$card_id   = (string) $saved_card->getId();
					$card_data = [
						'id'          => $saved_card->getId(),
						'last_4'      => $saved_card->getLast4(),
						'card_brand'  => $saved_card->getCardBrand(),
						'exp_month'   => $saved_card->getExpMonth(),
						'exp_year'    => $saved_card->getExpYear(),
						'fingerprint' => $saved_card->getFingerprint(),
					];
					if ( ! SquarePaymentTokens::find_duplicate( $card_data, get_current_user_id(), $this->id ) ) {
						$this->create_payment_token( get_current_user_id(), $card_data );
					}
				}
			}

			// $card_id at this point:
			//   Path A → set from $token->get_token() — always a real ccof:… ID
			//   Path B → set from save_card_from_payment() if card was saved,
			//            otherwise empty (guest checkout / save not requested)
			// DO NOT fall back to getCardDetails()->getCard()->getId() — that returns
			// an ephemeral card object embedded in the payment response, not a
			// reusable card-on-file ID. Storing it as _square_source_id would cause
			// every subsequent renewal charge to fail with CARD_TOKEN_USED.

			// _square_source_id is read by process_scheduled_payment() for every
			// subscription renewal / installment — must be a reusable ccof:… ID.
			if ( $card_id ) {
				$order->add_meta_data( '_square_source_id', $card_id, true );
			}

			if ( $sq_customer ) {
				$order->add_meta_data( '_square_customer_id', $sq_customer, true );
			}

			// Copy customer ID + card ID onto the subscription record so renewal
			// orders created by the scheduler inherit them automatically.
			$this->maybe_update_source_on_subscription_order( $order, $sq_customer, $card_id );

			$order_context->proceed_to_next_status( 'process_order', $order, [
				'note' => sprintf(
					/* translators: 1: payment ID 2: card brand 3: last 4 digits */
					__( 'Square charge complete. Payment ID: %1$s. Card: %2$s ending %3$s.', 'storeengine-square' ),
					$payment_id,
					$card_brand,
					$last4
				),
				'transaction_id' => $payment_id,
			] );

			$order->save();

			/**
			 * Fires after a successful Square payment.
			 *
			 * @param Payment $square_payment  Full Square Payment object (SDK type).
			 * @param Order   $order           StoreEngine order.
			 */
			do_action( 'storeengine/square/after_payment', $square_payment, $order );

			return [
				'result'   => 'success',
				'redirect' => $order->get_checkout_order_received_url(),
			];

		} catch ( StoreEngineException $e ) {
			$order->update_status(
				OrderStatus::PAYMENT_FAILED,
				sprintf( __( 'Payment failed. Error: %s', 'storeengine-square' ), $e->getMessage() )
			);
			throw $e;

		} catch ( Throwable $e ) {
			$order->update_status(
				OrderStatus::PAYMENT_FAILED,
				sprintf( __( 'Payment failed. Error: %s', 'storeengine-square' ), $e->getMessage() )
			);
			throw StoreEngineException::convert_exception( $e, 'square-payment-error' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	// ── process_refund ────────────────────────────────────────────────────────

	/**
	 * @param int        $order_id
	 * @param float|null $amount
	 * @param string     $reason
	 *
	 * @return bool|WP_Error
	 */
	public function process_refund( int $order_id, $amount = null, string $reason = '' ) {
		$order = Helper::get_order( $order_id );

		if ( is_wp_error( $order ) || ! $order instanceof Order ) {
			return new WP_Error( 'invalid-order', __( 'Invalid order.', 'storeengine-square' ) );
		}

		$amount = abs( (float) ( $amount ?? 0 ) );
		if ( '0.00' === sprintf( '%0.2f', $amount ) ) {
			return true; // Zero-amount refund is a no-op.
		}

		$payment_id = $order->get_transaction_id();

		if ( ! $payment_id ) {
			return new WP_Error(
				'square-missing-payment-id',
				__( 'No Square Payment ID found on this order.', 'storeengine-square' )
			);
		}

		try {
			$refund = SquareService::init( $this )->refund_payment(
				$payment_id,
				(float) $amount,
				$order->get_currency(),
				$reason
			);

			// Square refund statuses: COMPLETED, PENDING, REJECTED, FAILED.
			$succeeded = in_array( $refund->getStatus(), [ 'COMPLETED', 'PENDING' ], true );

			if ( $succeeded ) {
				$order->add_meta_data( '_square_refund_id', (string) $refund->getId(), false );
				$order->save();
			}

			return $succeeded;

		} catch ( StoreEngineException $e ) {
			Helper::log_error( $e );

			return $e->toWpError();

		} catch ( Throwable $e ) {
			Helper::log_error( $e );

			return new WP_Error(
				'square-refund-error',
				esc_html( sprintf( __( 'Refund error: %s', 'storeengine-square' ), $e->getMessage() ) )
			);
		}
	}

	// ── add_payment_method ────────────────────────────────────────────────────

	/**
	 * Called from the "Add Payment Method" page.
	 *
	 * @param array $payload  Sanitised request payload (from Ajax::save_card).
	 *
	 * @return array
	 * @throws StoreEngineException
	 */
	public function add_payment_method( array $payload ): array {
		if ( ! is_user_logged_in() ) {
			throw new StoreEngineException(
				esc_html__( 'You must be logged in to save a payment method.', 'storeengine-square' ),
				'square-not-logged-in',
				null, 401
			);
		}

		$source_id = sanitize_text_field( $payload['square_payment_token'] ?? '' );

		if ( ! $source_id ) {
			throw new StoreEngineException(
				esc_html__( 'Square payment token is missing.', 'storeengine-square' ),
				'square-nonce-missing',
				null, 400
			);
		}

		$user    = wp_get_current_user();
		$service = SquareService::init( $this );

		// Get or create a Square Customer for this WP user.
		$customer_id = $service->get_or_create_customer(
			$user->ID,
			$user->user_email,
			trim( $user->first_name . ' ' . $user->last_name )
		);

		$idem_key = $service->generate_idempotency_key( 'add_card_' . $user->ID . '_' . $source_id );

		/** @var Card $card  Square Card SDK object */
		$card = $service->create_card( $customer_id, $source_id, $idem_key );

		if ( ! $card->getId() ) {
			throw new StoreEngineException(
				esc_html__( 'Failed to save card with Square.', 'storeengine-square' ),
				'square-card-save-failed'
			);
		}

		// Duplicate check — same fingerprint already saved?
		$card_data = [
			'id'          => $card->getId(),
			'last_4'      => $card->getLast4(),
			'card_brand'  => $card->getCardBrand(),
			'exp_month'   => $card->getExpMonth(),
			'exp_year'    => $card->getExpYear(),
			'fingerprint' => $card->getFingerprint(),
		];

		$existing = SquarePaymentTokens::find_duplicate( $card_data, $user->ID, $this->id );

		if ( $existing ) {
			$existing->set_token( $card->getId() );
			$existing->save();
			$token = $existing;
			$found = true;
		} else {
			$token = $this->create_payment_token( $user->ID, $card_data );
			$found = false;
		}

		do_action( 'storeengine/square/add_payment_method', $user->ID, $card );

		return [
			'result'   => 'success',
			'redirect' => Helper::get_account_endpoint_url( 'payment-methods' ),
			'found'    => $found,
			'message'  => $found
				? __( 'Duplicate payment method — updated.', 'storeengine-square' )
				: __( 'Payment method saved successfully.', 'storeengine-square' ),
			'token'    => $token->get_id(),
			'last4'    => $token->get_last4(),
			'expire'   => [
				'month' => $token->get_expiry_month(),
				'year'  => $token->get_expiry_year(),
			],
		];
	}

	// ── Payment token helpers ─────────────────────────────────────────────────

	/**
	 * Persist a StoreEngine payment token from a Square Card data array.
	 *
	 * @param int   $user_id
	 * @param array $card  Normalised card data array (id, last_4, card_brand, exp_month, exp_year, fingerprint).
	 *
	 * @return SquarePaymentTokenCc
	 */
	public function create_payment_token( int $user_id, array $card ): SquarePaymentTokenCc {
		$token = new SquarePaymentTokenCc();
		$token->set_token( $card['id'] );
		$token->set_gateway_id( $this->id );
		$token->set_user_id( $user_id );
		$token->set_card_type( strtolower( $card['card_brand'] ?? 'card' ) );
		$token->set_last4( $card['last_4'] ?? '' );
		$token->set_expiry_month( (string) ( $card['exp_month'] ?? '' ) );
		$token->set_expiry_year( (string) ( $card['exp_year'] ?? '' ) );
		$token->set_fingerprint( $card['fingerprint'] ?? '' );
		$token->save();

		return $token;
	}

	// ── Subscription & installment support ───────────────────────────────────────

	/**
	 * Determine whether to force the "save payment method" checkbox on.
	 *
	 * Returns true when:
	 *   - We are on the "Add Payment Method" page (always save)
	 *   - The cart contains a subscription or installment plan
	 *
	 * When forced, the checkbox is pre-checked and the customer cannot uncheck it —
	 * a saved card is required for automatic renewals.
	 *
     * @param Order $order *
     *
	 * @return bool
	 */
	public function should_force_save_payment( Order $order): bool {
		if ( Helper::is_add_payment_method_page() ) {
			return true;
		}

		return (bool) apply_filters(
			'storeengine/square/force_save_payment_method',
			Helper::cart() && Helper::cart()->get_meta( 'has_subscription' )
		);
	}

	/**
	 * Copy Square customer ID and card (source) ID onto all subscriptions linked
	 * to the given order.
	 *
	 * Called after every successful payment so that the subscription record always
	 * holds the latest card details — renewal orders inherit them in
	 * process_scheduled_payment().
	 *
	 * Mirrors GatewayStripe::maybe_update_source_on_subscription_order().
	 *
	 * @param Order  $order       The parent or renewal order.
	 * @param string $customer_id Square Customer ID.
	 * @param string $card_id     Square Card ID (e.g. ccof:…).
	 *
	 * @return void
	 */
	private function maybe_update_source_on_subscription_order( Order $order, string $customer_id, string $card_id ): void {
		if ( ! Helper::get_addon_active_status( 'subscription' ) || ( ! $customer_id && ! $card_id ) ) {
			return;
		}

		if ( SubscriptionCollection::order_contains_subscription( $order->get_id() ) ) {
			$subscriptions = SubscriptionCollection::get_subscriptions_for_order( $order->get_id() );
		} elseif ( SubscriptionCollection::order_contains_subscription( $order->get_id(), [ 'renewal' ] ) ) {
			$subscriptions = SubscriptionCollection::get_subscriptions_for_renewal_order( $order->get_id() );
		} else {
			return;
		}

		foreach ( $subscriptions as $subscription ) {
			if ( $customer_id ) {
				$subscription->update_meta_data( '_square_customer_id', $customer_id );
			}

			if ( $card_id ) {
				$subscription->update_meta_data( '_square_source_id', $card_id );
			}

			$subscription->set_payment_method( $this->id );
			$subscription->save();
		}
	}

	/**
	 * Process a scheduled subscription renewal or installment payment.
	 *
	 * StoreEngine's SubscriptionScheduler fires:
	 *   do_action( 'storeengine/subscription/scheduled_payment_square', $renewal_order )
	 *
	 * Flow:
	 *   1. Read _square_customer_id + _square_source_id from the renewal order meta
	 *      (copied from the subscription record, which was set by the initial payment).
	 *   2. Charge the saved card off-session via SquareService.
	 *   3. On success — complete the renewal order and update subscription status.
	 *   4. On failure — mark the renewal order as failed and put the subscription
	 *      on-hold so the customer is notified and can update their card.
	 *
	 * @param Order $renewal_order  Renewal order created by the scheduler.
	 *
	 * @return void
	 * @throws StoreEngineException
	 * @throws StoreEngineInvalidOrderStatusException
	 * @throws StoreEngineInvalidOrderStatusTransitionException
	 */
	public function process_scheduled_payment( Order $renewal_order ): void {
		$order_context = new OrderContext( $renewal_order->get_status() );
		$service       = SquareService::init( $this );

		try {
			// ── Zero-amount renewal (trial, coupon, etc.) ─────────────────────
			if ( ! (float) $renewal_order->get_total() ) {
				$renewal_order->set_paid_status( 'paid' );
				$order_context->proceed_to_next_status(
					Completed::STATUS,
					$renewal_order,
					_x( 'Payment not needed.', 'Square scheduled payment', 'storeengine-square' )
				);
				$renewal_order->save();

				return;
			}

			// ── Retrieve card details stored on the subscription ───────────────
			//
			// The subscription record holds _square_customer_id and _square_source_id
			// because maybe_update_source_on_subscription_order() wrote them there
			// after the initial (or most recent) checkout payment.
			// The renewal order inherits them from the subscription via Renewal::create_new_order().
			$customer_id = (string) $renewal_order->get_meta( '_square_customer_id', true, 'edit' );
			$card_id     = (string) $renewal_order->get_meta( '_square_source_id',   true, 'edit' );

			// Fallback: check user meta if renewal order meta is empty
			// (handles orders created before this version of the plugin).
			if ( ! $customer_id && $renewal_order->get_customer_id() ) {
				$customer_id = (string) get_user_meta( $renewal_order->get_customer_id(), '_square_customer_id', true );
			}

			if ( ! $customer_id || ! $card_id ) {
				throw new StoreEngineException(
					esc_html__( 'Square renewal failed: no saved payment method found for this subscription. The customer needs to update their payment method.', 'storeengine-square' ),
					'square-renewal-no-saved-card',
					[
						'renewal_order_id' => $renewal_order->get_id(),
						'customer_id'      => $customer_id,
						'card_id'          => $card_id,
					],
					400
				);
			}

			// ── Charge the saved card ─────────────────────────────────────────
			$idem_key = $service->generate_idempotency_key(
				'renewal_' . $renewal_order->get_id() . '_' . $card_id
			);

			/** @var \Square\Types\Payment $square_payment */
			$square_payment = $service->create_payment_with_saved_card(
				$renewal_order,
				$card_id,
				$customer_id,
				$idem_key
			);

			$status = $square_payment->getStatus() ?? '';

			if ( 'COMPLETED' !== $status ) {
				throw new StoreEngineException(
					sprintf(
						/* translators: %s: Square payment status */
						esc_html__( 'Square renewal charge returned unexpected status: %s', 'storeengine-square' ),
						esc_html( $status )
					),
					'square-renewal-unexpected-status',
					[ 'status' => $status, 'renewal_order_id' => $renewal_order->get_id() ],
					400
				);
			}

			// ── Record the result on the renewal order ────────────────────────
			$payment_id      = (string) $square_payment->getId();
			$receipt_url     = (string) $square_payment->getReceiptUrl();
			$card_details    = $square_payment->getCardDetails();
			$card            = $card_details ? $card_details->getCard() : null;
			$card_brand      = (string) ( $card ? $card->getCardBrand() : '' );
			$last4           = (string) ( $card ? $card->getLast4() : '' );
			$processing_fees = $square_payment->getProcessingFee();
			$first_fee       = isset( $processing_fees[0] ) ? $processing_fees[0] : null;
			$fee_money       = $first_fee ? $first_fee->getAmountMoney() : null;
			$fee_amount      = (int) ( $fee_money ? $fee_money->getAmount() : 0 );

			$renewal_order->set_transaction_id( $payment_id );
			$renewal_order->set_paid_status( 'paid' );
			$renewal_order->add_meta_data( '_square_payment_id',     $payment_id,  true );
			$renewal_order->add_meta_data( '_square_receipt_url',    $receipt_url, true );
			$renewal_order->add_meta_data( '_square_card_brand',     $card_brand,  true );
			$renewal_order->add_meta_data( '_square_card_last4',     $last4,       true );
			$renewal_order->add_meta_data( '_square_processing_fee', $fee_amount,  true );
			$renewal_order->add_meta_data( '_square_customer_id',    $customer_id, true );
			$renewal_order->add_meta_data( '_square_source_id',      $card_id,     true );

			// Keep subscription's card meta up-to-date.
			$this->maybe_update_source_on_subscription_order( $renewal_order, $customer_id, $card_id );

            $order_context->proceed_to_next_status( 'process_order', $renewal_order, [
                    'note'           => sprintf(
                    // translators: %s. Square PaymentId.
                            __( 'Square payment complete (Payment ID: %s).', 'storeengine-square' ),
                            $payment_id
                    ),
                    'transaction_id' => $payment_id,
            ] );

			$order_context->proceed_to_next_status(
				Completed::STATUS,
				$renewal_order,
				[
					'note' => sprintf(
						/* translators: 1: payment ID 2: card brand 3: last 4 digits */
						__( 'Square renewal payment complete. Payment ID: %1$s. Card: %2$s ending %3$s.', 'storeengine-square' ),
						$payment_id,
						$card_brand,
						$last4
					),
					'transaction_id' => $payment_id,
				]
			);

			$renewal_order->save();

			/**
			 * Fires after a successful Square scheduled renewal / installment payment.
			 *
			 * @param \Square\Types\Payment $square_payment
			 * @param Order                  $renewal_order
			 */
			do_action( 'storeengine/square/after_renewal_payment', $square_payment, $renewal_order );

		} catch ( StoreEngineException $e ) {
			Helper::log_error( $e );

			$renewal_order->update_status(
				OrderStatus::PAYMENT_FAILED,
				sprintf(
					/* translators: %s: error message */
					__( 'Square renewal payment failed. Error: %s', 'storeengine-square' ),
					$e->getMessage()
				)
			);

			// Put the subscription on-hold so further renewals are paused and
			// the customer is notified to update their payment method.
			$this->maybe_put_subscription_on_hold( $renewal_order, $e->getMessage() );

		} catch ( \Throwable $e ) {
			Helper::log_error( $e );

			$renewal_order->update_status(
				OrderStatus::PAYMENT_FAILED,
				sprintf(
					/* translators: %s: error message */
					__( 'Square renewal payment failed. Error: %s', 'storeengine-square' ),
					$e->getMessage()
				)
			);

			$this->maybe_put_subscription_on_hold( $renewal_order, $e->getMessage() );
		}
	}

	/**
	 * Put the subscription linked to a failed renewal order on-hold.
	 *
	 * Called when process_scheduled_payment() catches an exception.
	 * Putting the subscription on-hold pauses future renewal attempts and
	 * (if the email addon is active) triggers a "payment failed" notification
	 * to the customer.
	 *
	 * @param Order  $renewal_order
	 * @param string $reason  Human-readable failure reason for the order note.
	 *
	 * @return void
	 */
	private function maybe_put_subscription_on_hold( Order $renewal_order, string $reason ): void {
		if ( ! Helper::get_addon_active_status( 'subscription' ) ) {
			return;
		}

		try {
			$subscriptions = SubscriptionCollection::get_subscriptions_for_renewal_order( $renewal_order->get_id() );

			foreach ( $subscriptions as $subscription ) {
				if ( $subscription->has_status( [ 'active', 'pending' ] ) ) {
					// payment_failed() marks the last order as failed, fires payment_failed actions,
					// and triggers retry scheduling (via storeengine/subscription/renewal_payment_failed).
					// Passing 'on_hold' puts the subscription on hold rather than cancelling it.
					$subscription->payment_failed( 'on_hold' );
				}
			}
		} catch ( \Throwable $e ) {
			Helper::log_error( $e );
		}
	}

}

// End of file GatewaySquare.php.
