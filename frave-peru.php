<?php
/**
 * Plugin Name: Frave Perú
 * Description: Requisitos comerciales de Frave en Perú: modo catálogo (productos sin venta en línea), comprobante (boleta o factura) con DNI, CE, pasaporte o RUC en el checkout clásico de WooCommerce, Libro de Reclamaciones virtual y formulario de contacto.
 * Version: 1.3.0
 * Author: Frave
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 10.8
 * License: GPLv2 or later
 * Text Domain: frave-peru
 *
 * @package FravePeru
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FRAVE_PERU_VERSION', '1.3.0' );
define( 'FRAVE_PERU_FILE', __FILE__ );
define( 'FRAVE_PERU_DIR', plugin_dir_path( __FILE__ ) );
define( 'FRAVE_PERU_URL', plugin_dir_url( __FILE__ ) );

/** Order data is read through WC_Order, so HPOS is supported; the fields target the classic checkout only. */
function frave_peru_declare_compatibility(): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', FRAVE_PERU_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', FRAVE_PERU_FILE, false );
	}
}
add_action( 'before_woocommerce_init', 'frave_peru_declare_compatibility' );

function frave_peru_load(): void {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	require_once FRAVE_PERU_DIR . 'includes/catalog-mode.php';
	require_once FRAVE_PERU_DIR . 'includes/documents.php';
	require_once FRAVE_PERU_DIR . 'includes/checkout-fields.php';
	require_once FRAVE_PERU_DIR . 'includes/antispam.php';
	require_once FRAVE_PERU_DIR . 'includes/claims.php';
	require_once FRAVE_PERU_DIR . 'includes/claims-form.php';
	require_once FRAVE_PERU_DIR . 'includes/contact-form.php';

	if ( is_admin() ) {
		require_once FRAVE_PERU_DIR . 'includes/claims-admin.php';
		require_once FRAVE_PERU_DIR . 'includes/contact-admin.php';
	}
}
add_action( 'plugins_loaded', 'frave_peru_load' );
