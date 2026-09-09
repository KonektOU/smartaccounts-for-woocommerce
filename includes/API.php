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
					// The product may have been deleted since the order was placed, in which
					// case get_product() is false and only the order item still knows the name.
					'code'        => $product ? $product->get_sku() : '',
					'description' => $product ? $product->get_name() : $item->get_name(),
					'price'       => wc_format_decimal( $this->get_item_unit_price( $item ) ),
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

			// The API answers in camelCase; read it once here so the note, the meta and the
			// return value cannot drift apart into different spellings of the same field.
			$invoice_id     = $response->invoiceId;
			$invoice_number = $response->invoiceNumber;
			$client_id      = $response->clientId;

			// Save order and customer IDs from response.
			$this->get_plugin()->add_order_meta(
				$order,
				array(
					'invoice_id' => $invoice_id,
					'client_id'  => $client_id,
				)
			);

			// Add order note.
			$this->get_plugin()->add_order_note(
				$order,
				sprintf(
					/* translators: %1$s invoice number, %2$s invoice ID, %3$s customer ID */
					__( 'Created invoice no. %1$s with ID %2$s. Customer ID is %3$s.', 'konekt-wc-smartaccounts' ),
					$invoice_number,
					$invoice_id,
					$client_id
				)
			);

			return array(
				'invoice_id'  => $invoice_id,
				'invoice_no'  => $invoice_number,
				'customer_id' => $client_id,
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
	 * Get the unit price for an order item.
	 *
	 * A line item can legitimately sit at quantity zero — a fully refunded line, or a quantity
	 * edited down in the admin — and dividing by it throws DivisionByZeroError on PHP 8, which
	 * would abort the order status transition the invoice is created from.
	 *
	 * @param \WC_Order_Item $order_item
	 *
	 * @return float
	 */
	protected function get_item_unit_price( $order_item ) {

		$quantity = (float) $order_item->get_quantity();

		if ( 0.0 === $quantity ) {
			return 0.0;
		}

		return (float) $order_item->get_total( 'edit' ) / $quantity;
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

		// The rates depend only on the tax class, so looking them up per tax item repeats the same query.
		$tax_rates = \WC_Tax::get_rates_for_tax_class( $tax_class );

		foreach ( $tax_items as $tax_item ) {
			$tax_rate_id = $tax_item->get_rate_id();

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