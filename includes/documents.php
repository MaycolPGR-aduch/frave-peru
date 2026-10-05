<?php
/** Peruvian identity documents and RUC: labels, normalization and format validation. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Payment voucher types, keyed by the stored value. */
function frave_peru_voucher_types(): array {
	return array(
		'BOLETA'  => __( 'Boleta', 'frave-peru' ),
		'FACTURA' => __( 'Factura', 'frave-peru' ),
	);
}

/** Identity documents accepted for a boleta, keyed by the stored value. */
function frave_peru_id_types(): array {
	return array(
		'DNI'       => __( 'DNI', 'frave-peru' ),
		'CE'        => __( 'Carné de extranjería', 'frave-peru' ),
		'PASAPORTE' => __( 'Pasaporte', 'frave-peru' ),
	);
}

/** Uppercase and drop spaces, dots and dashes that customers often type. */
function frave_peru_normalize_document( string $value ): string {
	return strtoupper( (string) preg_replace( '/[\s.\-]+/', '', $value ) );
}

/** Check an identity document number against the format of its type. */
function frave_peru_is_valid_id( string $type, string $number ): bool {
	return match ( $type ) {
		'DNI'       => 1 === preg_match( '/^\d{8}$/', $number ),
		'CE'        => 1 === preg_match( '/^[A-Z0-9]{8,12}$/', $number ),
		'PASAPORTE' => 1 === preg_match( '/^[A-Z0-9]{6,12}$/', $number ),
		default     => false,
	};
}

/** Customer-facing error for an invalid document number, or '' when it is valid. RUC is accepted as a type. */
function frave_peru_document_error( string $type, string $number ): string {
	if ( 'RUC' === $type ) {
		return frave_peru_is_valid_ruc( $number ) ? '' : __( 'El RUC no es válido. Revisa los 11 dígitos.', 'frave-peru' );
	}
	if ( frave_peru_is_valid_id( $type, $number ) ) {
		return '';
	}

	return match ( $type ) {
		'DNI'   => __( 'El DNI debe tener 8 dígitos.', 'frave-peru' ),
		'CE'    => __( 'El carné de extranjería debe tener entre 8 y 12 letras o números.', 'frave-peru' ),
		default => __( 'El pasaporte debe tener entre 6 y 12 letras o números.', 'frave-peru' ),
	};
}

/**
 * Validate a RUC: 11 digits, a SUNAT taxpayer prefix and the modulo 11 check digit.
 * Prefixes: 10 natural person, 15/16/17 special cases, 20 legal entity.
 */
function frave_peru_is_valid_ruc( string $ruc ): bool {
	if ( 1 !== preg_match( '/^(10|15|16|17|20)\d{9}$/', $ruc ) ) {
		return false;
	}

	$weights = array( 5, 4, 3, 2, 7, 6, 5, 4, 3, 2 );
	$sum     = 0;
	foreach ( $weights as $index => $weight ) {
		$sum += (int) $ruc[ $index ] * $weight;
	}

	$check = 11 - ( $sum % 11 );
	if ( $check >= 10 ) {
		$check -= 10;
	}

	return (int) $ruc[10] === $check;
}
