<?php
/**
 * SquarePaymentTokens
 *
 * Static helper for querying Square payment tokens stored in StoreEngine's
 * payment token table.
 *
 * Only contains logic specific to Square — all underlying storage is handled
 * by StoreEngine's PaymentTokens collection class.
 *
 * @package StoreEngineSquare\PaymentTokens
 */

namespace StoreEngineSquare;

use StoreEngine\Classes\PaymentTokens\PaymentToken;
use StoreEngine\Classes\PaymentTokens\PaymentTokens;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SquarePaymentTokens {

	/**
	 * Find an existing saved token for this customer that matches the given
	 * Square card fingerprint.
	 *
	 * Fingerprints are unique per card number regardless of expiry date, so
	 * they reliably identify duplicate cards even when re-entered.
	 *
	 * @param array  $card_data  Normalised card array with at least a 'fingerprint' key.
	 * @param int    $user_id    WordPress user ID.
	 * @param string $gateway_id Gateway ID string (e.g. 'square').
	 *
	 * @return PaymentToken|null  Existing token if found, null otherwise.
	 */
	public static function find_duplicate( array $card_data, int $user_id, string $gateway_id ): ?PaymentToken {
		$fingerprint = $card_data['fingerprint'] ?? '';

		if ( ! $fingerprint || ! $user_id ) {
			return null;
		}

		$tokens = PaymentTokens::get_customer_tokens( $user_id, $gateway_id );

		foreach ( $tokens as $token ) {
			if (
				$token instanceof SquarePaymentTokenCc &&
				$token->get_fingerprint() === $fingerprint
			) {
				return $token;
			}
		}

		return null;
	}
}