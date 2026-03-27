<?php
/**
 * SquarePaymentTokenCc
 *
 * Extends StoreEngine's PaymentTokenCc with a fingerprint field used for
 * duplicate-detection when saving Square cards-on-file.
 *
 * @package StoreEngineSquare
 */

namespace StoreEngineSquare;

use StoreEngine\Classes\PaymentTokens\PaymentTokenCc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SquarePaymentTokenCc extends PaymentTokenCc {

	public function __construct( $token = '' ) {
		$this->extra_data['fingerprint']        = '';
		$this->meta_key_to_props['fingerprint'] = 'fingerprint';

		parent::__construct( $token );
	}

	public function get_fingerprint( string $context = 'view' ): string {
		return (string) $this->get_prop( 'fingerprint', $context );
	}

	public function set_fingerprint( string $fingerprint ): void {
		$this->set_prop( 'fingerprint', $fingerprint );
	}
}

// End of file SquarePaymentTokenCc.php.
