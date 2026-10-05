<?php
/**
 * Public form of the Libro de Reclamaciones: [frave_libro_reclamaciones].
 *
 * The form posts to its own page. Errors re-render the form with the entered values;
 * a successful submission redirects to a confirmation that shows the sheet, reachable
 * only with a signed key. The markup is plain HTML so it works without JavaScript.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const FRAVE_PERU_CLAIM_RATE_LIMIT = 10; // Sheets per hour from one IP address.

function frave_peru_claim_defaults(): array {
	return array(
		'first_name'       => '',
		'last_name'        => '',
		'doc_type'         => 'DNI',
		'doc_number'       => '',
		'address'          => '',
		'phone'            => '',
		'email'            => '',
		'is_minor'         => '',
		'guardian'         => '',
		'good_type'        => 'PRODUCTO',
		'order_number'     => '',
		'amount'           => '',
		'good_description' => '',
		'claim_type'       => 'RECLAMO',
		'detail'           => '',
		'request'          => '',
		'privacy'          => '',
	);
}

function frave_peru_claim_max_lengths(): array {
	return array(
		'first_name'       => 80,
		'last_name'        => 80,
		'address'          => 200,
		'phone'            => 20,
		'email'            => 120,
		'guardian'         => 160,
		'order_number'     => 20,
		'good_description' => 300,
		'detail'           => 3000,
		'request'          => 1500,
	);
}

function frave_peru_sanitize_claim_input( array $input ): array {
	$values = frave_peru_claim_defaults();
	$pick   = static fn( string $value, array $options ): string => isset( $options[ $value ] ) ? $value : '';

	foreach ( array( 'first_name', 'last_name', 'address', 'phone', 'guardian', 'order_number', 'good_description' ) as $key ) {
		$values[ $key ] = sanitize_text_field( (string) ( $input[ $key ] ?? '' ) );
	}
	foreach ( array( 'detail', 'request' ) as $key ) {
		$values[ $key ] = sanitize_textarea_field( (string) ( $input[ $key ] ?? '' ) );
	}

	$values['email']        = sanitize_email( (string) ( $input['email'] ?? '' ) );
	$values['doc_type']     = $pick( (string) ( $input['doc_type'] ?? '' ), frave_peru_claim_document_types() );
	$values['doc_number']   = frave_peru_normalize_document( sanitize_text_field( (string) ( $input['doc_number'] ?? '' ) ) );
	$values['good_type']    = $pick( (string) ( $input['good_type'] ?? '' ), frave_peru_claim_good_types() );
	$values['claim_type']   = $pick( (string) ( $input['claim_type'] ?? '' ), frave_peru_claim_types() );
	$values['order_number'] = ltrim( $values['order_number'], '#' );
	$values['amount']       = str_replace( ',', '.', (string) preg_replace( '/[^\d.,]/', '', (string) ( $input['amount'] ?? '' ) ) );
	$values['is_minor']     = empty( $input['is_minor'] ) ? '' : '1';
	$values['privacy']      = empty( $input['privacy'] ) ? '' : '1';

	return $values;
}

/** @return array<string, string> Field key => message. */
function frave_peru_validate_claim( array $values ): array {
	$errors   = array();
	$required = array(
		'first_name'       => __( 'Ingresa tus nombres.', 'frave-peru' ),
		'last_name'        => __( 'Ingresa tus apellidos.', 'frave-peru' ),
		'address'          => __( 'Ingresa tu domicilio.', 'frave-peru' ),
		'phone'            => __( 'Ingresa un teléfono de contacto.', 'frave-peru' ),
		'good_description' => __( 'Describe el producto o servicio.', 'frave-peru' ),
		'detail'           => __( 'Describe tu reclamo o queja.', 'frave-peru' ),
		'request'          => __( 'Indica qué solicitas al proveedor.', 'frave-peru' ),
	);
	foreach ( $required as $key => $message ) {
		if ( '' === $values[ $key ] ) {
			$errors[ $key ] = $message;
		}
	}
	foreach ( frave_peru_claim_max_lengths() as $key => $max ) {
		if ( ! isset( $errors[ $key ] ) && mb_strlen( $values[ $key ] ) > $max ) {
			/* translators: %d: maximum number of characters. */
			$errors[ $key ] = sprintf( __( 'Este campo admite como máximo %d caracteres.', 'frave-peru' ), $max );
		}
	}

	if ( '' === $values['doc_type'] ) {
		$errors['doc_type'] = __( 'Elige un tipo de documento.', 'frave-peru' );
	} elseif ( '' === $values['doc_number'] ) {
		$errors['doc_number'] = __( 'Ingresa tu número de documento.', 'frave-peru' );
	} elseif ( '' !== frave_peru_document_error( $values['doc_type'], $values['doc_number'] ) ) {
		$errors['doc_number'] = frave_peru_document_error( $values['doc_type'], $values['doc_number'] );
	}

	if ( ! is_email( $values['email'] ) ) {
		$errors['email'] = __( 'Ingresa un correo electrónico válido. Te enviaremos allí la constancia.', 'frave-peru' );
	}
	if ( ! isset( $errors['phone'] ) && 1 !== preg_match( '/^[0-9+()\s-]{6,20}$/', $values['phone'] ) ) {
		$errors['phone'] = __( 'Ingresa un teléfono válido.', 'frave-peru' );
	}
	if ( $values['is_minor'] && '' === $values['guardian'] ) {
		$errors['guardian'] = __( 'Indica el nombre del padre, madre o apoderado.', 'frave-peru' );
	}
	if ( '' !== $values['amount'] && 1 !== preg_match( '/^\d{1,9}(\.\d{1,2})?$/', $values['amount'] ) ) {
		$errors['amount'] = __( 'Escribe solo el importe, por ejemplo 120.50.', 'frave-peru' );
	}
	if ( '' === $values['good_type'] ) {
		$errors['good_type'] = __( 'Indica si se trata de un producto o un servicio.', 'frave-peru' );
	}
	if ( '' === $values['claim_type'] ) {
		$errors['claim_type'] = __( 'Indica si registras un reclamo o una queja.', 'frave-peru' );
	}
	if ( '' === $values['privacy'] ) {
		$errors['privacy'] = __( 'Debes aceptar el tratamiento de tus datos para atender la hoja de reclamación.', 'frave-peru' );
	}

	return $errors;
}

