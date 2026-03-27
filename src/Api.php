<?php
namespace StoreEngineSquare;

use StoreEngine\API\AbstractRestApiController;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Api extends AbstractRestApiController {

	protected $rest_base = 'square';

	public static function init(): void {
		$self = new self();
		add_action( 'rest_api_init', [ $self, 'register_routes' ] );
	}

	public function register_routes(): void {
		// Extend here with Square webhook handling.
		// register_rest_route( $this->namespace, '/square/webhook', [...] );
	}

	public function permissions_check(): bool { return is_user_logged_in(); }
	public function get_items( $request ) {}
	public function create_item( $request ) {}
	public function get_item( $request ) {}
}
