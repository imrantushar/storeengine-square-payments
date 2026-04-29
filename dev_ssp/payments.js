/**
 * Square gateway adapter — works on BOTH checkout surfaces.
 *
 *   - Legacy `/checkout/`         (jQuery CheckoutManager)
 *   - React Quick Checkout        (instant-checkout React app)
 *
 * Both surfaces drive a single adapter registered against the unified
 * `window.StoreEngineCheckoutCore` registry. We use the global rather than
 * importing from `@CheckoutCore` because this plugin is shipped as a
 * standalone WordPress plugin — a real third-party gateway does not bundle
 * core's source files.
 *
 * FLOW — new card
 * ─────────────────
 * 1. onSelected (legacy) / mountUI (React) → init Square Web Payments SDK
 *    + attach card widget to #storeengine-square-card-element.
 * 2. processPayment → tokenize the card → forward the nonce as
 *    `payment_payload.square_payment_token` via ctx.placeOrder() (legacy)
 *    or by returning `{ payment_payload }` (React).
 *
 * FLOW — saved card
 * ─────────────────
 * The shopper picks a saved card via the radio input that StoreEngine renders
 * (`storeengine-square-payment-token`). processPayment skips tokenization
 * entirely — the token id rides along in the form data and the server's
 * GatewaySquare::process_payment() reads it via get_selected_token_from_request().
 *
 * SERVER CONTRACT
 * ───────────────
 *   storeengine/checkout/gateway/square/data filter exposes
 *     { application_id, location_id, is_sandbox } to both surfaces.
 *   storeengine/checkout/before_place_order_payload/square action persists
 *     `square_payment_token` onto $_POST so process_payment() can read it.
 *
 * THIRD-PARTY EXTENSION
 * ─────────────────────
 *   wp.hooks.addFilter('storeengine.square.card_options', 'my-plugin', opts => opts);
 *   wp.hooks.addAction('storeengine.square.after_tokenize', 'my-plugin', async (token) => {});
 */

import { __ } from '@wordpress/i18n';
import { addAction, applyFilters, doAction, doActionAsync } from '@wordpress/hooks';
import {
	getSeGlobal,
	handleRedirectResponse,
	notification,
	renderErrorNotification,
	StoreEngineDQ,
} from '@Utils/helper';

/* ── Module state ────────────────────────────────────────────────────────── */

/** Cached Square Payments instance (returned by Square.payments()). Square
 *  recommends a single Payments instance per page, so this stays module-global. */
let squarePayments = null;

/** The card currently in use (the most recently mounted card element). Used
 *  by the standalone tokenizeCard() helper for the dashboard add-payment-method
 *  flow. NOT used as a reuse cache — each mount creates a fresh card. */
let activeCard = null;

/* ── Helpers ─────────────────────────────────────────────────────────────── */

function clearError() {
	StoreEngineDQ( '#storeengine-square-card-errors' ).hide().text( '' );
}

/**
 * Square gateway config exposed by the server filter
 * `storeengine/checkout/gateway/square/data`. On the React surface ctx.gateway
 * carries it directly; on the legacy surface it lives under the localized
 * `payment_gateways.square` global. Either way we get the same shape.
 */
function getSquareConfig( ctx ) {
	if ( ctx && ctx.gateway && ctx.gateway.application_id ) {
		return ctx.gateway;
	}
	return getSeGlobal( 'payment_gateways.square', {
		application_id: null,
		is_sandbox:     false,
		location_id:    null,
	} );
}

/**
 * Saved-card radio: empty / "new" → null (use a new card), otherwise the
 * saved StoreEngine token id. The server reads this via
 * GatewaySquare::get_selected_token_from_request().
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

/* ── Square SDK init ─────────────────────────────────────────────────────── */

async function getOrInitPayments( config ) {
	if ( squarePayments ) return squarePayments;

	if ( ! window.Square ) {
		throw new Error( __( 'Square Web Payments SDK is not loaded.', 'storeengine-square' ) );
	}

	if ( ! config.application_id || ! config.location_id ) {
		throw new Error( __( 'Square is not configured. Please contact the site administrator.', 'storeengine-square' ) );
	}

	const appIdIsSandbox = String( config.application_id ).startsWith( 'sandbox-' );
	if ( config.is_sandbox && ! appIdIsSandbox ) {
		throw new Error( __( 'Square configuration error: invalid “Application ID”.', 'storeengine-square' ) );
	}
	if ( ! config.is_sandbox && appIdIsSandbox ) {
		throw new Error( __( 'Square configuration error: invalid “Application ID”.', 'storeengine-square' ) );
	}

	squarePayments = window.Square.payments( config.application_id, config.location_id );
	return squarePayments;
}