/** Key that lets the submitter reopen their confirmation without an account. */
function frave_peru_claim_view_key( int $post_id ): string {
	return substr( hash_hmac( 'sha256', 'frave-claim-' . $post_id, wp_salt( 'auth' ) ), 0, 24 );
}

/** Values and errors of the current request, shared between the handler and the shortcode. */
function frave_peru_claim_form_state( ?array $state = null ): array {
	static $current = array(
		'values' => array(),
		'errors' => array(),
	);
	if ( null !== $state ) {
		$current = $state;
	}
	return $current;
}

function frave_peru_is_claims_page(): bool {
	$page = get_queried_object();
	return is_page() && $page instanceof WP_Post && has_shortcode( $page->post_content, 'frave_libro_reclamaciones' );
}

/**
 * Keep the page out of caches (it shows per-visitor results) and process submissions.
 * Automated submissions are filtered by includes/antispam.php (no nonce; see there).
 */
function frave_peru_handle_claim_submission(): void {
	if ( ! frave_peru_is_claims_page() ) {
		return;
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Convention read by page cache plugins.
	}
	nocache_headers();

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- see the docblock.
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['frave_claim_submit'] ) ) {
		return;
	}

	$input  = isset( $_POST['claim'] ) && is_array( $_POST['claim'] ) ? wp_unslash( $_POST['claim'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field below.
	$values = frave_peru_sanitize_claim_input( $input );
	$errors = frave_peru_validate_claim( $values );

	// phpcs:enable

	$spam       = frave_peru_posted_spam_fields();
	$spam_error = frave_peru_spam_error( $spam['honeypot'], $spam['token'], 'claim', FRAVE_PERU_CLAIM_RATE_LIMIT );
	if ( '' !== $spam_error ) {
		$errors = array( 'form' => $spam_error ) + $errors;
	}

	if ( $errors ) {
		frave_peru_claim_form_state(
			array(
				'values' => $values,
				'errors' => $errors,
			)
		);
		return;
	}

	$post_id = frave_peru_create_claim( $values );
	if ( is_wp_error( $post_id ) || ! $post_id ) {
		frave_peru_claim_form_state(
			array(
				'values' => $values,
				'errors' => array( 'form' => __( 'No pudimos registrar tu hoja por un error del sistema. Inténtalo de nuevo en unos minutos.', 'frave-peru' ) ),
			)
		);
		return;
	}

	frave_peru_rate_hit( 'claim' );
	frave_peru_send_claim_receipt( $post_id );
	frave_peru_notify_new_claim( $post_id );

	wp_safe_redirect(
		add_query_arg(
			array(
				'reclamo' => $post_id,
				'clave'   => frave_peru_claim_view_key( $post_id ),
			),
			get_permalink( get_queried_object_id() )
		) . '#frave-claims'
	);
	exit;
}
add_action( 'template_redirect', 'frave_peru_handle_claim_submission' );

/** Prefill contact data for signed-in customers, including the checkout document. */
function frave_peru_claim_prefill(): array {
	$values = frave_peru_claim_defaults();
	if ( ! is_user_logged_in() || ! function_exists( 'WC' ) || ! WC()->customer ) {
		return $values;
	}

	$customer = WC()->customer;
	$doc_type = (string) $customer->get_meta( 'billing_id_type' );

	return array_merge(
		$values,
		array(
			'first_name' => $customer->get_billing_first_name(),
			'last_name'  => $customer->get_billing_last_name(),
			'address'    => trim( $customer->get_billing_address_1() . ' ' . $customer->get_billing_city() ),
			'phone'      => $customer->get_billing_phone(),
			'email'      => $customer->get_billing_email(),
			'doc_type'   => isset( frave_peru_claim_document_types()[ $doc_type ] ) ? $doc_type : 'DNI',
			'doc_number' => (string) $customer->get_meta( 'billing_id_number' ),
		)
	);
}

/** Render one labelled control with its help text and error message. */
function frave_peru_claim_field( string $key, string $label, array $state, array $args = array() ): void {
	$args  = wp_parse_args(
		$args,
		array(
			'type'         => 'text',
			'required'     => true,
			'autocomplete' => '',
			'inputmode'    => '',
			'options'      => array(),
			'help'         => '',
			'class'        => '',
		)
	);
	$id    = 'frave-claim-' . str_replace( '_', '-', $key );
	$error = $state['errors'][ $key ] ?? '';
	$value = (string) ( $state['values'][ $key ] ?? '' );
	$max   = frave_peru_claim_max_lengths()[ $key ] ?? 0;

	$attributes     = array(
		'id'               => $id,
		'name'             => 'claim[' . $key . ']',
		'required'         => $args['required'] ? 'required' : '',
		'maxlength'        => $max ? (string) $max : '',
		'autocomplete'     => $args['autocomplete'],
		'inputmode'        => $args['inputmode'],
		'aria-invalid'     => $error ? 'true' : '',
		'aria-describedby' => implode( ' ', array_filter( array( $args['help'] ? $id . '-help' : '', $error ? $id . '-error' : '' ) ) ),
	);
	$attribute_html = '';
	foreach ( array_filter( $attributes, static fn( $attribute ) => '' !== $attribute ) as $name => $attribute ) {
		$attribute_html .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $attribute ) );
	}
	?>
	<p class="frave-claims__field <?php echo esc_attr( $args['class'] ); ?><?php echo $error ? ' has-error' : ''; ?>">
		<label for="<?php echo esc_attr( $id ); ?>">
			<?php echo esc_html( $label ); ?>
			<?php if ( $args['required'] ) : ?>
				<span class="frave-claims__required" aria-hidden="true">*</span>
			<?php endif; ?>
		</label>
		<?php if ( 'textarea' === $args['type'] ) : ?>
			<textarea rows="5"<?php echo $attribute_html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>><?php echo esc_textarea( $value ); ?></textarea>
		<?php elseif ( 'select' === $args['type'] ) : ?>
			<select<?php echo $attribute_html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>>
				<?php foreach ( $args['options'] as $option_value => $option_label ) : ?>
					<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $value, $option_value ); ?>><?php echo esc_html( $option_label ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php else : ?>
			<input type="<?php echo esc_attr( $args['type'] ); ?>" value="<?php echo esc_attr( $value ); ?>"<?php echo $attribute_html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>>
		<?php endif; ?>
		<?php if ( $args['help'] ) : ?>
			<span class="frave-claims__help" id="<?php echo esc_attr( $id . '-help' ); ?>"><?php echo esc_html( $args['help'] ); ?></span>
		<?php endif; ?>
		<?php if ( $error ) : ?>
			<span class="frave-claims__error" id="<?php echo esc_attr( $id . '-error' ); ?>"><?php echo esc_html( $error ); ?></span>
		<?php endif; ?>
	</p>
	<?php
}

