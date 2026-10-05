<?php
/**
 * Boleta/factura selection with DNI, CE, passport or RUC in the classic checkout.
 *
 * Fields use the billing_ prefix, so WooCommerce stores them as order meta
 * (_billing_*) and customer meta (billing_*) and prefills them on the next order.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Rows shown for each voucher type; they are required only when their voucher is chosen. */
function frave_peru_conditional_fields(): array {
	return array(
		'BOLETA'  => array( 'billing_id_type', 'billing_id_number' ),
		'FACTURA' => array( 'billing_ruc', 'billing_business_name' ),
	);
}

function frave_peru_checkout_fields( array $fields ): array {
	$fields['billing']['billing_voucher_type'] = array(
		'type'     => 'select',
		'label'    => __( 'Comprobante de pago', 'frave-peru' ),
		'required' => true,
		'options'  => frave_peru_voucher_types(),
		'default'  => 'BOLETA',
		'class'    => array( 'form-row-wide' ),
		'priority' => 21,
	);
	$fields['billing']['billing_id_type'] = array(
		'type'     => 'select',
		'label'    => __( 'Tipo de documento', 'frave-peru' ),
		'options'  => frave_peru_id_types(),
		'default'  => 'DNI',
		'class'    => array( 'form-row-first', 'frave-peru-boleta' ),
		'priority' => 22,
	);
	$fields['billing']['billing_id_number'] = array(
		'type'              => 'text',
		'label'             => __( 'Número de documento', 'frave-peru' ),
		'maxlength'         => 12,
		'class'             => array( 'form-row-last', 'frave-peru-boleta' ),
		'custom_attributes' => array( 'autocomplete' => 'off' ),
		'priority'          => 23,
	);
	$fields['billing']['billing_ruc'] = array(
		'type'              => 'text',
		'label'             => __( 'RUC', 'frave-peru' ),
		'maxlength'         => 11,
		'class'             => array( 'form-row-first', 'frave-peru-factura' ),
		'custom_attributes' => array( 'autocomplete' => 'off', 'inputmode' => 'numeric' ),
		'priority'          => 24,
	);
	$fields['billing']['billing_business_name'] = array(
		'type'         => 'text',
		'label'        => __( 'Razón social', 'frave-peru' ),
		'maxlength'    => 150,
		'autocomplete' => 'organization',
		'class'        => array( 'form-row-last', 'frave-peru-factura' ),
		'priority'     => 25,
	);

	return $fields;
}
add_filter( 'woocommerce_checkout_fields', 'frave_peru_checkout_fields' );

/**
 * Conditional fields are declared optional so WooCommerce does not reject the hidden
 * group; show them as required instead of "(opcional)" since they are for their voucher.
 */
function frave_peru_required_indicator( string $field, string $key ): string {
	if ( ! in_array( $key, array_merge( ...array_values( frave_peru_conditional_fields() ) ), true ) ) {
		return $field;
	}

	$optional = '&nbsp;<span class="optional">(' . esc_html__( 'optional', 'woocommerce' ) . ')</span>';
	$field    = str_replace( $optional, '&nbsp;<span class="required" aria-hidden="true">*</span>', $field );

	return (string) preg_replace( '/<(input|select)\b/', '<$1 aria-required="true"', $field, 1 );
}
add_filter( 'woocommerce_form_field', 'frave_peru_required_indicator', 10, 2 );

/** Normalize document numbers before validation and storage. */
function frave_peru_normalize_posted_data( array $data ): array {
	if ( ! array_key_exists( 'billing_voucher_type', $data ) ) {
		return $data;
	}

	if ( ! isset( frave_peru_voucher_types()[ $data['billing_voucher_type'] ] ) ) {
		$data['billing_voucher_type'] = 'BOLETA';
	}
	$data['billing_id_number']     = frave_peru_normalize_document( (string) ( $data['billing_id_number'] ?? '' ) );
	$data['billing_ruc']           = frave_peru_normalize_document( (string) ( $data['billing_ruc'] ?? '' ) );
	$data['billing_business_name'] = trim( (string) ( $data['billing_business_name'] ?? '' ) );

	return $data;
}
add_filter( 'woocommerce_checkout_posted_data', 'frave_peru_normalize_posted_data' );

