<?php
/**
 * Integration
 *
 * @package SmartAccounts for WooCommerce
 * @author Konekt
 */

namespace Konekt\WooCommerce\SmartAccounts;

use Automattic\WooCommerce\Enums\ProductStatus;
use Automattic\WooCommerce\Enums\ProductType;
use WC_Admin_Notices;

defined( 'ABSPATH' ) || exit;

class Integration extends \WC_Integration {

	/**
	 * How many article codes fit in one articles:getwarehousequantities request.
	 *
	 * From the API documentation: "codes - urlencoded string of comma separated values of max 50
	 * article codes". It doubles as the page size of the scheduled sweep, so one page is one
	 * request.
	 */
	const API_CODES_PER_REQUEST = 50;

	/**
	 * How long a SKU refreshed on demand is left alone before it may be asked about again.
	 *
	 * SmartAccounts allows 1000 requests per 24 hours and 60 per minute. This cooldown is what
	 * keeps cart and checkout page reloads from turning into one request each.
	 */
	const STOCK_REFRESH_COOLDOWN = MINUTE_IN_SECONDS;

	/**
	 * Plugin instance
	 *
	 * @var \Konekt\WooCommerce\SmartAccounts\Plugin
	 */
	private $plugin = null;


	/** @var Konekt\WooCommerce\SmartAccounts\API API handler instance */
	protected $api = null;

