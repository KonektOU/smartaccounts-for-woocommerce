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
	 * Timezone every API request must be expressed in.
	 *
	 * From the SmartAccounts API documentation, under the security measures: "Stale requests —
	 * requests with a time difference more than 15 minutes are ignored as stale. Important! You
	 * must send requests with Estonian timezone!"
	 *
	 * So this is deliberately not UTC, and deliberately not the store's own timezone either: a
	 * shop left on WordPress's UTC default would sign every request three hours out in summer
	 * and have all of them rejected as stale.
	 */
	const API_TIMEZONE = 'Europe/Tallinn';


	/**
	 * Format a time the way the API expects it.
	 *
	 * @param string $format
	 * @param int|null $timestamp Unix timestamp, or null for now.
	 *
	 * @return string
	 */
	public static function format_api_time( $format = 'dmYHis', $timestamp = null ) {

		$date = new \DateTime( '@' . ( null === $timestamp ? time() : (int) $timestamp ) );

		$date->setTimezone( new \DateTimeZone( self::API_TIMEZONE ) );

		return $date->format( $format );
	}


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

		$params['timestamp'] = self::format_api_time();
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