/** A group of radio buttons, each with an optional description. */
function frave_peru_claim_choice( string $key, string $legend, array $choices, array $state ): void {
	$error = $state['errors'][ $key ] ?? '';
	$value = (string) ( $state['values'][ $key ] ?? '' );
	?>
	<fieldset class="frave-claims__choice<?php echo $error ? ' has-error' : ''; ?>"<?php echo $error ? ' aria-describedby="frave-claim-' . esc_attr( $key ) . '-error"' : ''; ?>>
		<legend><?php echo esc_html( $legend ); ?> <span class="frave-claims__required" aria-hidden="true">*</span></legend>
		<?php foreach ( $choices as $choice_value => $choice ) : ?>
			<?php $choice_id = 'frave-claim-' . str_replace( '_', '-', $key ) . '-' . strtolower( $choice_value ); ?>
			<label class="frave-claims__option" for="<?php echo esc_attr( $choice_id ); ?>">
				<input type="radio" id="<?php echo esc_attr( $choice_id ); ?>" name="claim[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $choice_value ); ?>" <?php checked( $value, $choice_value ); ?> required>
				<span>
					<strong><?php echo esc_html( $choice['label'] ); ?></strong>
					<?php if ( ! empty( $choice['description'] ) ) : ?>
						<span class="frave-claims__option-description"><?php echo esc_html( $choice['description'] ); ?></span>
					<?php endif; ?>
				</span>
			</label>
		<?php endforeach; ?>
		<?php if ( $error ) : ?>
			<span class="frave-claims__error" id="frave-claim-<?php echo esc_attr( $key ); ?>-error"><?php echo esc_html( $error ); ?></span>
		<?php endif; ?>
	</fieldset>
	<?php
}

