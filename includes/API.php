<?php
/**
 * API functionality
 *
 * @package SmartAccounts for WooCommerce
 * @author Konekt
 */

namespace Konekt\WooCommerce\SmartAccounts;

use SkyVerge\WooCommerce\PluginFramework\v5_15_2 as Framework;

defined( 'ABSPATH' ) || exit;

class API extends Framework\SV_WC_API_Base {

	/** @var \Konekt\WooCommerce\SmartAccounts\Integration the integration class instance */
	private $integration;


	/**
	 * API constructor
	 *
	 * @param \Konekt\WooCommerce\SmartAccounts\Integration $integration
	 */
	public function __construct( $integration ) {

		$this->integration = $integration;

		$this->api_public_key  = $this->integration->get_option( 'api_public_key' );
		$this->api_private_key = $this->integration->get_option( 'api_private_key' );
		$this->request_uri     = $this->integration->get_option( 'api_url' );

		$this->set_request_content_type_header( 'application/json' );
		$this->set_request_accept_header( 'application/json' );

		$this->response_handler = API\Response::class;
	}


	/**
	 * Get products
	 *
	 * @param integer $last_sync
	 *
	 * @since 1.0
	 *
	 * @return object
	 */
	public function get_products( $last_sync = null ) {

		$products    = array();
		$page_number = 1;

		do {
			$params = array(
				'pageNumber' => $page_number,
				'type'       => 'WH',
			);

			if ( null !== $last_sync ) {
				$params['modifiedFrom'] = date_i18n( 'd.m.Y_H:i:s', $last_sync );
				$params['modifiedTo']   = date_i18n( 'd.m.Y_H:i:s' );
			}

			$result = $this->perform_request(
				$this->get_new_request(
					array(
						'method' => 'GET',
						'path'   => 'purchasesales/articles:get',
						'params' => $params,
					)
				)
			);

			foreach ( $result->response_data->articles as $product ) {
				if ( ! in_array( $product->type, array( 'WH' ), true ) ) {
					continue;
				}

				if ( false === $product->activeSales ) {
					continue;
				}

				$products[ $product->code ] = array(
					'code'        => $product->code,
					'description' => $product->description,
					'price'       => $product->priceSales,
					'vat'         => $product->vatPc,
				);
			}

			$page_number++;
		} while ( ! empty( $result->response_data ) && $result->response_data->hasMoreEntries );

		return $products;
	}

	/**
	 * Get article stock in warehouse
	 *
	 * @since 1.0
	 *
	 * @return null|object|array[object]
	 */
	public function get_article_stock( $product_sku ) {

		$multiple_skus = is_array( $product_sku );

		if ( $multiple_skus ) {
			$product_sku = implode( ',', array_filter( $product_sku ) );
		}

		$result = $this->perform_request(
			$this->get_new_request(
				array(
					'method' => 'GET',
					'path'   => 'purchasesales/articles:getwarehousequantities',
					'params' => array(
						'codes' => $product_sku,
					),
				)
			)
		);

		if ( 200 === $this->get_response_code() ) {
			$quantities = $result->quantities;

			return $multiple_skus ? $quantities : reset( $quantities );
		}

		return null;
	}

