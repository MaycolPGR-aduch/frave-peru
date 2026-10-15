<?php
/**
 * Leaves the Libro de Reclamaciones ready: site timezone America/Lima (sheet dates and
 * the 15 business days depend on it), a published page with the form, and the
 * provider's data in Reclamaciones → Ajustes, validated as the settings screen does.
 *
 * From the site folder, with the plugin's clone (bin/ is not deployed with the plugin):
 *   wp eval-file ~/repos/frave-peru/bin/configurar-libro.php "RAZÓN SOCIAL" RUC "DOMICILIO" [CORREO]
 *
 * CORREO receives the notice of each new sheet (empty: the administrator's address).
 * Safe to run again: it reuses the page at /libro-de-reclamaciones/ (published or not),
 * adds the form only if missing and never deletes content.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'Run with WP-CLI only.' );
}
if ( ! defined( 'FRAVE_PERU_DIR' ) ) {
	WP_CLI::error( 'Activa el plugin Frave Perú antes de ejecutar este script.' );
}
// The settings screen (and its validation) is only loaded in the admin.
require_once FRAVE_PERU_DIR . 'includes/claims-admin.php';

list( $frave_peru_name, $frave_peru_ruc, $frave_peru_address ) = array_pad( array_map( 'trim', $args ), 3, '' );
$frave_peru_email = trim( (string) ( $args[3] ?? '' ) );
if ( '' === $frave_peru_name || '' === $frave_peru_ruc || '' === $frave_peru_address ) {
	WP_CLI::error( 'Faltan datos. Uso: wp eval-file configurar-libro.php "RAZÓN SOCIAL" RUC "DOMICILIO" [CORREO]' );
}
if ( ! frave_peru_is_valid_ruc( frave_peru_normalize_document( $frave_peru_ruc ) ) ) {
	WP_CLI::error( "El RUC {$frave_peru_ruc} no es válido (11 dígitos, prefijo 10, 15, 16, 17 o 20 y dígito verificador)." );
}
if ( '' !== $frave_peru_email && ! is_email( $frave_peru_email ) ) {
	WP_CLI::error( "El correo {$frave_peru_email} no es válido." );
}

// Sheet dates and deadlines use the site timezone.
if ( 'America/Lima' !== get_option( 'timezone_string' ) ) {
	update_option( 'timezone_string', 'America/Lima' );
	update_option( 'gmt_offset', '' );
	WP_CLI::log( 'Zona horaria: America/Lima.' );
}

$frave_peru_shortcode = '[frave_libro_reclamaciones]';
$frave_peru_page      = get_page_by_path( 'libro-de-reclamaciones', OBJECT, 'page' );
if ( $frave_peru_page instanceof WP_Post ) {
	$frave_peru_update = array( 'ID' => $frave_peru_page->ID );
	if ( ! has_shortcode( $frave_peru_page->post_content, 'frave_libro_reclamaciones' ) ) {
		$frave_peru_update['post_content'] = trim( $frave_peru_page->post_content . "\n\n" . $frave_peru_shortcode );
	}
	if ( 'publish' !== $frave_peru_page->post_status ) {
		$frave_peru_update['post_status'] = 'publish';
	}
	if ( count( $frave_peru_update ) > 1 ) {
		wp_update_post( $frave_peru_update );
	}
	$frave_peru_page_id = $frave_peru_page->ID;
	WP_CLI::log( "Página «{$frave_peru_page->post_title}» publicada con el formulario." );
} else {
	$frave_peru_page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Libro de Reclamaciones',
			'post_name'    => 'libro-de-reclamaciones',
			'post_content' => $frave_peru_shortcode,
		),
		true
	);
	if ( is_wp_error( $frave_peru_page_id ) ) {
		WP_CLI::error( 'No se pudo crear la página: ' . $frave_peru_page_id->get_error_message() );
	}
	WP_CLI::log( 'Página «Libro de Reclamaciones» creada.' );
}

update_option(
	'frave_peru_claims_settings',
	frave_peru_sanitize_claims_settings(
		array(
			'business_name' => $frave_peru_name,
			'ruc'           => $frave_peru_ruc,
			'address'       => $frave_peru_address,
			'page_id'       => $frave_peru_page_id,
			'notify_email'  => $frave_peru_email,
		)
	)
);
$frave_peru_saved = frave_peru_claims_settings();
WP_CLI::log( "Proveedor: {$frave_peru_saved['business_name']} · RUC {$frave_peru_saved['ruc']} · {$frave_peru_saved['address']}" );
WP_CLI::log( 'Avisos de hojas nuevas a: ' . ( '' !== $frave_peru_saved['notify_email'] ? $frave_peru_saved['notify_email'] : get_option( 'admin_email' ) . ' (correo del administrador)' ) );
WP_CLI::success( 'Libro de Reclamaciones listo en ' . frave_peru_claims_page_url() );
