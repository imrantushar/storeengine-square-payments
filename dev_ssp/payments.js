/**
 * square-checkout.js
 *
 * Square gateway — uses the StoreEngine registerGateway() API
 * (the same system built for Stripe, PayPal, Razorpay, Paddle).
 *
 * FLOW
 * ────
 * 1. onSelected   → initialize Square Web Payments SDK + mount card widget
 * 2. processPayment → tokenize the card → POST nonce with place_order
 * 3. onCheckoutUpdated → nothing needed (Square amounts come from the server)
 *
 * PHP side already injects credentials via:
 *   StoreEngineGlobal.payment_gateways.square = { application_id, location_id, is_sandbox }
 *
 * THIRD-PARTY EXTENSION
 * ─────────────────────
 * Other plugins can hook into the Square flow via WordPress hooks:
 *
 *   wp.hooks.addFilter(
 *     'storeengine.square.card_options',
 *     'my-plugin/square-ext',
 *     ( options ) => ({ ...options, postalCode: true })
 *   );
 *
 *   wp.hooks.addAction(
 *     'storeengine.square.after_tokenize',
 *     'my-plugin/square-ext',
 *     async ( token, verificationToken ) => { ... }
 *   );
 */

import { __, sprintf } from '@wordpress/i18n';
import { applyFilters, doAction, doActionAsync } from '@wordpress/hooks';
import {
	getSeGlobal,
	renderErrorNotification,
	StoreEngineDQ,
} from '@Utils/helper';

// ─── Module state ─────────────────────────────────────────────────────────────

/** @type {{ payments: any, card: any } | null} */
let squareState = null;

/** True after the card widget has been mounted at least once. */
let isMounted = false;

// ─── Helpers ──────────────────────────────────────────────────────────────────

function getSquareConfig() {
	return getSeGlobal( 'payment_gateways.square', {} );
}

function getErrorContainer() {
	return document.getElementById( 'storeengine-square-card-errors' );
}

function showError( message ) {
	const container = getErrorContainer();
	if ( container ) {
		container.textContent = message;
		container.style.display = 'block';
	}
}

function clearError() {
	const container = getErrorContainer();
	if ( container ) {
		container.textContent = '';
		container.style.display = 'none';
	}
}

// ─── Square Web Payments SDK initialization ───────────────────────────────────

/**
 * Initialize the Square Payments instance (once per page load).
 *
 * Square.payments() accepts an optional third argument { env } but the
 * recommended approach is to load the correct CDN script per environment
 * (handled in Assets.php). The application_id prefix also encodes the
 * environment — sandbox IDs start with 'sandbox-'.
 *
 * We perform an explicit guard here as a belt-and-braces check: if the
 * application_id prefix does not match the is_sandbox flag coming from PHP,
 * we throw a clear error before Square does — preventing the cryptic SDK
 * mismatch message.
 *
 * @return {Promise<any>}  Square Payments instance.
 */
async function getOrInitPayments() {
	if ( squareState?.payments ) {
		return squareState.payments;
	}

	if ( ! window.Square ) {
		throw new Error( __( 'Square Web Payments SDK is not loaded.', 'storeengine-square' ) );
	}

	const config = getSquareConfig();

	if ( ! config.application_id || ! config.location_id ) {
		throw new Error( __( 'Square is not configured. Please contact the site administrator.', 'storeengine-square' ) );
	}

	// ── Environment / application_id mismatch guard ───────────────────────────
	// Square application IDs starting with 'sandbox-' are sandbox-only.
	// Catch the mismatch early with a meaningful developer-facing error.
	const appIdIsSandbox = config.application_id.startsWith( 'sandbox-' );

	if ( config.is_sandbox && ! appIdIsSandbox ) {
		throw new Error(
			__( 'Square configuration error: is_sandbox is true but the Application ID does not start with "sandbox-". Check your gateway settings.', 'storeengine-square' )
		);
	}

	if ( ! config.is_sandbox && appIdIsSandbox ) {
		throw new Error(
			__( 'Square configuration error: Live Mode is enabled but a sandbox Application ID is configured. Update your Application ID to a production key.', 'storeengine-square' )
		);
	}

	const payments = window.Square.payments( config.application_id, config.location_id );
	squareState    = { payments, card: null };

	return payments;
}

