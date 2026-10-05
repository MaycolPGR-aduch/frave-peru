<?php
/**
 * Anti-spam shared by the public forms (Libro de Reclamaciones, contact).
 *
 * No nonces: the forms are anonymous, so CSRF gains nothing, and a nonce cached in a
 * page would expire and block real visitors. Instead:
 * - a honeypot field that people never see;
 * - a signed timestamp: submissions faster than FRAVE_PERU_FORM_MIN_SECONDS are bots;
 * - a per-IP hourly limit, counted on successful submissions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const FRAVE_PERU_FORM_MIN_SECONDS = 3;

/** Signed timestamp for the time trap. It stays valid in cached pages, unlike a nonce. */
function frave_peru_form_token(): string {
	$time = (string) time();
	return $time . '.' . substr( hash_hmac( 'sha256', $time, wp_salt( 'nonce' ) ), 0, 32 );
}

function frave_peru_form_token_is_valid( string $token ): bool {
	$parts = explode( '.', $token );
	if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
		return false;
	}
	$expected = substr( hash_hmac( 'sha256', $parts[0], wp_salt( 'nonce' ) ), 0, 32 );
	return hash_equals( $expected, $parts[1] ) && time() - (int) $parts[0] >= FRAVE_PERU_FORM_MIN_SECONDS;
}

function frave_peru_rate_key( string $context ): string {
	return 'frave_rl_' . $context . '_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
}

/** Count one successful submission from this IP for the next hour. */
function frave_peru_rate_hit( string $context ): void {
	$key = frave_peru_rate_key( $context );
	set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
}

/**
 * Message for a submission that fails the checks, or '' when it passes.
 *
 * @param string $honeypot Value of the hidden trap field.
 * @param string $token    Value of the signed timestamp field.
 */
function frave_peru_spam_error( string $honeypot, string $token, string $context, int $hourly_limit ): string {
	if ( '' !== $honeypot || ! frave_peru_form_token_is_valid( $token ) ) {
		return __( 'No pudimos enviar el formulario. Espera unos segundos y vuelve a intentarlo.', 'frave-peru' );
	}
	if ( (int) get_transient( frave_peru_rate_key( $context ) ) >= $hourly_limit ) {
		return __( 'Recibimos varios envíos desde tu conexión en poco tiempo. Inténtalo de nuevo en una hora.', 'frave-peru' );
	}
	return '';
}

/** Hidden fields every protected form prints: the honeypot and the signed timestamp. */
function frave_peru_spam_fields(): void {
	?>
	<p class="frave-form-trap" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">
		<label for="frave-form-website"><?php esc_html_e( 'No completes este campo', 'frave-peru' ); ?></label>
		<input type="text" id="frave-form-website" name="frave_form_website" value="" tabindex="-1" autocomplete="off">
	</p>
	<input type="hidden" name="frave_form_token" value="<?php echo esc_attr( frave_peru_form_token() ); ?>">
	<?php
}

/** Read the anti-spam fields of the current POST request. */
function frave_peru_posted_spam_fields(): array {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- see the file docblock.
	return array(
		'honeypot' => sanitize_text_field( wp_unslash( $_POST['frave_form_website'] ?? '' ) ),
		'token'    => sanitize_text_field( wp_unslash( $_POST['frave_form_token'] ?? '' ) ),
	);
	// phpcs:enable
}
