<?php
/**
 * Catalog mode: the store shows its products without selling them online.
 *
 * Frave sells through its contact channels for now. With the mode on, products cannot be
 * bought, prices are left out of every page, endpoint and markup WooCommerce exposes to
 * visitors, and the cart, checkout and account pages send visitors to the catalog.
 * Orders, checkout fields and store settings stay as they are, so turning the mode off
 * (WooCommerce → Ajustes → Productos) restores the store.
 *
 * Callbacks check the mode when they run, so the `frave_peru_catalog_mode` filter works
 * from a theme too.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const FRAVE_PERU_CATALOG_MODE_OPTION = 'frave_peru_catalog_mode';

/** On unless the setting was turned off: Frave does not sell online yet. */
function frave_peru_catalog_mode(): bool {
	return (bool) apply_filters( 'frave_peru_catalog_mode', 'no' !== get_option( FRAVE_PERU_CATALOG_MODE_OPTION, 'yes' ) );
}

/** Prices stay visible in the administrator (product list, orders); only visitors lose them. */
function frave_peru_catalog_mode_hides_prices(): bool {
	return frave_peru_catalog_mode() && ( ! is_admin() || wp_doing_ajax() );
}

/* ---------- Setting ---------- */

function frave_peru_catalog_mode_setting( array $settings, string $section_id = '' ): array {
	if ( '' !== $section_id ) {
		return $settings;
	}
	$catalog_mode = array(
		array(
			'title' => __( 'Modo catálogo', 'frave-peru' ),
			'type'  => 'title',
			'desc'  => __( 'Muestra los productos sin venderlos en línea: sin precios, carrito, checkout ni cuenta de cliente. Los clientes piden cotización por el formulario de contacto o WhatsApp. Al desactivarlo vuelve la tienda con su configuración.', 'frave-peru' ),
			'id'    => 'frave_peru_catalog_mode_options',
		),
		array(
			'title'   => __( 'Modo catálogo', 'frave-peru' ),
			'desc'    => __( 'Mostrar los productos sin venta en línea', 'frave-peru' ),
			'id'      => FRAVE_PERU_CATALOG_MODE_OPTION,
			'default' => 'yes',
			'type'    => 'checkbox',
		),
		array(
			'type' => 'sectionend',
			'id'   => 'frave_peru_catalog_mode_options',
		),
	);
	return array_merge( $catalog_mode, $settings );
}
add_filter( 'woocommerce_get_settings_products', 'frave_peru_catalog_mode_setting', 10, 2 );

/* ---------- Nothing can be bought ---------- */

/** Also empties carts saved before the mode was turned on: WooCommerce drops unpurchasable items. */
function frave_peru_catalog_mode_purchasable( bool $purchasable ): bool {
	return frave_peru_catalog_mode() ? false : $purchasable;
}
add_filter( 'woocommerce_is_purchasable', 'frave_peru_catalog_mode_purchasable' );
add_filter( 'woocommerce_variation_is_purchasable', 'frave_peru_catalog_mode_purchasable' );

/** Price, sale badge and buy buttons leave the product pages and listings. */
function frave_peru_catalog_mode_templates(): void {
	if ( ! frave_peru_catalog_mode() ) {
		return;
	}
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
	remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10 );
	remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );
}
add_action( 'wp', 'frave_peru_catalog_mode_templates' );

/** Reviews ask about a purchase that cannot happen online; the store setting is left as it is. */
function frave_peru_catalog_mode_product_tabs( array $tabs ): array {
	if ( frave_peru_catalog_mode() ) {
		unset( $tabs['reviews'] );
	}
	return $tabs;
}
add_filter( 'woocommerce_product_tabs', 'frave_peru_catalog_mode_product_tabs', 99 );

/** Cart, checkout and account have nothing to show: send visitors to the catalog. */
function frave_peru_catalog_mode_redirect(): void {
	if ( ! frave_peru_catalog_mode() || ! ( is_cart() || is_checkout() || is_account_page() ) ) {
		return;
	}
	// The archive link avoids the extra redirect of the shop page's ?page_id= URL with plain permalinks.
	$catalog = get_post_type_archive_link( 'product' );
	wp_safe_redirect( $catalog ? $catalog : wc_get_page_permalink( 'shop' ) );
	exit;
}
add_action( 'template_redirect', 'frave_peru_catalog_mode_redirect' );

