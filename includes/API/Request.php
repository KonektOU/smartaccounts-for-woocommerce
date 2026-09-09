<?php
/**
 * API Request
 *
 * @package SmartAccounts for WooCommerce
 * @author Konekt
 */

namespace Konekt\WooCommerce\SmartAccounts\API;

use SkyVerge\WooCommerce\PluginFramework\v5_15_2 as Framework;

defined( 'ABSPATH' ) || exit;


/**
 * Base API request object.
 *
 * @since 1.0.0
 */
class Request extends Framework\SV_WC_API_JSON_Request {


	/**
	 * Construct API request
	 *
	 * @param string $api_id
	 * @param string $api_key
	 * @param string $method
	 * @param string $path
	 * @param array $data
	 * @param array $params
	 */
	public function __construct( $api_public_key, $api_private_key, $method, $path, $data = array(), $params = array() ) {

		$this->api_public_key  = $api_public_key;
		$this->api_private_key = $api_private_key;
		$this->method          = $method;
		$this->path            = $path;
		$this->data            = $data;
		$this->params          = $params;
	}


	/**
	 * Get the request parameters.
	 *
	 * @since 1.0.0
	 * @see SV_WC_API_Request::get_params()
	 * @return array
	 */
	public function get_params() {
		$params = $this->params;

		$params['timestamp'] = date_i18n( 'dmYHis' );
		$params['apikey']    = $this->api_public_key;
		$params['signature'] = $this->create_signature( $params, $this->data );

		return $params;
	}


	/**
	 * Create signature for the request
	 *
	 * @param array $params
	 * @param array $data
	 *
	 * @return string
	 */
	protected function create_signature( $params, $data = array() ) {

		$signable      = http_build_query( $params ) . ( ! empty( $data ) ? wp_json_encode( $data ) : '' );
		$raw_signature = hash_hmac( 'sha256', $signable, $this->api_private_key );

		return $raw_signature;
	}


}