function frave_peru_validate_checkout( array $data, WP_Error $errors ): void {
	if ( ! array_key_exists( 'billing_voucher_type', $data ) ) {
		return;
	}

	if ( 'FACTURA' === $data['billing_voucher_type'] ) {
		if ( '' === $data['billing_ruc'] ) {
			$errors->add( 'billing_ruc_required', __( 'Ingresa el RUC para emitir la factura.', 'frave-peru' ), array( 'id' => 'billing_ruc' ) );
		} elseif ( '' !== frave_peru_document_error( 'RUC', $data['billing_ruc'] ) ) {
			$errors->add( 'billing_ruc_invalid', frave_peru_document_error( 'RUC', $data['billing_ruc'] ), array( 'id' => 'billing_ruc' ) );
		}
		if ( '' === $data['billing_business_name'] ) {
			$errors->add( 'billing_business_name_required', __( 'Ingresa la razón social para emitir la factura.', 'frave-peru' ), array( 'id' => 'billing_business_name' ) );
		}
		return;
	}

	$type   = (string) ( $data['billing_id_type'] ?? '' );
	$number = $data['billing_id_number'];

	if ( ! isset( frave_peru_id_types()[ $type ] ) ) {
		$errors->add( 'billing_id_type_invalid', __( 'Elige un tipo de documento válido.', 'frave-peru' ), array( 'id' => 'billing_id_type' ) );
	} elseif ( '' === $number ) {
		$errors->add( 'billing_id_number_required', __( 'Ingresa tu número de documento para emitir la boleta.', 'frave-peru' ), array( 'id' => 'billing_id_number' ) );
	} elseif ( '' !== frave_peru_document_error( $type, $number ) ) {
		$errors->add( 'billing_id_number_invalid', frave_peru_document_error( $type, $number ), array( 'id' => 'billing_id_number' ) );
	}
}
add_action( 'woocommerce_after_checkout_validation', 'frave_peru_validate_checkout', 10, 2 );

/**
 * Keep only the data of the chosen voucher on the order. The customer profile keeps
 * both groups so the next checkout is prefilled whichever voucher is chosen.
 */
function frave_peru_clean_order_meta( WC_Order $order ): void {
	$voucher = (string) $order->get_meta( '_billing_voucher_type' );
	if ( '' === $voucher ) {
		return;
	}

	foreach ( frave_peru_conditional_fields() as $type => $keys ) {
		if ( $type === $voucher ) {
			continue;
		}
		foreach ( $keys as $key ) {
			$order->delete_meta_data( '_' . $key );
		}
	}
}
add_action( 'woocommerce_checkout_create_order', 'frave_peru_clean_order_meta' );

/** Label => value pairs describing the order's voucher, for customers, emails and admin. */
function frave_peru_order_document_rows( WC_Order $order ): array {
	$voucher = (string) $order->get_meta( '_billing_voucher_type' );
	if ( '' === $voucher ) {
		return array();
	}

	$rows = array( __( 'Comprobante', 'frave-peru' ) => frave_peru_voucher_types()[ $voucher ] ?? $voucher );

	if ( 'FACTURA' === $voucher ) {
		$rows[ __( 'RUC', 'frave-peru' ) ]          = (string) $order->get_meta( '_billing_ruc' );
		$rows[ __( 'Razón social', 'frave-peru' ) ] = (string) $order->get_meta( '_billing_business_name' );
	} else {
		$type = (string) $order->get_meta( '_billing_id_type' );
		$rows[ frave_peru_id_types()[ $type ] ?? __( 'Documento', 'frave-peru' ) ] = (string) $order->get_meta( '_billing_id_number' );
	}

	return array_filter( $rows, static fn( $value ) => '' !== $value );
}

/** Thank-you page and "My account → Orders". */
function frave_peru_order_details( string $address_type, WC_Order $order ): void {
	if ( 'billing' !== $address_type ) {
		return;
	}
	foreach ( frave_peru_order_document_rows( $order ) as $label => $value ) {
		printf( '<p class="frave-peru-document">%s: %s</p>', esc_html( $label ), esc_html( $value ) );
	}
}
add_action( 'woocommerce_order_details_after_customer_address', 'frave_peru_order_details', 10, 2 );