/**
 * Build the Card widget options.
 * Third-party plugins can modify options via the `storeengine.square.card_options` filter.
 *
 * @return {Object}
 */
function buildCardOptions() {
	const defaults = {
		style: {
			'.input-container': { borderColor: '#ddd', borderRadius: '4px' },
			'.input-container.is-focus': { borderColor: '#1a56db' },
			'.input-container.is-error': { borderColor: '#dc2626' },
		},
	};

	return applyFilters( 'storeengine.square.card_options', defaults );
}

/**
 * Create and mount the Square Card widget.
 * Safe to call multiple times — re-mounts if container is empty.
 *
 * @param {any} payments  Square Payments instance.
 * @return {Promise<void>}
 */
async function mountCardWidget( payments ) {
	const container = document.getElementById( 'storeengine-square-card-element' );
	if ( ! container ) return;

	// Already mounted and still has children — skip.
	if ( isMounted && container.children.length > 0 ) return;

	const options = buildCardOptions();

	if ( ! squareState.card ) {
		squareState.card = await payments.card( options );
	}

	await squareState.card.attach( '#storeengine-square-card-element' );
	isMounted = true;

	// Notify listeners (e.g. accessibility tools, analytics).
	doAction( 'storeengine.square.card_mounted', squareState.card );
}

// ─── Tokenization ─────────────────────────────────────────────────────────────

/**
 * Tokenize the card data entered in the widget.
 *
 * @param {any}    card       Square Card widget instance.
 * @param {Object} formData   Checkout form data (for verification token).
 *
 * @return {Promise<string>}  Square nonce (token).
 */
async function tokenizeCard( card, formData ) {
	const result = await card.tokenize();

	if ( result.status !== 'OK' ) {
		const errors = result.errors ?? [];
		const message = errors.map( ( e ) => e.message ).join( ' ' ) ||
			__( 'Card tokenization failed. Please check your card details.', 'storeengine-square' );

		throw new Error( message );
	}

	const token = result.token;

	/**
	 * Fires after a card is tokenized.
	 * Use this to run SCA verification or additional checks.
	 */
	await doActionAsync( 'storeengine.square.after_tokenize', token, formData );

	return token;
}

// ─── registerGateway implementation ──────────────────────────────────────────

/**
 * Registers the Square gateway handler with StoreEngine CheckoutManager.
 * Called once when this script loads.
 */
export function initSquareGateway() {
	window.StoreEngineCheckout?.registerGateway( 'square', {

		/**
		 * Called when the customer selects Square at checkout.
		 * Mounts the card widget.
		 */
		async onSelected() {
			try {
				clearError();
				const payments = await getOrInitPayments();
				await mountCardWidget( payments );
			} catch ( err ) {
				renderErrorNotification( err );
			}
		},

		/**
		 * Called when the customer clicks "Place Order" with Square selected.
		 * Tokenizes the card, appends the nonce to the form, then POSTs to StoreEngine.
		 *
		 * @param {GatewayContext} context
		 * @return {Promise<Object>} Server response.
		 */
		async processPayment( { checkout_action, makeRequest } ) {
			clearError();

			if ( ! squareState?.card ) {
				throw new Error( __( 'Card widget is not initialized. Please refresh and try again.', 'storeengine-square' ) );
			}

			// 1. Get the form data that will be sent to the server.
			const formData = window.StoreEngineCheckout.getFormData();

			// 2. Tokenize the card.
			const token = await tokenizeCard( squareState.card, formData );

			// 3. Inject the Square nonce into the payload.
			//    GatewaySquare::process_payment() reads $_POST['square_payment_token'].
			const extraPayload = { square_payment_token: token };

			// 4. POST to StoreEngine checkout action — same as every other gateway.
			const response = await makeRequest( checkout_action, extraPayload );

			return response;
		},

		/**
		 * Called when totals change. Square doesn't need client-side amount updates.
		 */
		onCheckoutUpdated() {
			// No-op — Square reads the amount from the order on the server.
		},
	} );
}

// ─── Entrypoint ───────────────────────────────────────────────────────────────

document.addEventListener( 'DOMContentLoaded', () => {
	// Guard: only run when StoreEngineCheckout is available.
	if ( ! window.StoreEngineCheckout ) {
		return;
	}

	initSquareGateway();
} );