/* ── Card widget ─────────────────────────────────────────────────────────── */

/**
 * Attach a freshly-created card to the supplied container. We stash the card
 * on the container itself (`__seSquareCard`) so repeated mounts on the same
 * persistent container (legacy /checkout/ page, where the same DOM node
 * survives gateway switches) reuse the existing card. React-driven mounts
 * unmount + remount a brand-new container each time the shopper switches
 * gateways, so the WeakRef-via-attribute is GC'd along with the container —
 * the next mount creates a fresh card.
 *
 * Returns the card so the surface can hold it (React's MountUIBridge passes
 * it back to `unmountUI` on cleanup, which then calls `card.destroy()`).
 */
async function attachToContainer( container, config ) {
	if ( ! container ) return null;

	if ( container.__seSquareCard ) {
		// Same persistent container, already attached — reuse.
		activeCard = container.__seSquareCard;
		return activeCard;
	}

	const payments = await getOrInitPayments( config );
	const options  = applyFilters( 'storeengine.square.card_options', {
		style: {
			'.input-container':          { borderColor: '#ddd', borderRadius: '4px' },
			'.input-container.is-focus': { borderColor: '#1a56db' },
			'.input-container.is-error': { borderColor: '#dc2626' },
		},
	} );

	const card = await payments.card( options );
	await card.attach( container );

	container.__seSquareCard = card;
	activeCard               = card;

	doAction( 'storeengine.square.card_mounted', card );
	return card;
}

/**
 * Mount the card element into the surface-provided container OR — when the
 * legacy /checkout/ page calls onSelected without a `ui` block — into the
 * `#storeengine-square-card-element` DOM node the gateway's `payment_fields()`
 * rendered.
 */
async function mountCard( ctx ) {
	const config = getSquareConfig( ctx );
	if ( ctx && ctx.ui && ctx.ui.container ) {
		return attachToContainer( ctx.ui.container, config );
	}
	const container = document.querySelector( '#storeengine-square-card-element' );
	if ( container ) {
		return attachToContainer( container, config );
	}
	return null;
}

/**
 * Tear down a previously-mounted card. Called from React's MountUIBridge
 * when the shopper switches away from Square; without this, switching
 * gateways and coming back would try to attach the cached card to a new
 * container, which Square's SDK refuses.
 */
async function destroyCard( card ) {
	if ( ! card ) return;
	try {
		if ( typeof card.destroy === 'function' ) {
			await card.destroy();
		}
	} catch ( e ) {
		// ignore — best-effort cleanup
	}
	if ( activeCard === card ) {
		activeCard = null;
	}
}

/* ── Tokenization ────────────────────────────────────────────────────────── */

async function tokenizeCard( card, formData ) {
	const target = card || activeCard;
	if ( ! target ) {
		throw new Error( __( 'Card widget is not initialized. Please refresh and try again.', 'storeengine-square' ) );
	}

	const result = await target.tokenize();

	if ( result.status !== 'OK' ) {
		const errors  = result.errors || [];
		const message = errors.map( ( e ) => e.message ).join( ' ' )
			|| __( 'Card tokenization failed. Please check your card details.', 'storeengine-square' );
		throw new Error( message );
	}

	await doActionAsync( 'storeengine.square.after_tokenize', result.token, formData );
	return result.token;
}

/* ── Adapter registration ────────────────────────────────────────────────── */