function frave_peru_email_fields( array $fields, bool $sent_to_admin, $order ): array {
	if ( ! $order instanceof WC_Order ) {
		return $fields;
	}
	foreach ( frave_peru_order_document_rows( $order ) as $label => $value ) {
		$fields[ sanitize_key( $label ) ] = array( 'label' => $label, 'value' => $value );
	}
	return $fields;
}
add_filter( 'woocommerce_email_order_meta_fields', 'frave_peru_email_fields', 10, 3 );

/** Show and edit the fields in the order screen, under the billing address. */
function frave_peru_admin_billing_fields( array $fields, $order = false, string $context = 'edit' ): array {
	$fields['voucher_type']  = array( 'label' => __( 'Comprobante', 'frave-peru' ), 'type' => 'select', 'options' => array( '' => '—' ) + frave_peru_voucher_types(), 'show' => true );
	$fields['id_type']       = array( 'label' => __( 'Tipo de documento', 'frave-peru' ), 'type' => 'select', 'options' => array( '' => '—' ) + frave_peru_id_types(), 'show' => true );
	$fields['id_number']     = array( 'label' => __( 'Número de documento', 'frave-peru' ), 'show' => true );
	$fields['ruc']           = array( 'label' => __( 'RUC', 'frave-peru' ), 'show' => true );
	$fields['business_name'] = array( 'label' => __( 'Razón social', 'frave-peru' ), 'show' => true );

	// The read-only summary shows option labels ("Boleta", "DNI") instead of stored codes.
	if ( 'view' === $context && $order instanceof WC_Order ) {
		foreach ( array( 'voucher_type', 'id_type' ) as $key ) {
			$stored = (string) $order->get_meta( '_billing_' . $key );
			if ( '' !== $stored ) {
				$fields[ $key ]['value'] = $fields[ $key ]['options'][ $stored ] ?? $stored;
			}
		}
	}

	return $fields;
}
add_filter( 'woocommerce_admin_billing_fields', 'frave_peru_admin_billing_fields', 10, 3 );

/** Let the team find orders by DNI, RUC or business name, with or without HPOS. */
function frave_peru_order_search_fields( array $meta_keys ): array {
	return array_merge( $meta_keys, array( '_billing_id_number', '_billing_ruc', '_billing_business_name' ) );
}
add_filter( 'woocommerce_shop_order_search_fields', 'frave_peru_order_search_fields' );
add_filter( 'woocommerce_order_table_search_query_meta_keys', 'frave_peru_order_search_fields' );

function frave_peru_enqueue_checkout_script(): void {
	if ( ! is_checkout() || is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) {
		return;
	}
	wp_enqueue_script(
		'frave-peru-checkout',
		FRAVE_PERU_URL . 'assets/checkout-fields.js',
		array(),
		FRAVE_PERU_VERSION,
		array( 'strategy' => 'defer', 'in_footer' => true )
	);
}
add_action( 'wp_enqueue_scripts', 'frave_peru_enqueue_checkout_script' );

/**
 * WooCommerce shows "I accept the terms" as soon as a terms page is assigned, even if it is
 * a draft, so customers would be asked to accept a page that returns 404. Hide the checkbox
 * (and with it the server-side requirement, tied to the same template) until it is published.
 */
function frave_peru_require_published_terms( bool $show ): bool {
	$page_id = function_exists( 'wc_terms_and_conditions_page_id' ) ? (int) wc_terms_and_conditions_page_id() : 0;
	return $show && ( ! $page_id || 'publish' === get_post_status( $page_id ) );
}
add_filter( 'woocommerce_checkout_show_terms', 'frave_peru_require_published_terms' );

/** These fields exist only in the classic checkout; warn if the page uses the Checkout block. */
function frave_peru_block_checkout_notice(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$checkout_id = wc_get_page_id( 'checkout' );
	if ( $checkout_id <= 0 || ! has_block( 'woocommerce/checkout', $checkout_id ) ) {
		return;
	}
	?>
	<div class="notice notice-warning"><p>
		<?php esc_html_e( 'Frave Perú: la página de finalizar compra usa el bloque de Checkout. Los campos de boleta, factura, DNI y RUC solo funcionan con el checkout clásico [woocommerce_checkout].', 'frave-peru' ); ?>
	</p></div>
	<?php
}
add_action( 'admin_notices', 'frave_peru_block_checkout_notice' );