/* ---------- No prices for visitors ---------- */

/** Covers every product type and every place that prints a price (listings, blocks, embeds). */
function frave_peru_catalog_mode_price_html( $price_html ) {
	return frave_peru_catalog_mode_hides_prices() ? '' : $price_html;
}
add_filter( 'woocommerce_get_price_html', 'frave_peru_catalog_mode_price_html', 99 );

/** "¡Oferta!" would tell visitors the price changed. */
function frave_peru_catalog_mode_sale_flash( $html ) {
	return frave_peru_catalog_mode() ? '' : $html;
}
add_filter( 'woocommerce_sale_flash', 'frave_peru_catalog_mode_sale_flash', 99 );

/** The public ?wc-ajax=get_variation endpoint returns raw variation prices. */
function frave_peru_catalog_mode_variation_data( array $data ): array {
	if ( frave_peru_catalog_mode_hides_prices() ) {
		unset( $data['display_price'], $data['display_regular_price'] );
		$data['price_html'] = '';
	}
	return $data;
}
add_filter( 'woocommerce_available_variation', 'frave_peru_catalog_mode_variation_data', 99 );

/** Product markup for search engines: offers carry the price. */
function frave_peru_catalog_mode_structured_data( array $markup ): array {
	if ( frave_peru_catalog_mode() ) {
		unset( $markup['offers'] );
	}
	return $markup;
}
add_filter( 'woocommerce_structured_data_product', 'frave_peru_catalog_mode_structured_data', 99 );

/** Sorting by price would reveal the order of prices. */
function frave_peru_catalog_mode_orderby_options( array $options ): array {
	if ( frave_peru_catalog_mode() ) {
		unset( $options['price'], $options['price-desc'] );
	}
	return $options;
}
add_filter( 'woocommerce_catalog_orderby', 'frave_peru_catalog_mode_orderby_options', 99 );
add_filter( 'woocommerce_default_catalog_orderby_options', 'frave_peru_catalog_mode_orderby_options', 99 );

function frave_peru_catalog_mode_default_orderby( $orderby ) {
	return frave_peru_catalog_mode() && in_array( $orderby, array( 'price', 'price-desc' ), true ) ? 'menu_order' : $orderby;
}
add_filter( 'woocommerce_default_catalog_orderby', 'frave_peru_catalog_mode_default_orderby', 99 );

/**
 * Price filters and price sorting in the URL would still filter or sort by price:
 * drop them before WooCommerce runs the catalog query.
 */
function frave_peru_catalog_mode_strip_price_args(): void {
	if ( ! frave_peru_catalog_mode() ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only catalog arguments.
	$remove  = array_values( array_intersect( array( 'min_price', 'max_price' ), array_keys( $_GET ) ) );
	$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
	// phpcs:enable
	if ( in_array( $orderby, array( 'price', 'price-desc' ), true ) ) {
		$remove[] = 'orderby';
	}
	if ( $remove ) {
		wp_safe_redirect( remove_query_arg( $remove ) );
		exit;
	}
}
add_action( 'template_redirect', 'frave_peru_catalog_mode_strip_price_args', 1 );

/**
 * The public Store API (/wp-json/wc/store) lists products with their prices and runs
 * carts and checkouts. Store managers keep it for product block previews in the editor.
 */
function frave_peru_catalog_mode_store_api( array $endpoints ): array {
	if ( ! frave_peru_catalog_mode() || current_user_can( 'manage_woocommerce' ) ) {
		return $endpoints;
	}
	foreach ( array_keys( $endpoints ) as $route ) {
		if ( str_starts_with( $route, '/wc/store' ) ) {
			unset( $endpoints[ $route ] );
		}
	}
	return $endpoints;
}
add_filter( 'rest_endpoints', 'frave_peru_catalog_mode_store_api', 99 );