export function registerSquareAdapter() {
	const core = window.StoreEngineCheckoutCore;
	if ( ! core || typeof core.register !== 'function' ) {
		// Core didn't load — bail silently. Square gateway will be unavailable.
		return;
	}

	core.register( 'square', {
		id: 'square',

		/**
		 * Legacy surface — fires when the shopper picks Square at checkout.
		 * The legacy CheckoutManager doesn't pass a ui.container, so we mount
		 * into the DOM node our payment_fields() rendered.
		 */
		async onSelected( ctx ) {
			try {
				clearError();
				await mountCard( ctx );
			} catch ( err ) {
				renderErrorNotification( err );
			}
		},

		/**
		 * React surface — fires when the gateway becomes selected. ctx.ui.container
		 * is a React-owned DOM node; we attach a fresh card element into it
		 * and return the card so MountUIBridge can pass it back to unmountUI
		 * on teardown.
		 */
		async mountUI( ctx ) {
			try {
				clearError();
				const card = await mountCard( ctx );
				if ( ctx.ui && typeof ctx.ui.onReady === 'function' ) {
					ctx.ui.onReady( card );
				}
				return card;
			} catch ( err ) {
				if ( ctx.ui && typeof ctx.ui.onError === 'function' ) {
					ctx.ui.onError( err );
				}
				throw err;
			}
		},

		/**
		 * React surface — fires when the shopper switches to a different
		 * gateway. Destroying the card element here means the next select
		 * gets a brand-new card, fixing the "form doesn't reappear after
		 * switching away and back" bug.
		 */
		async unmountUI( card ) {
			await destroyCard( card );
		},

		async processPayment( ctx ) {
			clearError();

			// Saved-card path — the radio carries the token id; server picks
			// it up via get_selected_token_from_request(). No SDK needed.
			if ( getSelectedSavedToken() ) {
				if ( typeof ctx.placeOrder === 'function' ) {
					return ctx.placeOrder( {} );
				}
				return { payment_payload: {} };
			}

			// New-card path — tokenize via Square SDK, forward the nonce.
			const formData = ctx.formData || {};
			const token    = await tokenizeCard( null, formData );
			const payload  = { square_payment_token: token };

			if ( typeof ctx.placeOrder === 'function' ) {
				return ctx.placeOrder( payload );
			}
			return { payment_payload: payload };
		},

		// No-op — Square reads the amount from the order on the server, so
		// nothing to refresh client-side when totals change.
		onCheckoutUpdated() {},
	} );
}

/* ── Add-payment-method dashboard form ───────────────────────────────────── */

addAction( 'storeengine.add-payment-method.init-form', 'storeengine-square/init', async () => {
	clearError();
	try {
		await mountCard( null );
	} catch ( err ) {
		renderErrorNotification( err );
	}
} );

addAction( 'storeengine.add-payment-method.select-payment-method', 'storeengine-square/select', async ( method ) => {
	if ( 'square' !== method ) return;
	clearError();
	try {
		await mountCard( null );
	} catch ( err ) {
		renderErrorNotification( err );
	}
} );

addAction( 'storeengine.add-payment-method.save-payment-method', 'storeengine-square/save', async ( method, form ) => {
	if ( 'square' !== method ) return;

	const formData = Object.fromEntries( new FormData( form.get( 0 ) ) );
	const token    = await tokenizeCard( null, formData );

	// Use the helper exposed under the StoreEngineCheckoutCore.CheckoutApi
	// global. We post directly via fetch because PaymentMethods is a separate
	// REST namespace from /checkout/* (which is what CheckoutApi targets).
	const SE       = window.StoreEngineGlobal || {};
	const restRoot = ( SE.rest_url || '/wp-json/' ).replace( /\/+$/, '/' );
	const res = await fetch( restRoot + 'storeengine/v1/payment-methods', {
		method:      'POST',
		credentials: 'include',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce':   SE.nonce || '',
		},
		body: JSON.stringify( {
			payment_method:  'square',
			payment_payload: { square_payment_token: token },
			fields:          formData,
		} ),
	} );
	const text = await res.text();
	let response = null;
	try { response = text ? JSON.parse( text ) : null; } catch ( e ) { /* ignore */ }
	if ( ! res.ok ) {
		throw new Error( ( response && ( response.message || response.code ) ) || __( 'Failed to save card.', 'storeengine-square' ) );
	}

	if ( response && response.message ) {
		await notification( response.message, response.found ? 'info' : 'success', 3500 );
	}
	await handleRedirectResponse( response, 850 );
} );

/* ── Entry point ─────────────────────────────────────────────────────────── */

document.addEventListener( 'DOMContentLoaded', registerSquareAdapter );
// Also register immediately if the doc is already past DOMContentLoaded by
// the time this script runs (deferred / late-loaded bundles).
if ( document.readyState !== 'loading' ) {
	registerSquareAdapter();
}
