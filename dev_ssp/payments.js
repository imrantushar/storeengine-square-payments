/**
 * square-checkout.js
 *
 * Square gateway — uses the StoreEngine registerGateway() API
 * (the same system built for Stripe, PayPal, Razorpay, Paddle).
 *
 * FLOW — new card
 * ───────────────
 * 1. onSelected   → initialize Square Web Payments SDK + mount card widget
 * 2. processPayment → tokenize the card → POST nonce with place_order
 *
 * FLOW — saved card
 * ─────────────────
 * StoreEngine's own tokenization UI (qr class) already handles show/hide of
 * the card form when the customer selects a saved card vs "new card" radio.
 * The gateway does NOT need to manage visibility — StoreEngine does it.
 *
 * 1. onSelected → mount card widget (StoreEngine hides it if saved card selected)
 * 2. processPayment → if saved card radio selected: skip tokenization, POST
 *    form data as-is (PHP reads token via get_selected_token_from_request()).
 *    If new card: tokenize via Square SDK, POST nonce.
 *
 * PHP side injects credentials via:
 *   StoreEngineGlobal.payment_gateways.square = { application_id, location_id, is_sandbox }
 *
 * THIRD-PARTY EXTENSION
 * ─────────────────────
 *   wp.hooks.addFilter('storeengine.square.card_options', 'my-plugin', opts => opts);
 *   wp.hooks.addAction('storeengine.square.after_tokenize', 'my-plugin', async (token) => {});
 */

import { __ } from '@wordpress/i18n';
import { applyFilters, doAction, doActionAsync } from '@wordpress/hooks';
import {
	getSeGlobal,
	renderErrorNotification,
} from '@Utils/helper';

// ─── Module state ─────────────────────────────────────────────────────────────

/** @type {{ payments: any, card: any } | null} */
let squareState = null;

/**
 * Mount promise — shared singleton across all concurrent callers.
 *
 * WHY THIS EXISTS
 * ───────────────
 * StoreEngine's CheckoutManager calls onSelected (→ mountCardWidget) multiple
 * times in rapid succession on page load:
 *   window.load → _updateCheckout() → _onPaymentMethodChanged() → onSelected
 *   storeengine_cart_updated         → _onPaymentMethodChanged() → onSelected
 *
 * Square's card.attach() is async. A second call arriving while the first
 * attach() is still in flight would pass any DOM-based guard (the iframe is
 * not yet in the DOM) and call attach() again, producing two iframes.
 *
 * Storing the promise means every concurrent caller awaits the same operation.
 * attach() runs exactly once regardless of how many times onSelected fires.
 *
 * @type {Promise<void>|null}
 */
let mountPromise = null;

// ─── Helpers ──────────────────────────────────────────────────────────────────

function clearError() {
	const el = document.getElementById( 'storeengine-square-card-errors' );
	if ( el ) { el.textContent = ''; el.style.display = 'none'; }
}

/**
 * Return the currently selected saved-token ID, or null for "new card".
 *
 * StoreEngine renders saved cards as radio inputs:
 *   <input type="radio" name="storeengine-square-payment-token" value="{id}">
 * The "new card" option has value="new".
 *
 * When a saved card is selected, processPayment skips Square SDK tokenization
 * and lets PHP handle it via get_selected_token_from_request() (Path A).
 */
function getSelectedSavedToken() {
	const radio = document.querySelector(
		'input[name="storeengine-square-payment-token"]:checked'
	);
	if ( ! radio || radio.value === 'new' || ! radio.value ) {
		return null;
	}
	return radio.value;
}

// ─── Square SDK init ──────────────────────────────────────────────────────────

async function getOrInitPayments() {
	if ( squareState?.payments ) return squareState.payments;

	if ( ! window.Square ) {
		throw new Error( __( 'Square Web Payments SDK is not loaded.', 'storeengine-square' ) );
	}

	const config = getSeGlobal( 'payment_gateways.square', {} );

	if ( ! config.application_id || ! config.location_id ) {
		throw new Error( __( 'Square is not configured. Please contact the site administrator.', 'storeengine-square' ) );
	}

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

// ─── Card widget ──────────────────────────────────────────────────────────────

/**
 * Mount the Square card widget — guaranteed to call attach() exactly once.
 *
 * Uses mountPromise as a singleton: the first call creates and stores the
 * promise; every subsequent call (including concurrent ones) returns it.
 * StoreEngine's tokenization UI controls visibility of the form container —
 * we do not touch display/visibility here.
 *
 * @param {any} payments  Square Payments instance.
 * @return {Promise<void>}
 */
function mountCardWidget( payments ) {
	if ( mountPromise ) return mountPromise;

	mountPromise = ( async () => {
		const container = document.getElementById( 'storeengine-square-card-element' );
		if ( ! container ) return;

		const options = applyFilters( 'storeengine.square.card_options', {
			style: {
				'.input-container':          { borderColor: '#ddd', borderRadius: '4px' },
				'.input-container.is-focus': { borderColor: '#1a56db' },
				'.input-container.is-error': { borderColor: '#dc2626' },
			},
		} );

		if ( ! squareState.card ) {
			squareState.card = await payments.card( options );
		}

		await squareState.card.attach( '#storeengine-square-card-element' );
		doAction( 'storeengine.square.card_mounted', squareState.card );
	} )();

	return mountPromise;
}

// ─── Tokenization ─────────────────────────────────────────────────────────────

async function tokenizeCard( card, formData ) {
	const result = await card.tokenize();

	if ( result.status !== 'OK' ) {
		const errors  = result.errors ?? [];
		const message = errors.map( ( e ) => e.message ).join( ' ' ) ||
			__( 'Card tokenization failed. Please check your card details.', 'storeengine-square' );
		throw new Error( message );
	}

	const token = result.token;
	await doActionAsync( 'storeengine.square.after_tokenize', token, formData );
	return token;
}

// ─── registerGateway ─────────────────────────────────────────────────────────

export function initSquareGateway() {
	window.StoreEngineCheckout?.registerGateway( 'square', {

		/**
		 * Called when the customer selects Square at checkout.
		 *
		 * Mount the card widget. StoreEngine's tokenization handler (qr class)
		 * already manages show/hide of the form based on the saved-card radio
		 * selection — we intentionally do not duplicate that here.
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
		 * Called when the customer clicks "Place Order".
		 *
		 * Path A — saved card selected:
		 *   Token ID is already in the form via StoreEngine's radio input.
		 *   PHP reads it via get_selected_token_from_request(). No SDK needed.
		 *
		 * Path B — new card:
		 *   Tokenize via Square SDK, POST nonce as square_payment_token.
		 */
		async processPayment( { checkout_action, makeRequest } ) {
			clearError();

			// Path A — saved card: skip tokenization entirely.
			if ( getSelectedSavedToken() ) {
				return await makeRequest( checkout_action, {} );
			}

			// Path B — new card.
			if ( ! squareState?.card ) {
				throw new Error(
					__( 'Card widget is not initialized. Please refresh and try again.', 'storeengine-square' )
				);
			}

			const formData = window.StoreEngineCheckout.getFormData();
			const token    = await tokenizeCard( squareState.card, formData );

			return await makeRequest( checkout_action, { square_payment_token: token } );
		},

		onCheckoutUpdated() {
			// No-op — Square reads the amount from the order on the server.
		},
	} );
}

// ─── Entrypoint ───────────────────────────────────────────────────────────────

document.addEventListener( 'DOMContentLoaded', () => {
	if ( ! window.StoreEngineCheckout ) return;
	initSquareGateway();
} );