/** A single checkbox with its label and error message. */
function frave_peru_claim_checkbox( string $key, string $label_html, array $state, bool $required = false ): void {
	$error = $state['errors'][ $key ] ?? '';
	$id    = 'frave-claim-' . str_replace( '_', '-', $key );
	?>
	<p class="frave-claims__checkbox<?php echo $error ? ' has-error' : ''; ?>">
		<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="claim[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( '1', (string) ( $state['values'][ $key ] ?? '' ) ); ?><?php echo $required ? ' required' : ''; ?><?php echo $error ? ' aria-invalid="true" aria-describedby="' . esc_attr( $id ) . '-error"' : ''; ?>>
		<label for="<?php echo esc_attr( $id ); ?>">
		<?php
		echo wp_kses(
			$label_html,
			array(
				'a' => array(
					'href'   => true,
					'target' => true,
					'rel'    => true,
				),
			)
		);
		?>
					</label>
		<?php if ( $error ) : ?>
			<span class="frave-claims__error" id="<?php echo esc_attr( $id ); ?>-error"><?php echo esc_html( $error ); ?></span>
		<?php endif; ?>
	</p>
	<?php
}

function frave_peru_render_claim_confirmation( array $claim ): void {
	$type = frave_peru_claim_types()[ $claim['claim_type'] ] ?? '';
	?>
	<div class="frave-claims__notice frave-claims__notice--success" role="status">
		<h2>
			<?php
			/* translators: 1: "Reclamo" or "Queja", 2: sheet number. */
			printf( esc_html__( '%1$s registrado con la hoja N.º %2$s', 'frave-peru' ), esc_html( $type ), esc_html( $claim['number'] ) );
			?>
		</h2>
		<p>
			<?php
			printf(
				/* translators: 1: deadline date, 2: number of business days. */
				esc_html__( 'Te responderemos a más tardar el %1$s (%2$d días hábiles).', 'frave-peru' ),
				esc_html( frave_peru_format_date( $claim['due'] ) ),
				(int) FRAVE_PERU_CLAIM_RESPONSE_DAYS
			);
			?>
			<?php if ( $claim['receipt_sent'] ) : ?>
				<?php
				/* translators: %s: consumer email address. */
				printf( esc_html__( 'Enviamos una copia de la hoja a %s.', 'frave-peru' ), esc_html( $claim['email'] ) );
				?>
			<?php else : ?>
				<?php esc_html_e( 'No pudimos enviarte la copia por correo: imprime o guarda esta página como constancia.', 'frave-peru' ); ?>
			<?php endif; ?>
		</p>
	</div>
	<?php echo frave_peru_render_claim_sheet( $claim ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while rendering. ?>
	<p class="frave-claims__actions">
		<button type="button" class="button button--secondary" onclick="window.print()"><?php esc_html_e( 'Imprimir o guardar en PDF', 'frave-peru' ); ?></button>
	</p>
	<?php
}

function frave_peru_render_claims_book(): string {
	$settings = frave_peru_claims_settings();

	ob_start();
	echo '<div class="frave-claims" id="frave-claims">';

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view protected by a signed key.
	$post_id = absint( $_GET['reclamo'] ?? 0 );
	$key     = sanitize_text_field( wp_unslash( $_GET['clave'] ?? '' ) );
	// phpcs:enable
	$claim = $post_id && hash_equals( frave_peru_claim_view_key( $post_id ), $key ) ? frave_peru_get_claim( $post_id ) : null;

	if ( $claim ) {
		frave_peru_render_claim_confirmation( $claim );
		echo '</div>';
		return (string) ob_get_clean();
	}

	$state = frave_peru_claim_form_state();
	if ( ! $state['values'] ) {
		$state['values'] = frave_peru_claim_prefill();
	}
	$errors  = $state['errors'];
	$privacy = get_privacy_policy_url();
	$now     = new DateTimeImmutable( 'now', wp_timezone() );
	?>
	<div class="frave-claims__provider">
		<dl>
			<?php if ( $settings['business_name'] ) : ?>
				<div><dt><?php esc_html_e( 'Razón social', 'frave-peru' ); ?></dt><dd><?php echo esc_html( $settings['business_name'] ); ?></dd></div>
			<?php endif; ?>
			<?php if ( $settings['ruc'] ) : ?>
				<div><dt><?php esc_html_e( 'RUC', 'frave-peru' ); ?></dt><dd><?php echo esc_html( $settings['ruc'] ); ?></dd></div>
			<?php endif; ?>
			<?php if ( $settings['address'] ) : ?>
				<div><dt><?php esc_html_e( 'Domicilio', 'frave-peru' ); ?></dt><dd><?php echo esc_html( $settings['address'] ); ?></dd></div>
			<?php endif; ?>
			<div><dt><?php esc_html_e( 'Fecha', 'frave-peru' ); ?></dt><dd><?php echo esc_html( wp_date( 'd/m/Y', $now->getTimestamp() ) ); ?></dd></div>
		</dl>
		<p>
			<?php
			printf(
				/* translators: %d: number of business days. */
				esc_html__( 'Registra aquí tu reclamo o queja. Al enviarla, recibirás una copia de la hoja en tu correo electrónico y te responderemos en un plazo no mayor a %d días hábiles.', 'frave-peru' ),
				(int) FRAVE_PERU_CLAIM_RESPONSE_DAYS
			);
			?>
		</p>
	</div>

	<form class="frave-claims__form" method="post" action="#frave-claims">
		<?php if ( $errors ) : ?>
			<div class="frave-claims__notice frave-claims__notice--error" role="alert">
				<p><strong><?php esc_html_e( 'Revisa los siguientes datos:', 'frave-peru' ); ?></strong></p>
				<ul>
					<?php foreach ( $errors as $field => $message ) : ?>
						<?php if ( 'form' === $field ) : ?>
							<li><?php echo esc_html( $message ); ?></li>
						<?php else : ?>
							<li><a href="#frave-claim-<?php echo esc_attr( str_replace( '_', '-', $field ) ); ?>"><?php echo esc_html( $message ); ?></a></li>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<fieldset class="frave-claims__section">
			<legend><?php esc_html_e( '1. Identificación del consumidor reclamante', 'frave-peru' ); ?></legend>
			<div class="frave-claims__grid">
				<?php
				frave_peru_claim_field( 'first_name', __( 'Nombres', 'frave-peru' ), $state, array( 'autocomplete' => 'given-name' ) );
				frave_peru_claim_field( 'last_name', __( 'Apellidos', 'frave-peru' ), $state, array( 'autocomplete' => 'family-name' ) );
				frave_peru_claim_field(
					'doc_type',
					__( 'Tipo de documento', 'frave-peru' ),
					$state,
					array(
						'type'    => 'select',
						'options' => frave_peru_claim_document_types(),
					)
				);
				frave_peru_claim_field( 'doc_number', __( 'Número de documento', 'frave-peru' ), $state, array( 'autocomplete' => 'off' ) );
				frave_peru_claim_field(
					'address',
					__( 'Domicilio', 'frave-peru' ),
					$state,
					array(
						'autocomplete' => 'street-address',
						'class'        => 'frave-claims__field--wide',
					)
				);
				frave_peru_claim_field(
					'phone',
					__( 'Teléfono', 'frave-peru' ),
					$state,
					array(
						'type'         => 'tel',
						'autocomplete' => 'tel',
					)
				);
				frave_peru_claim_field(
					'email',
					__( 'Correo electrónico', 'frave-peru' ),
					$state,
					array(
						'type'         => 'email',
						'autocomplete' => 'email',
						'help'         => __( 'Te enviaremos aquí la constancia y la respuesta.', 'frave-peru' ),
					)
				);
				?>
			</div>
			<?php frave_peru_claim_checkbox( 'is_minor', esc_html__( 'Soy menor de edad', 'frave-peru' ), $state ); ?>
			<?php
			frave_peru_claim_field(
				'guardian',
				__( 'Nombre del padre, madre o apoderado', 'frave-peru' ),
				$state,
				array(
					'required' => false,
					'help'     => __( 'Obligatorio solo si el reclamante es menor de edad.', 'frave-peru' ),
				)
			);
			?>
		</fieldset>

		<fieldset class="frave-claims__section">
			<legend><?php esc_html_e( '2. Identificación del bien contratado', 'frave-peru' ); ?></legend>
			<?php
			frave_peru_claim_choice(
				'good_type',
				__( 'Tipo de bien', 'frave-peru' ),
				array_map( static fn( $good_label ) => array( 'label' => $good_label ), frave_peru_claim_good_types() ),
				$state
			);
			?>
			<div class="frave-claims__grid">
				<?php
				frave_peru_claim_field(
					'order_number',
					__( 'Número de pedido', 'frave-peru' ),
					$state,
					array(
						'required'  => false,
						'inputmode' => 'numeric',
						'help'      => __( 'Si tu reclamo es sobre una compra en la tienda.', 'frave-peru' ),
					)
				);
				frave_peru_claim_field(
					'amount',
					__( 'Monto reclamado (S/)', 'frave-peru' ),
					$state,
					array(
						'required'  => false,
						'inputmode' => 'decimal',
					)
				);
				frave_peru_claim_field( 'good_description', __( 'Descripción del producto o servicio', 'frave-peru' ), $state, array( 'class' => 'frave-claims__field--wide' ) );
				?>
			</div>
		</fieldset>

		<fieldset class="frave-claims__section">
			<legend><?php esc_html_e( '3. Detalle de la reclamación y pedido del consumidor', 'frave-peru' ); ?></legend>
			<?php
			frave_peru_claim_choice(
				'claim_type',
				__( 'Tipo', 'frave-peru' ),
				array(
					'RECLAMO' => array(
						'label'       => __( 'Reclamo', 'frave-peru' ),
						'description' => __( 'Disconformidad relacionada con los productos o servicios.', 'frave-peru' ),
					),
					'QUEJA'   => array(
						'label'       => __( 'Queja', 'frave-peru' ),
						'description' => __( 'Disconformidad no relacionada con los productos o servicios, o malestar respecto a la atención al público.', 'frave-peru' ),
					),
				),
				$state
			);
			frave_peru_claim_field( 'detail', __( 'Detalle', 'frave-peru' ), $state, array( 'type' => 'textarea' ) );
			frave_peru_claim_field(
				'request',
				__( 'Pedido', 'frave-peru' ),
				$state,
				array(
					'type' => 'textarea',
					'help' => __( 'Qué solución solicitas al proveedor.', 'frave-peru' ),
				)
			);
			?>
		</fieldset>

		<?php
		$privacy_label = $privacy
			? sprintf(
				/* translators: %s: privacy policy URL. */
				__( 'Autorizo el uso de mis datos para registrar y atender esta hoja, según la <a href="%s" target="_blank" rel="noopener">política de privacidad</a>.', 'frave-peru' ),
				esc_url( $privacy )
			)
			: esc_html__( 'Autorizo el uso de mis datos para registrar y atender esta hoja de reclamación.', 'frave-peru' );
		frave_peru_claim_checkbox( 'privacy', $privacy_label, $state, true );
		?>

		<ul class="frave-claims__notes">
			<?php foreach ( array_slice( frave_peru_claim_legal_notes(), 2 ) as $note ) : ?>
				<li><?php echo esc_html( $note ); ?></li>
			<?php endforeach; ?>
		</ul>

		<?php frave_peru_spam_fields(); ?>
		<p class="frave-claims__actions">
			<button type="submit" name="frave_claim_submit" value="1" class="button button--primary"><?php esc_html_e( 'Enviar hoja de reclamación', 'frave-peru' ); ?></button>
		</p>
	</form>
	<?php
	echo '</div>';
	return (string) ob_get_clean();
}
add_shortcode( 'frave_libro_reclamaciones', 'frave_peru_render_claims_book' );
