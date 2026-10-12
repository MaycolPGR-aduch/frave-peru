<?php
/**
 * What the site shows search engines and visitors about itself:
 * - Account names stay private: the public REST user list, author archives, the users
 *   sitemap and the author in oEmbed previews would reveal the login names of the staff.
 * - In catalog mode the cart, checkout and account pages only redirect to the catalog,
 *   so they leave the sitemap.
 *
 * @package FravePeru
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The user endpoints answer only to people who can list users. */
function frave_peru_hide_user_endpoints( array $endpoints ): array {
	if ( current_user_can( 'list_users' ) ) {
		return $endpoints;
	}
	foreach ( array_keys( $endpoints ) as $route ) {
		if ( str_starts_with( $route, '/wp/v2/users' ) ) {
			unset( $endpoints[ $route ] );
		}
	}
	return $endpoints;
}
add_filter( 'rest_endpoints', 'frave_peru_hide_user_endpoints' );

/** Author archives (and ?author=ID) lead to the home page instead of naming the account. */
function frave_peru_redirect_author_archives(): void {
	if ( is_author() || ( isset( $_GET['author'] ) && ! is_admin() ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only.
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
}
add_action( 'template_redirect', 'frave_peru_redirect_author_archives', 1 );

function frave_peru_sitemap_providers( $provider, string $name ) {
	return 'users' === $name ? false : $provider;
}
add_filter( 'wp_sitemaps_add_provider', 'frave_peru_sitemap_providers', 10, 2 );

function frave_peru_oembed_without_author( array $data ): array {
	unset( $data['author_name'], $data['author_url'] );
	return $data;
}
add_filter( 'oembed_response_data', 'frave_peru_oembed_without_author' );

/** In catalog mode the store pages redirect to the catalog: keep them out of the sitemap. */
function frave_peru_sitemap_without_store_pages( array $args, string $post_type ): array {
	if ( 'page' !== $post_type || ! frave_peru_catalog_mode() || ! function_exists( 'wc_get_page_id' ) ) {
		return $args;
	}
	$store_pages          = array_filter( array_map( 'wc_get_page_id', array( 'cart', 'checkout', 'myaccount' ) ), static fn( $id ) => $id > 0 );
	$args['post__not_in'] = array_merge( $args['post__not_in'] ?? array(), $store_pages );
	return $args;
}
add_filter( 'wp_sitemaps_posts_query_args', 'frave_peru_sitemap_without_store_pages', 10, 2 );
