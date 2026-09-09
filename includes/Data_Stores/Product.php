<?php
/**
 * Integration
 *
 * @package SmartAccounts for WooCommerce
 * @author Konekt
 */

namespace Konekt\WooCommerce\SmartAccounts\Data_Stores;

defined( 'ABSPATH' ) or exit;

class Product extends \WC_Product_Data_Store_CPT implements \WC_Object_Data_Store_Interface, \WC_Product_Data_Store_Interface {


	/**
	 * Store the reads and writes are delegated to.
	 *
	 * @var \Konekt\WooCommerce\SmartAccounts\Product_Data_Store
	 */
	protected $base;


	/**
	 * Construct
	 *
	 * @param \Konekt\WooCommerce\SmartAccounts\Product_Data_Store $base_store
	 */
	public function __construct( $base_store ) {
		$this->base = $base_store;
	}


	/**
	 * Read product data
	 *
	 * @param \WC_product $product
	 *
	 * @return void
	 */
	public function read( &$product ) {

		parent::read( $product );

		if ( ! empty( $product->get_sku() ) ) {

			$this->base->read( $product );
		}
	}


}