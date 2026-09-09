<?php
/**
 * Integration
 *
 * @package SmartAccounts for WooCommerce
 * @author Konekt
 */

namespace Konekt\WooCommerce\SmartAccounts;

defined( 'ABSPATH' ) or exit;

class Product_Data_Store {


	public function read( $product ) {

		if ( 'yes' === $this->get_integration()->get_option( 'sync_enabled', 'no' ) && 'yes' === $this->get_integration()->get_option( 'stock_sync_enabled', 'no' ) ) {
			//$this->refetch_product_stock( $product );
		}
	}


	/**
	 * Fetch product stock from API and update it
	 *
	 * @param \WC_Product $product
	 *
	 * @return void
	 */
	private function refetch_product_stock( &$product ) {

		$article_stock = $this->get_plugin()->get_cache( 'stock_' . $product->get_sku() );

		if ( false === $article_stock ) {
			$article_stock = $this->get_api()->get_article_stock( $product->get_sku() );

			if ( empty( $article_stock ) ) {
				return;
			}

			$new_stock_count = wc_stock_amount( $article_stock->quantity );

			$product->set_manage_stock( true );
			$product->set_stock_quantity( $new_stock_count );

			if ( $new_stock_count > 0 ) {
				$product->set_stock_status( 'instock' );
			}

			if ( ! empty( $product->get_changes() ) ) {
				$product->save();
			}

			$this->get_plugin()->set_cache( 'stock_' . $product->get_sku(), $article_stock, HOUR_IN_SECONDS );
		}
	}


	/**
	 * Get plugin
	 *
	 * @return Konekt\WooCommerce\SmartAccounts\Plugin
	 */
	protected function get_plugin() {
		return konekt_wc_smartaccounts();
	}


	/**
	 * Get API connector
	 *
	 * @return Konekt\WooCommerce\SmartAccounts\API
	 */
	protected function get_api() {
		return $this->get_integration()->get_api();
	}


	/**
	 * Get integration
	 *
	 * @return Konekt\WooCommerce\SmartAccounts\Integration
	 */
	protected function get_integration() {
		return $this->get_plugin()->get_integration();
	}


}