	/**
	 * Get payment methods
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public function get_payment_methods() {

		$result = $this->perform_request(
			$this->get_new_request(
				array(
					'method' => 'GET',
					'path'   => 'settings/paymentmethods:get',
				)
			)
		);

		if ( 200 === $this->get_response_code() ) {
			return $result->templates;
		}

		return array();
	}


	/**
	 * Create API invoice
	 *
	 * @param \WC_Order $order
	 * @param string $client_id
	 *
	 * @return void
	 */
	public function create_invoice( $order, $client_id ) {

		$invoice_data = array(
			'clientId'    => $client_id,
			'date'        => $order->get_date_created()->date( 'd.m.Y' ),
			'currency'    => $order->get_currency( 'edit' ),
			'totalAmount' => $order->get_total( 'edit' ),
			'dateUpdated' => $order->get_date_modified()->date( 'd.m.Y' ),
			'warehouseId' => $this->integration->get_option( 'invoice_warehouse_id' ),
		);

		$invoice_note = $this->integration->get_option( 'invoice_note_text' );

		if ( ! empty( $invoice_note ) ) {
			$invoice_data['invoiceNote'] = sprintf( $invoice_note, $order->get_order_number() );
		}

		if ( $order->is_paid() ) {
			$invoice_data['paymentMethod'] = $order->get_payment_method();
			$invoice_data['paymentAmount'] = $order->get_total( 'edit' );

			$matching_payment_method = $this->integration->get_matching_payment_method( $order->get_payment_method() );

			if ( ! empty( $matching_payment_method ) ) {
				$invoice_data['paymentMethod'] = $matching_payment_method;
			}
		}

		$invoice_data['rows'] = array();

		foreach ( $order->get_items( array( 'line_item', 'shipping' ) ) as $item ) {

			/** @var \WC_Order_Item_Product $item */
			if ( is_callable( array( $item, 'get_product' ) ) ) {

				$product = $item->get_product();

				$invoice_data['rows'][] = array(
					'code'        => $product->get_sku(),
					'description' => $product->get_name(),
					'price'       => wc_format_decimal( $item->get_total( 'edit' ) / $item->get_quantity() ),
					'quantity'    => $item->get_quantity(),
					'vatPc'       => $this->get_vat_percentage( $item ),
				);

			} elseif ( $item->is_type( 'shipping' ) ) {
				/** @var \WC_Order_Item_Shipping $item */

				$invoice_data['rows'][] = array(
					'code'        => $this->integration->get_option( 'invoice_shipping_sku', 'shipping' ),
					'description' => $item->get_name(),
					'price'       => $item->get_total( 'edit' ),
					'quantity'    => $item->get_quantity(),
					'vatPc'       => $this->get_vat_percentage( $item ),
				);
			}
		}

		$this->get_plugin()->log( print_r( $invoice_data, true ) );

		// Get external order ID.
		$order_external_id = $this->get_plugin()->get_order_meta( $order, 'invoice_id' );

		if ( ! empty( $order_external_id ) ) {
			$invoice_data['id'] = $order_external_id;
		}

		// Perform API request.
		$response = $this->perform_request(
			$this->get_new_request(
				array(
					'method' => 'POST',
					'path'   => 'purchasesales/clientinvoices:' . ( ! empty( $order_external_id ) ? 'edit' : 'add' ),
					'data'   => $invoice_data,
				)
			)
		);

		if ( 200 === $this->get_response_code() ) {
			// Save order and customer IDs from response.
			$this->get_plugin()->add_order_meta(
				$order,
				array(
					'invoice_id' => $response->invoiceId,
					'client_id'  => $response->clientId,
				)
			);

			// Add order note.
			$this->get_plugin()->add_order_note(
				$order,
				sprintf(
					/* translators: %s invoice number, %s invoice ID, %s customer ID */
					__( 'Created invoice no. %1$s with ID %2$s. Customer ID is %1$s.', 'konekt-wc-smartaccounts' ),
					$response->invoiceNumber,
					$response->invoiceId,
					$response->clientId,
				)
			);

			return array(
				'invoice_id'  => $response->InvoiceId,
				'invoice_no'  => $response->InvoiceNo,
				'customer_id' => $response->CustomerId,
			);
		} else {

			// Request failed.
			$this->get_plugin()->add_order_note(
				$order,
				__( 'Invoice generation failed.', 'konekt-wc-smartaccounts' )
			);

			if ( 'yes' === $this->integration->get_option( 'save_api_messages_to_notes', 'no' ) ) {
				$errors = $response->errors;

				if ( ! empty( $errors ) ) {
					foreach ( $errors as $error ) {
						$this->get_plugin()->add_order_note( $order, $error->message );
					}
				}
			}

			return null;
		}

	}


	/**
	 * Get VAT percentage for the order item
	 *
	 * @param \WC_Order_Item $order_item
	 *
	 * @return float|null
	 */
	public function get_vat_percentage( $order_item ) {

		$order     = $order_item->get_order();
		$tax_class = $order_item->get_tax_class();
		$tax_items = $order->get_items( 'tax' );

		foreach ( $tax_items as $tax_item ) {
			$tax_rate_id = $tax_item->get_rate_id();
			$tax_rates   = \WC_Tax::get_rates_for_tax_class( $tax_class );

			foreach ( $tax_rates as $rate_id => $tax_rate ) {
				if ( $rate_id == $tax_rate_id ) {
					return floatval( $tax_rate->tax_rate );
				}
			}
		}

		return 0;
	}


	/**
	 * Construct new API request
	 *
	 * @param array $args
	 *
	 * @return \Konekt\WooCommerce\SmartAccounts\API\Request
	 */
	protected function get_new_request( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'path'   => '',
				'params' => array(),
				'method' => 'POST',
				'data'   => array(),
			)
		);

		return new API\Request( $this->api_public_key, $this->api_private_key, $args['method'], $args['path'], $args['data'], $args['params'] );
	}


	/**
	 * Gets the request URL query.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected function get_request_query() {

		$query  = '';
		$params = $this->get_request()->get_params();

		if ( ! empty( $params ) ) {
			$query = http_build_query( $params, '', '&' );
		}

		return $query;
	}


	/**
	 * Get plugin
	 *
	 * @return \Konekt\WooCommerce\SmartAccounts\Plugin
	 */
	protected function get_plugin() {
		return \konekt_wc_smartaccounts();
	}


}