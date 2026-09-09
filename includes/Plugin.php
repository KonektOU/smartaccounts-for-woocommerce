<?php
/**
 * SmartAccounts for WooCommerce
 *
 * This source file is subject to the GNU General Public License v3.0
 * that is bundled with this package in the file license.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.html
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@skyverge.com so we can send you a copy immediately.
 *
 * @author    SkyVerge
 * @copyright Copyright (c) 2014-2019, SkyVerge, Inc.
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

namespace Konekt\WooCommerce\SmartAccounts;

defined( 'ABSPATH' ) || exit;

use SkyVerge\WooCommerce\PluginFramework\v5_15_2 as Framework;

/**
 * @since 1.0.0
 */
class Plugin extends Framework\SV_WC_Plugin {


	/** @var Plugin */
	protected static $instance;

	/** plugin version number */
	const VERSION = '1.0.0';

	/** plugin id */
	const PLUGIN_ID = 'konekt-wc-smartaccounts';

	/** @var string the integration class name */
	const INTEGRATION_CLASS = '\\Konekt\\WooCommerce\\SmartAccounts\\Integration';

	/** @var string the data store class name */
	const DATASTORE_CLASS = '\\Konekt\\WooCommerce\\SmartAccounts\\Product_Data_Store';

	/** @var \Konekt\WooCommerce\SmartAccounts\Integration the integration class instance */
	private $integration;

	/** @var string cache transient prefix */
	private $cache_prefix;

	/**
	 * Constructs the plugin.
	 *
	 * @since 1.0
	 */
	public function __construct() {

		parent::__construct(
			self::PLUGIN_ID,
			self::VERSION,
			array(
				'text_domain'        => 'konekt-wc-smartaccounts',
				'supported_features' => array(
					'hpos' => true,
				),
			)
		);

		$this->cache_prefix = self::PLUGIN_ID . '_';

		/* Built at init, not in the constructor: the wizard names its steps with
		   __(), and this runs while WordPress is still loading plugins, before the
		   locale is settled. Everything the wizard hooks runs after init anyway. */
		add_action( 'init', function () {
			$this->setup_wizard_handler = new Setup_Wizard( $this );
		}, 5 );
	}


	/**
	 * Initializes the plugin.
	 *
	 * @since 1.0.0
	 */
	public function init_plugin() {

		$this->load_integration();

		// Add integration
		add_filter( 'woocommerce_integrations', array( $this, 'load_integration' ) );

		// Add custom data store
		add_filter( 'woocommerce_data_stores', array( $this, 'load_product_data_store' ) );
	}


	public function load_product_data_store( $stores = array() ) {

		if ( ! class_exists( self::DATASTORE_CLASS ) ) {
			require_once $this->get_plugin_path() . '/includes/Product_Data_Store.php';
			require_once $this->get_plugin_path() . '/includes/Data_Stores/Product.php';
			require_once $this->get_plugin_path() . '/includes/Data_Stores/Product_Variable.php';
			require_once $this->get_plugin_path() . '/includes/Data_Stores/Product_Variation.php';
		}

		$base_store = self::DATASTORE_CLASS;

		$stores['product']           = new Data_Stores\Product( new $base_store() );
		$stores['product-variable']  = new Data_Stores\Product_Variable( new $base_store() );
		$stores['product-variation'] = new Data_Stores\Product_Variation( new $base_store() );

		return $stores;
	}


	/**
	 * Schedule action-scheduler action
	 *
	 * @param string $action
	 * @param array $data
	 * @param bool $recurring
	 * @param integer|null $next_run
	 *
	 * @return void
	 */
	public function schedule_action( $action, $data = array(), $recurring = null, $next_run = null ) {

		if ( ! as_next_scheduled_action( $this->get_id() . '_' . $action, $data, $this->get_id() ) ) {
			if ( null !== $recurring || null !== $recurring ) {
				as_schedule_recurring_action( $next_run ?? time(), $recurring, $this->get_id() . '_' . $action, $data, $this->get_id() );
			} else {
				as_enqueue_async_action( $this->get_id() . '_' . $action, $data, $this->get_id() );
			}
		}
	}


	public function has_scheduled_action( $action, $data = array() ) {
		return as_has_scheduled_action( $this->get_id() . '_' . $action, $data, $this->get_id() );
	}


	public function hook_action( $action, $hook, $priority = 10, $accepted_args = 1 ) {
		add_action( $this->get_id() . '_' . $action, $hook, $priority, $accepted_args );
	}


	public function log_action( $message, $action = null ) {
		if ( $action ) {
			$this->log( $message, $this->get_id() . '_' . $action );
		} else {
			$this->log( $message );
		}
	}


	public function unschedule_all_actions( $action, $data = null ) {
		as_unschedule_all_actions( $this->get_id() . '_' . $action, $data, $this->get_id() );
	}


	public function get_option( $name ) {
		return get_option( $this->get_id() . '_' . $name );
	}


	public function update_option( $name, $value ) {
		update_option( $this->get_id() . '_' . $name, $value, 'no' );
	}

