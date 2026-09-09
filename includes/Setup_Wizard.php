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
 * @author    Konekt
 * @copyright Copyright (c) 2025, Konekt ltd
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

namespace Konekt\WooCommerce\SmartAccounts;

defined( 'ABSPATH' ) || exit;

use SkyVerge\WooCommerce\PluginFramework\v5_15_2 as Framework;

class Setup_Wizard extends Framework\Admin\Setup_Wizard {


	public function register_steps() {
		$this->register_step(
			'start',
			__( 'Start', 'konekt-wc-smartaccounts' ),
			array( $this, 'render_start_step' ),
			array( $this, 'save_preferences' )
		);

		$this->register_step(
			'prices',
			__( 'Prices', 'konekt-wc-smartaccounts' ),
			array( $this, 'render_prices_step' ),
			array( $this, 'save_preferences' )
		);

		$this->register_step(
			'sync',
			__( 'Sync', 'konekt-wc-smartaccounts' ),
			array( $this, 'render_sync_step' ),
			array( $this, 'save_preferences' )
		);
	}


	protected function load_scripts_styles() {
		parent::load_scripts_styles();

		//wp_register_style( 'jquery-ui', '//ajax.googleapis.com/ajax/libs/jqueryui/1.8/themes/base/jquery-ui.css' );

		//wp_enqueue_style( 'konekt-wc-smartaccounts-setup-wizard', $this->get_plugin()->get_plugin_url() . '/assets/css/wizard.css' );
	}


	public function render_start_step() {
		$this->render_form_field(
			'api_url',
			array(
				'type'     => 'text',
				'label'    => __( 'API URL', 'konekt-wc-smartaccounts' ),
				'required' => true,
			),
			$this->get_plugin()->get_integration()->get_option( 'api_url', 'https://sa.smartaccounts.eu/api/' )
		);

		$this->render_form_field(
			'api_public_key',
			array(
				'type'     => 'text',
				'label'    => __( 'API public key', 'konekt-wc-smartaccounts' ),
				'required' => true,
			),
			$this->get_plugin()->get_integration()->get_option( 'api_public_key', '' )
		);

		$this->render_form_field(
			'api_private_key',
			array(
				'type'     => 'text',
				'label'    => __( 'API private key', 'konekt-wc-smartaccounts' ),
				'required' => true,
			),
			$this->get_plugin()->get_integration()->get_option( 'api_private_key', '' )
		);
	}


	public function render_prices_step() {

		$this->render_toggle_form_field(
			'sync_prices',
			array(
				'label'       => __( 'Sync prices', 'konekt-wc-smartaccounts' ),
				'description' => __( 'Choose whether product SRP prices will be used for product prices.', 'konekt-wc-smartaccounts' ),
				'class'       => array( 'sv-wc-plugin-admin-setup-control' ),
			),
			'yes' === $this->get_plugin()->get_integration()->get_option( 'sync_prices', 'yes' )
		);

		$this->render_form_field(
			'prices_rounding',
			array(
				'label'    => __( 'Rounding', 'konekt-wc-gunfifre' ),
				'type'     => 'select',
				'required' => true,
				'options'  => array(
					'disabled'   => __( 'Disabled', 'konekt-wc-smartaccounts' ),
					'nearest_90' => __( 'To nearest 90 cents', 'konekt-wc-smartaccounts' ),
					'full'       => __( 'To full', 'konekt-wc-smartaccounts' ),
				),
			),
			$this->get_plugin()->get_integration()->get_option( 'prices_rounding', 'disabled' )
		);
	}


	public function render_sync_step() {

		$this->render_toggle_form_field(
			'sync_enabled',
			array(
				'label'       => __( 'Enabled', 'konekt-wc-smartaccounts' ),
				'description' => __( 'Choose whether product syncronisation will start right away. You can enabled it later in the settings, for example after adding product IDs to required products.', 'konekt-wc-smartaccounts' ),
				'class'       => array( 'sv-wc-plugin-admin-setup-control' ),
			),
			'no' === $this->get_plugin()->get_integration()->get_option( 'sync_enabled', 'no' )
		);

		$this->render_form_field(
			'sync_frequency',
			array(
				'label'    => __( 'Sync frequency', 'konekt-wc-gunfifre' ),
				'type'     => 'select',
				'required' => true,
				'options'  => array(
					'twice_a_day' => __( 'Twice a day', 'konekt-wc-smartaccounts' ),
					'once_a_day'  => __( 'Once a day', 'konekt-wc-smartaccounts' ),
				),
			),
			$this->get_plugin()->get_integration()->get_option( 'sync_frequency', 'once_a_day' )
		);
	}


	public function save_preferences() {

		if ( ! empty( $_POST['api_url'] ) ) {
			$api_url = sanitize_url( wp_unslash( $_POST['api_url'] ) );

			$this->get_plugin()->get_integration()->update_option( 'api_url', $api_url );
		}

		if ( ! empty( $_POST['api_public_key'] ) ) {
			$api_public_key = sanitize_text_field( wp_unslash( $_POST['api_public_key'] ) );

			$this->get_plugin()->get_integration()->update_option( 'api_public_key', $api_public_key );
		}

		if ( ! empty( $_POST['api_private_key'] ) ) {
			$api_private_key = sanitize_text_field( wp_unslash( $_POST['api_private_key'] ) );

			$this->get_plugin()->get_integration()->update_option( 'api_private_key', $api_private_key );
		}

		if ( ! empty( $_POST['sync_prices'] ) ) {
			$sync_prices = sanitize_text_field( wp_unslash( $_POST['sync_prices'] ) );

			$this->get_plugin()->get_integration()->update_option( 'sync_prices', $sync_prices );
		}

		if ( ! empty( $_POST['prices_rounding'] ) ) {
			$prices_rounding = sanitize_text_field( wp_unslash( $_POST['prices_rounding'] ) );

			$this->get_plugin()->get_integration()->update_option( 'prices_rounding', $prices_rounding );
		}

		if ( ! empty( $_POST['sync_enabled'] ) ) {
			$sync_enabled = sanitize_text_field( wp_unslash( $_POST['sync_enabled'] ) );

			$this->get_plugin()->get_integration()->update_option( 'sync_enabled', $sync_enabled );
		}

		if ( ! empty( $_POST['sync_frequency'] ) ) {
			$sync_frequency = sanitize_text_field( wp_unslash( $_POST['sync_frequency'] ) );

			$this->get_plugin()->get_integration()->update_option( 'sync_frequency', $sync_frequency );
		}
	}


}