	/**
	 * Integration constructor
	 */
	public function __construct() {
		$this->plugin             = konekt_wc_smartaccounts();
		$this->id                 = 'konekt_wc_smartaccounts';
		$this->method_title       = __( 'SmartAccounts', 'konekt-wc-smartaccounts' );
		$this->method_description = __( 'Supercharge your WooCommerce with SmartAccounts integration for seamless order information exchange.', 'konekt-wc-smartaccounts' );

		$this->init_form_fields();
		$this->init_settings();

		// Bind to the save action for the settings.
		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options' ) );

		// Credentials may have changed, so the cached bank list is no longer trustworthy.
		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'flush_payment_methods_cache' ), 20 );

		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );

		// Custom WC query.
		add_filter( 'woocommerce_product_data_store_cpt_get_products_query', array( $this, 'add_custom_product_query_var' ), 10, 2 );
	}


	/**
	 * Set integration settings fields
	 *
	 * @return void
	 */
	public function init_form_fields() {

		$this->form_fields = array(
			// API configuration.
			'api_section_title' => array(
				'title' => __( 'API configuration', 'konekt-wc-smartaccounts' ),
				'type'  => 'title',
			),

			'api_url' => array(
				'title'   => __( 'API URL', 'konekt-wc-smartaccounts' ),
				'type'    => 'text',
				'default' => '',
			),

			'api_public_key' => array(
				'title'   => __( 'API public key', 'konekt-wc-smartaccounts' ),
				'type'    => 'text',
				'default' => '',
			),

			'api_private_key' => array(
				'title'   => __( 'API private key', 'konekt-wc-smartaccounts' ),
				'type'    => 'text',
				'default' => '',
			),

			// Sync configuration.
			'sync_section_title' => array(
				'title' => __( 'Sync configuration', 'konekt-wc-smartaccounts' ),
				'type'  => 'title',
			),

			'sync_enabled' => array(
				'title'       => __( 'Sync enabled', 'konekt-wc-smartaccounts' ),
				'type'        => 'checkbox',
				'description' => __( 'Choose whether product syncronisation will start right away.', 'konekt-wc-smartaccounts' ),
				'default'     => 'no',
			),

			'sync_prices' => array(
				'title'       => __( 'Sync prices', 'konekt-wc-smartaccounts' ),
				'type'        => 'checkbox',
				'description' => __( 'Choose whether product prices from API will be used for product prices in store.', 'konekt-wc-smartaccounts' ),
				'default'     => 'no',
			),

			'stock_sync_enabled' => array(
				'title'       => __( 'Sync stock', 'konekt-wc-smartaccounts' ),
				'type'        => 'checkbox',
				'description' => __( 'Choose whether product stock will be synced.', 'konekt-wc-smartaccounts' ),
				'default'     => 'no',
			),

			// Invoices.
			'invoices_section_title' => array(
				'title' => __( 'Invoices configuration', 'konekt-wc-smartaccounts' ),
				'type'  => 'title',
			),

			'invoice_sync_allowed' => array(
				'title'   => __( 'Invoices', 'konekt-wc-smartaccounts' ),
				'type'    => 'checkbox',
				'default' => 'no',
				'value'   => 'yes',
				'label'   => __( 'Allow sending invoices to SmartAccounts', 'konekt-wc-smartaccounts' ),
			),

			'invoice_sync_status' => array(
				'title'       => __( 'Order status', 'konekt-wc-smartaccounts' ),
				'type'        => 'select',
				'default'     => 'processing',
				'options'     => array(
					'processing' => __( 'Processing', 'woocommerce' ),
					'completed'  => __( 'Completed', 'woocommerce' ),
				),
				'description' => __( 'This determines which order status is needed to be sent to SmartAccounts', 'konekt-wc-smartaccounts' ),
			),

			'invoice_shipping_sku' => array(
				'title'   => __( 'Shipping SKU', 'konekt-wc-smartaccounts' ),
				'type'    => 'text',
				'default' => '',
			),

			'invoice_private_person_id' => array(
				'title'   => __( 'Private person customer ID', 'konekt-wc-smartaccounts' ),
				'type'    => 'text',
				'default' => '',
			),

			'invoice_warehouse_id' => array(
				'title'   => __( 'Warehouse', 'konekt-wc-smartaccounts' ),
				'type'    => 'text',
				'default' => '',
			),

			'invoice_note_text' => array(
				'title'   => __( 'Invoice note text', 'konekt-wc-smartaccounts' ),
				'type'    => 'text',
				// translators: %s order number.
				'default' => __( 'Order #%s', 'konekt-wc-smartaccounts' ),
			),

			// Advanced.
			'advanced_section_title' => array(
				'title' => __( 'Advanced configuration', 'konekt-wc-smartaccounts' ),
				'type'  => 'title',
			),

			'save_api_messages_to_notes' => array(
				'title'   => __( 'Messages', 'konekt-wc-smartaccounts' ),
				'type'    => 'checkbox',
				'default' => 'no',
				'value'   => 'yes',
				'label'   => __( 'Save messages from API to order notes (privately).', 'konekt-wc-smartaccounts' ),
			),
		);

		if ( $this->have_api_credentials() ) {

			$this->form_fields['payment_methods'] = array(
				'title'       => __( 'Payment methods', 'konekt-wc-smartaccounts' ),
				'type'        => 'payment_methods_mapping_table',
				'description' => __( 'Map WooCommerce payment methods to SmartAccounts banks.', 'konekt-wc-smartaccounts' ),
			);
		}
	}


	/**
	 * Checks if API username and password have been set
	 *
	 * @return bool
	 */
	private function have_api_credentials() {

		return $this->get_option( 'api_public_key' ) && $this->get_option( 'api_private_key' ) && $this->get_option( 'api_url' );
	}


	/**
	 * Main initialization
	 *
	 * @return void
	 */
	public function init() {

		if ( $this->have_api_credentials() ) {
			$this->schedule_cron();

			if ( $this->is_stock_sync_enabled() ) {
				// Top up the cart's own SKUs just before WooCommerce validates them against stock.
				add_action( 'woocommerce_check_cart_items', array( $this, 'refresh_cart_stock' ), 5 );
			}

			if ( 'yes' === $this->get_option( 'invoice_sync_allowed', 'no' ) ) {
				add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_create_invoice' ), 20, 4 );
			}

			// Add "Submit again to SmartAccounts".
			add_filter( 'woocommerce_order_actions', array( $this, 'add_order_view_action' ), 90, 1 );
			add_action( 'woocommerce_order_action_wc_' . $this->get_plugin()->get_id() . '_submit_order_action', array( $this, 'process_order_submit_action' ), 90, 1 );
		}
	}


	/**
	 * Admin initialization
	 *
	 * @return void
	 */
	public function admin_init() {

		// Product ID meta field.
		add_action( 'woocommerce_process_product_meta', array( $this, 'process_product_smartaccounts_id_data_field' ), 10, 2 );
		add_action( 'woocommerce_product_options_inventory_product_data', array( $this, 'add_product_external_id_data_field' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'add_variation_product_external_id_data_field' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'process_variation_product_smartaccounts_id_data_field' ), 10, 2 );

		// Manual update handling
		add_action( 'woocommerce_product_options_inventory_product_data', array( $this, 'add_product_update_data_field' ) );

		if ( isset( $_GET['update_source'] ) && $this->id === $_GET['update_source'] ) {
			// This writes stock and calls the API, so it needs the same guards as any admin write.
			if ( ! current_user_can( 'edit_products' ) ) {
				return;
			}

			if ( ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', $this->id . '_update_source' ) ) {
				return;
			}

			$product_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
			$product    = wc_get_product( $product_id );

			if ( $product && $product->get_sku() ) {
				$api   = $this->get_api();
				$stock = $api->get_article_stock( $product->get_sku() );

				if ( ! empty( $stock ) ) {
					if ( 'OK' === $stock->status ) {
						$new_stock_count = wc_stock_amount( $stock->quantity );

						$product->set_manage_stock( true );
						$product->set_stock_quantity( $new_stock_count );

						if ( $new_stock_count > 0 ) {
							$product->set_stock_status( 'instock' );
						}

						if ( ! empty( $product->get_changes() ) ) {
							$product->save();
						}
					}
				}
			}
		}
	}


	/**
	 * Schedule cron
	 *
	 * @return void
	 */
	public function schedule_cron() {

		if ( ! $this->get_plugin()->has_scheduled_action( 'continuous_job', array() ) ) {
			$this->get_plugin()->schedule_action( 'continuous_job', array(), 5 * MINUTE_IN_SECONDS );
		}

		if ( $this->is_stock_sync_enabled() ) {
			$this->get_plugin()->hook_action( 'continuous_job', array( $this, 'continuous_job_hook' ) );
		}
	}


	public function continuous_job_hook() {

		$this->get_plugin()->log( 'Start continuous job hook.' );

		$page = (int) $this->get_plugin()->get_option( 'sync_page_number' );

		if ( ! $page ) {
			$page = 1;
		}

		$update = $this->run_products_stock_update( $page );

		if ( true === $update ) {
			$this->get_plugin()->update_option( 'sync_page_number', 1 );
		} elseif ( false === $update ) {
			$this->get_plugin()->update_option( 'sync_page_number', $page + 1 );
		}
	}


	public function run_products_stock_update( int $page ) {

		$this->get_plugin()->log_action( sprintf( 'Fetching products for an update, page %d.', $page ), 'update-products' );

		$args = array(
			'type'     => array( ProductType::SIMPLE, ProductType::VARIABLE, ProductType::VARIATION ),
			'return'   => 'ids',
			'limit'    => self::API_CODES_PER_REQUEST,
			'order'    => 'ASC',
			'orderby'  => 'ID',
			'status'   => ProductStatus::PUBLISH,
			'paginate' => true,
			'page'     => $page,
			'has_sku'  => true,
		);

		if ( function_exists( 'pll_default_language' ) ) {
			$args['lang'] = pll_default_language();
		}

		$results = wc_get_products( $args );

		if ( ! empty( $results->products ) ) {
			$products = array();

			foreach ( $results->products as $product_id ) {
				$product_id = $this->get_wpml_original_post_id( $product_id );
				$product    = wc_get_product( $product_id );

				if ( $product && '' !== $product->get_sku() ) {
					$products[ $product->get_sku() ] = $product;
				}
			}

			$this->sync_stock_for_products( $products );
		}

		$this->get_plugin()->log_action( sprintf( 'Updated %d products, page %d of %d. Total products %d.', count( $results->products ), $page, $results->max_num_pages, $results->total ), 'update-products' );

		if ( $results->max_num_pages > $page ) {
			return false;
		}

		/* At the last page — or past it, if the catalogue shrank since the run started, or if
		   there are no published products at all. Either way the counter has to go back to 1,
		   otherwise it climbs forever and every later run queries an empty page. */
		$this->get_plugin()->log_action( sprintf( 'End of product updates. Updated total of %d.', $results->total ), 'update-products' );

		return true;
	}


	/**
	 * Apply SmartAccounts warehouse quantities to a set of products.
	 *
	 * @param \WC_Product[] $products Products keyed by SKU. At most API_CODES_PER_REQUEST of them.
	 *
	 * @return int Number of products whose stock actually changed.
	 */
	protected function sync_stock_for_products( array $products ) {

		if ( empty( $products ) ) {
			return 0;
		}

		$stocks = $this->get_api()->get_article_stock( array_keys( $products ) );

		if ( empty( $stocks ) ) {
			return 0;
		}

		$updated = 0;

		foreach ( $stocks as $product_stock ) {
			if ( 'OK' !== $product_stock->status || empty( $product_stock->code ) ) {
				continue;
			}

			if ( ! array_key_exists( $product_stock->code, $products ) ) {
				$this->get_plugin()->log_action( sprintf( 'Product %s not found.', $product_stock->code ), 'update-products' );

				continue;
			}

			$product         = $products[ $product_stock->code ];
			$new_stock_count = wc_stock_amount( $product_stock->quantity );

			$product->set_manage_stock( true );
			$product->set_stock_quantity( $new_stock_count );

			if ( $new_stock_count > 0 ) {
				$product->set_stock_status( 'instock' );
			}

			if ( ! empty( $product->get_changes() ) ) {
				$product->save();

				$updated++;
			}
		}

		return $updated;
	}


	/**
	 * Refresh stock for a specific set of SKUs, on demand.
	 *
	 * This is the "near-live" half of stock syncing. The scheduled sweep keeps the whole
	 * catalogue roughly current, which is accurate enough to render a product page; this tops up
	 * the handful of SKUs a shopper is actually about to buy, where being wrong means overselling.
	 *
	 * It is deliberately not called on product reads. SmartAccounts allows 1000 requests per 24
	 * hours, so a per-read lookup would be exhausted by a single crawler pass over the catalogue.
	 * Here the cost is bounded twice over: one request covers up to 50 codes, and a SKU refreshed
	 * within the cooldown is not asked about again, so reloading the cart page costs nothing.
	 *
	 * @param string[] $skus
	 *
	 * @return int Number of products whose stock actually changed.
	 */
	public function refresh_stock_for_skus( array $skus ) {

		$skus = array_slice( array_unique( array_filter( $skus ) ), 0, self::API_CODES_PER_REQUEST );

		if ( empty( $skus ) ) {
			return 0;
		}

		$products = array();

		foreach ( $skus as $sku ) {
			if ( false !== $this->get_plugin()->get_cache( 'stock_checked_' . md5( $sku ) ) ) {
				continue;
			}

			$product_id = wc_get_product_id_by_sku( $sku );
			$product    = $product_id ? wc_get_product( $product_id ) : null;

			if ( $product ) {
				$products[ $sku ] = $product;
			}

			// Marked before the call, so a failing API does not turn every reload into a retry.
			$this->get_plugin()->set_cache( 'stock_checked_' . md5( $sku ), 1, self::STOCK_REFRESH_COOLDOWN );
		}

		return $this->sync_stock_for_products( $products );
	}


	/**
	 * Refresh stock for everything in the cart before it is validated.
	 *
	 * Runs on the cart and checkout pages. WooCommerce re-validates the cart against stock right
	 * after this, so the quantities it checks are the ones SmartAccounts has, not the ones the
	 * last sweep left behind.
	 *
	 * @return void
	 */
	public function refresh_cart_stock() {

		if ( ! $this->is_stock_sync_enabled() || is_null( WC()->cart ) ) {
			return;
		}

		$skus = array();

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( ! empty( $cart_item['data'] ) && $cart_item['data'] instanceof \WC_Product ) {
				$skus[] = $cart_item['data']->get_sku();
			}
		}

		$this->refresh_stock_for_skus( $skus );
	}


	/**
	 * Whether stock syncing is switched on.
	 *
	 * @return bool
	 */
	public function is_stock_sync_enabled() {

		return 'yes' === $this->get_option( 'sync_enabled', 'no' ) && 'yes' === $this->get_option( 'stock_sync_enabled', 'no' );
	}


	public function add_order_view_action( $actions ) {
		// Add custom action
		$actions['wc_' . $this->get_plugin()->get_id() . '_submit_order_action'] = __( 'Submit order to SmartAccounts', 'konekt-wc-smartaccounts' );

		return $actions;
	}


	public function process_order_submit_action( $order ) {
		if ( ! is_object( $order ) ) {
			$order = wc_get_order( $order );
		}

		// Submit manually
		$this->maybe_create_invoice( $order->get_id(), $this->get_option( 'invoice_sync_status', 'processing' ), $this->get_option( 'invoice_sync_status', 'processing' ), $order );
	}


	/**
	 * Create invoice (if order status is okay)
	 *
	 * @param integer $order_id
	 * @param string $order_old_status
	 * @param string $order_new_status
	 * @param \WC_Order $order
	 *
	 * @return void
	 */
	public function maybe_create_invoice( $order_id, $order_old_status, $order_new_status, $order ) {

		if ( $order_new_status !== $this->get_option( 'invoice_sync_status', 'processing' ) ) {
			return;
		}

		$customer_id = $this->get_option( 'invoice_private_person_id' );

		if ( $order->get_billing_company() ) {
			/* TODO: look the company up in SmartAccounts and create it when it is not there yet.
			   API::get_customer_code() and API::create_customer() do not exist yet — writing them
			   needs the SmartAccounts client endpoints. Until then a company order is invoiced
			   against the private-person client, which is wrong in the books, so say so out loud
			   rather than letting it pass silently. */
			$this->get_plugin()->log_action(
				sprintf(
					'Order %s is a company order (%s) but B2B customer lookup is not implemented; invoicing it against the private person client ID instead.',
					$order->get_order_number(),
					$order->get_billing_company()
				),
				'invoices'
			);
		}

		$this->get_api()->create_invoice( $order, $customer_id );
	}


	public function get_wpml_original_post_id( $post_id, $type = 'post_product' ) {
		global $sitepress;

		if ( $sitepress && defined( 'ICL_LANGUAGE_CODE' ) ) {
			$trid         = $sitepress->get_element_trid( $post_id, $type );
			$translations = $sitepress->get_element_translations( $trid, $type );

			if ( ! empty( $translations ) ) {
				foreach ( $translations as $translation ) {
					if ( $translation->original ) {
						$post_id = $translation->element_id;

						break;
					}
				}
			}
		} elseif ( function_exists( 'pll_get_post' ) ) {
			return pll_get_post( $post_id, pll_default_language() );
		}

		return $post_id;
	}


	/**
	 * Add custom field to product data to show external API ID
	 *
	 * @return void
	 */
	public function add_product_external_id_data_field() {
		global $product_object;

		if ( $product_object->is_type( 'variable' ) ) {
			return;
		}

		?>
		<div class="options_group options-group__smartaccounts">
			<?php
				woocommerce_wp_text_input(
					array(
						'id'    => '_smartaccounts_id',
						'value' => $this->get_product_external_id( $product_object ),
						'label' => $this->get_method_title() . ' ID',
					)
				);
			?>
		</div>

		<?php
	}


	public function add_variation_product_external_id_data_field( $loop, $data, $variation ) {
		echo '<div class="options_group form-row form-row-full">';

		// SmartAccounts ID field.
		woocommerce_wp_text_input(
			array(
				'id'    => "_smartaccounts_id[{$variation->ID}]",
				'label' => __( 'SmartAccounts ID', 'konekt-wc-smartaccounts' ),
				'value' => $this->get_product_external_id( $variation->ID ),
			)
		);

		echo '</div>';
	}


	/**
	 * Add custom button for manual product data update
	 *
	 * @return void
	 */
	public function add_product_update_data_field() {
		?>
		<div class="options_group options-group__<?php echo esc_attr( $this->id ); ?>">
			<p class="form-field">
				<label><?php echo esc_html( $this->get_method_title() ); ?></label>
				<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'update_source', $this->id ), $this->id . '_update_source' ) ); ?>" class="button"><?php esc_html_e( 'Update data', 'konekt-wc-smartaccounts' ); ?></a>
			</p>
		</div>
		<?php
	}


	/**
	 * Processed product metadata
	 *
	 * @param integer $id Product id.
	 * @param WP_Post $post Post object.
	 *
	 * @return void
	 */
	public function process_product_smartaccounts_id_data_field( $id, $post ) {
		$product = wc_get_product( $id );

		if ( $product ) {
			$external_id = isset( $_POST['_smartaccounts_id'] ) ? sanitize_text_field( wp_unslash( $_POST['_smartaccounts_id'] ) ) : ''; // WPCS: CSRF ok.

			// Save external ID.
			$product->update_meta_data( '_smartaccounts_id', $external_id );
			$product->save();
		}
	}


	public function process_variation_product_smartaccounts_id_data_field( $id, $loop ) {
		$product = wc_get_product( $id );

		if ( $product ) {
			$external_id = isset( $_POST['_smartaccounts_id'] ) && isset( $_POST['_smartaccounts_id'][ $id ] ) ? sanitize_text_field( wp_unslash( $_POST['_smartaccounts_id'][ $id ] ) ) : ''; // WPCS: CSRF ok.

			// Save external ID.
			$product->update_meta_data( '_smartaccounts_id', $external_id );
			$product->save();
		}
	}

	public function generate_payment_methods_mapping_table_html( $key, $data ) {
		$field_key     = $this->get_field_key( $key );
		$default_args  = array(
			'title'             => '',
			'disabled'          => false,
			'class'             => '',
			'css'               => '',
			'placeholder'       => '',
			'desc_tip'          => false,
			'description'       => '',
			'custom_attributes' => array(),
		);
		$data          = wp_parse_args( $data, $default_args );
		$row_counter   = 0;
		$values        = (array) $this->get_option( $key, array() );
		$payment_types = $this->get_api_payment_methods();

		ob_start();
		?>

		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?> <?php echo $this->get_tooltip_html( $data ); // WPCS: XSS ok. ?></label>
			</th>
			<td class="forminp">

				<?php echo $this->get_description_html( $data ); // WPCS: XSS ok. ?>

				<table class="form-table">

					<thead>
						<tr>
							<td style="width: 35px;"><strong>#</strong></td>
							<td><strong><?php esc_html_e( 'Payment method', 'konekt-wc-smartaccounts' ); ?></strong></td>
							<td><strong><?php esc_html_e( 'Bank', 'konekt-wc-smartaccounts' ); ?></strong></td>
						</tr>
					</thead>
					<tbody>

						<?php foreach ( WC()->payment_gateways()->payment_gateways() as $gateway_id => $gateway ) : ?>

							<?php
							$row_counter++;

							$value = $values[ $gateway_id ] ?? false;
							?>

							<tr>
								<td><?php echo $row_counter; ?>.</td>
								<td><?php echo esc_html( $gateway->get_title() ); ?></td>
								<td>
									<select name="<?php echo esc_attr( $field_key ); ?>[<?php echo esc_attr( $gateway_id ); ?>]">
										<option value="">- <?php esc_html_e( 'Do not overwrite', 'konekt-wc-smartaccounts' ); ?> -</option>

										<?php foreach ( $payment_types as $payment_method ) : ?>
											<option value="<?php echo esc_attr( $payment_method->name ); ?>" <?php selected( $value, $payment_method->name, true ); ?>><?php echo esc_html( $payment_method->name ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>

						<?php endforeach; ?>


						<?php
						$row_counter++;

						$value = $values['none'] ?? false;
						?>

						<tr>
							<td><?php echo $row_counter; ?>.</td>
							<td><?php echo esc_html( __( 'No payment' ) ); ?></td>
							<td>
								<select name="<?php echo esc_attr( $field_key ); ?>[none]">
									<option value="">- <?php esc_html_e( 'Do not overwrite', 'konekt-wc-smartaccounts' ); ?> -</option>

									<?php foreach ( $payment_types as $payment_method ) : ?>
										<option value="<?php echo esc_attr( $payment_method->name ); ?>" <?php selected( $value, $payment_method->name, true ); ?>><?php echo esc_html( $payment_method->name ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>

					</tbody>

				</table>
			</td>
		</tr>

		<?php
		return ob_get_clean();
	}


	/**
	 * Validate payment_methods mapping table field.
	 *
	 * @param  string $key Field key.
	 * @param  string $value Posted Value.
	 * @return string|array
	 */
	public function validate_payment_methods_mapping_table_field( $key, $value ) {

		return $this->validate_multiselect_field( $key, $value );
	}


	/**
	 * Get the SmartAccounts payment methods (banks) from the API, cached.
	 *
	 * The settings screen renders this list on every load, so an uncached lookup means the
	 * page blocks on an HTTP round-trip each time — and hangs outright while the API is down,
	 * leaving no way to reach the setting that turns the integration off.
	 *
	 * @return array
	 */
	public function get_api_payment_methods() {

		$payment_methods = $this->get_plugin()->get_cache( 'payment_methods' );

		if ( false === $payment_methods ) {
			$payment_methods = $this->get_api()->get_payment_methods();

			// A failed lookup returns an empty list; caching that would hide every bank for a day.
			if ( ! empty( $payment_methods ) ) {
				$this->get_plugin()->set_cache( 'payment_methods', $payment_methods, DAY_IN_SECONDS );
			}
		}

		return (array) $payment_methods;
	}


	/**
	 * Drop the cached payment method list.
	 *
	 * @return void
	 */
	public function flush_payment_methods_cache() {

		$this->get_plugin()->delete_cache( 'payment_methods' );
	}


	public function get_matching_payment_method( $payment_method ) {
		$payment_methods = (array) $this->get_option( 'payment_methods', array() );

		if ( array_key_exists( $payment_method, $payment_methods ) ) {
			return $payment_methods[ $payment_method ];
		} else {
			return null;
		}
	}


	public function add_custom_product_query_var( $query, $query_vars ) {
		if ( ! empty( $query_vars['has_sku'] ) ) {

			/* Only a product with a SKU can be matched against a SmartAccounts article code, so
			   the sweep must not page through the ones without: on this catalogue that is 495 of
			   761 products, and every page of them costs an API request that can return nothing. */
			$query['meta_query'][] = array(
				'key'     => '_sku',
				'value'   => '',
				'compare' => '!=',
			);
		}

		if ( isset( $query_vars['smartaccounts_id'] ) ) {
			if ( is_array( $query_vars['smartaccounts_id'] ) ) {

				$query['meta_query'][] = array(
					'key'     => '_smartaccounts_id',
					'value'   => $query_vars['smartaccounts_id'],
					'compare' => 'IN',
				);

			} else {

				$query['meta_query'][] = array(
					'key'     => '_smartaccounts_id',
					'value'   => $query_vars['smartaccounts_id'],
					'compare' => '=',
				);
			}
		}

		return $query;
	}


	/**
	 * Get smartaccounts external ID
	 *
	 * @param WC_Product|integer $product Product.
	 *
	 * @return string
	 */
	public function get_product_external_id( $product ) {
		if ( is_integer( $product ) ) {
			$product = wc_get_product( $product );
		}

		return $product->get_meta( '_smartaccounts_id', true );
	}


	public function get_plugin() {
		return $this->plugin ? $this->plugin : konekt_wc_smartaccounts();
	}


	/**
	 * Gets the API handler instance.
	 *
	 * @since 1.0.0
	 *
	 * @return \Konekt\WooCommerce\SmartAccounts\API
	 */
	public function get_api() {

		if ( null === $this->api ) {
			$this->api = new API( $this );
		}

		return $this->api;
	}


}