	/**
	 * Get cached item
	 *
	 * @param string $cache_key
	 *
	 * @return mixed
	 */
	public function get_cache( $cache_key ) {
		return get_transient( $this->cache_prefix . $cache_key );
	}


	/**
	 * Add item to cache
	 *
	 * @param string $cache_key
	 * @param mixed $data
	 * @param int $expiration
	 *
	 * @return bool
	 */
	public function set_cache( $cache_key, $data, $expiration ) {
		return set_transient( $this->cache_prefix . $cache_key, $data, $expiration );
	}


	/**
	 * Delete item from cache
	 *
	 * @param string $cache_key
	 *
	 * @return bool
	 */
	public function delete_cache( $cache_key ) {
		return delete_transient( $this->cache_prefix . $cache_key );
	}

	/**
	 * Get prefixed meta key for order meta
	 *
	 * @param string $meta_key
	 *
	 * @return string
	 */
	public function get_meta_key( $meta_key ) {
		return $this->get_id() . '_' . $meta_key;
	}


	/**
	 * Add order meta
	 *
	 * @param \WC_Order $order
	 * @param string|array $meta
	 * @param string $value
	 *
	 * @return void
	 */
	public function add_order_meta( \WC_Order $order, $meta, $value = null ) {

		if ( is_array( $meta ) ) {
			foreach ( $meta as $key => $value ) {
				$order->update_meta_data( $this->get_meta_key( $key ), $value );
			}

		} elseif ( is_array( $value ) ) {
			foreach ( $value as $meta_value ) {
				$order->add_meta_data( $this->get_meta_key( $meta ), $meta_value, false );
			}
		} else {
			$order->update_meta_data( $this->get_meta_key( $meta ), $value );
		}

		$order->save_meta_data();
	}


	/**
	 * Get order meta
	 *
	 * @param \WC_Order $order
	 * @param string|array $meta
	 * @param string $value
	 *
	 * @return void
	 */
	public function get_order_meta( \WC_Order $order, $meta_key ) {

		return $order->get_meta( $this->get_meta_key( $meta_key ), true );
	}


	/**
	 * Remove order meta
	 *
	 * @param WC_Order $order
	 * @param array $meta_keys
	 *
	 * @return void
	 */
	public function remove_order_meta( \WC_Order $order, $meta_keys ) {

		foreach ( $meta_keys as $meta_key ) {
			$order->delete_meta_data( $this->get_meta_key( $meta_key ) );
		}

		$order->save_meta_data();
	}


	/**
	 * Add note to order
	 *
	 * @param \WC_Order $order
	 * @param string $message
	 *
	 * @return void
	 */
	public function add_order_note( \WC_Order $order, $message ) {

		$order->add_order_note( sprintf( '%s: %s', $this->get_integration()->get_method_title(), $message ) );
	}


	/**
	 * Loads integration
	 *
	 * @param array $integrations
	 *
	 * @return array
	 */
	public function load_integration( $integrations = array() ) {

		if ( ! class_exists( self::INTEGRATION_CLASS ) ) {
			require_once $this->get_plugin_path() . '/includes/Integration.php';
		}

		if ( ! in_array( self::INTEGRATION_CLASS, $integrations, true ) ) {
			$integrations[ self::PLUGIN_ID ] = self::INTEGRATION_CLASS;
		}

		return $integrations;
	}


	/**
	 * Returns the integration class instance.
	 *
	 * @since 1.0.0
	 *
	 * @return \Konekt\WooCommerce\Merit_Aktiva\Integration the integration class instance
	 */
	public function get_integration() {

		if ( null === $this->integration ) {

			$integrations = null === WC()->integrations ? array() : WC()->integrations->get_integrations();
			$integration  = self::INTEGRATION_CLASS;

			if ( isset( $integrations[ self::PLUGIN_ID ] ) && $integrations[ self::PLUGIN_ID ] instanceof $integration ) {

				$this->integration = $integrations[ self::PLUGIN_ID ];

			} else {

				$this->load_integration();

				$this->integration = new $integration();
			}
		}

		return $this->integration;
	}


	/**
	 * Gets the full path and filename of the plugin file.
	 *
	 * @since 1.0.0
	 *
	 * @return string the full path and filename of the plugin file
	 */
	protected function get_file() {

		return __DIR__;
	}


	/**
	 * Gets the plugin full name including "WooCommerce", ie "WooCommerce X".
	 *
	 * @since 1.0.0
	 *
	 * @return string plugin name
	 */
	public function get_plugin_name() {

		return __( 'SmartAccounts for WooCommerce', 'wc-plugin-framework' );
	}


	/**
	 * Initializes the lifecycle handler.
	 *
	 * @since 1.0.0
	 */
	protected function init_lifecycle_handler() {

		require_once $this->get_plugin_path() . '/includes/Lifecycle.php';

		$this->lifecycle_handler = new Lifecycle( $this );
	}


	/**
	 * Gets the main instance of Framework Plugin instance.
	 *
	 * Ensures only one instance is/can be loaded.
	 *
	 * @since 1.0.0
	 *
	 * @return Plugin
	 */
	public static function instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}