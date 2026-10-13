<?php
/**
 * Contact form: [frave_contacto].
 *
 * Every message is stored as a private `frave_mensaje` post and emailed to the store,
 * so nothing is lost if email delivery fails. Replies go straight to the sender through
 * Reply-To. No copy is emailed to the sender: that would let anyone use the form to send
 * mail to arbitrary addresses.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const FRAVE_PERU_CONTACT_RATE_LIMIT = 5; // Messages per hour from one IP address.

/** In catalog mode there are no orders or deliveries to ask about: the form gathers leads. */
function frave_peru_contact_subjects(): array {
	if ( frave_peru_catalog_mode() ) {
		$subjects = array(
			'cotizacion' => __( 'Solicitar una cotización', 'frave-peru' ),
			'productos'  => __( 'Consulta sobre productos', 'frave-peru' ),
			'mayorista'  => __( 'Distribución y compras por mayor', 'frave-peru' ),
			'proveedor'  => __( 'Quiero ser proveedor', 'frave-peru' ),
			'otro'       => __( 'Otro tema', 'frave-peru' ),
		);
	} else {
		$subjects = array(
			'productos' => __( 'Consulta sobre productos', 'frave-peru' ),
			'pedido'    => __( 'Un pedido realizado', 'frave-peru' ),
			'envios'    => __( 'Envíos y entregas', 'frave-peru' ),
			'mayorista' => __( 'Compras por mayor', 'frave-peru' ),
			'otro'      => __( 'Otro tema', 'frave-peru' ),
		);
	}
	return (array) apply_filters( 'frave_peru_contact_subjects', $subjects );
}

/**
 * Product lines a visitor can say they are interested in: the top-level product categories
 * (except the default one), plus "several". Keys are category slugs.
 *
 * @return array<string, string>
 */
function frave_peru_contact_lines(): array {
	$lines = array( '' => __( 'Elige una línea (opcional)', 'frave-peru' ) );
	if ( taxonomy_exists( 'product_cat' ) ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'parent'     => 0,
				'hide_empty' => false,
				'exclude'    => array( (int) get_option( 'default_product_cat' ) ),
			)
		);
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			$lines[ $term->slug ] = $term->name;
		}
	}
	$lines['varias'] = __( 'Varias líneas', 'frave-peru' );
	return (array) apply_filters( 'frave_peru_contact_lines', $lines );
}

/** Slug of the product line (top-level category) a category belongs to. */
function frave_peru_contact_line_of( WP_Term $term ): string {
	$ancestors = get_ancestors( $term->term_id, 'product_cat', 'taxonomy' );
	$top       = $ancestors ? get_term( (int) end( $ancestors ), 'product_cat' ) : $term;
	return $top instanceof WP_Term ? $top->slug : '';
}

/**
 * Product a "Solicitar cotización" link points to (?producto=ID), if visitors can see it.
 * Theme links add the parameter to the contact page URL.
 */
function frave_peru_contact_requested_product(): ?WC_Product {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	$product_id = isset( $_GET['producto'] ) ? absint( $_GET['producto'] ) : 0;
	$product    = $product_id ? wc_get_product( $product_id ) : null;
	if ( ! $product || 'publish' !== $product->get_status() || ! $product->is_visible() ) {
		return null;
	}
	return $product;
}

/**
 * Product line or subcategory a quote link points to (?linea=slug), as on the theme's
 * line pages.
 */
function frave_peru_contact_requested_category(): ?WP_Term {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	$slug = isset( $_GET['linea'] ) ? sanitize_title( wp_unslash( $_GET['linea'] ) ) : '';
	$term = '' !== $slug ? get_term_by( 'slug', $slug, 'product_cat' ) : false;
	return $term instanceof WP_Term ? $term : null;
}

function frave_peru_contact_recipient(): string {
	$email = (string) get_option( 'frave_peru_contact_email', '' );
	return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
}

/* ---------- Storage ---------- */

function frave_peru_register_message_post_type(): void {
	register_post_type(
		'frave_mensaje',
		array(
			'labels'              => array(
				'name'          => __( 'Mensajes de contacto', 'frave-peru' ),
				'singular_name' => __( 'Mensaje de contacto', 'frave-peru' ),
				'menu_name'     => __( 'Mensajes', 'frave-peru' ),
				'all_items'     => __( 'Mensajes recibidos', 'frave-peru' ),
				'edit_item'     => __( 'Mensaje de contacto', 'frave-peru' ),
				'search_items'  => __( 'Buscar mensajes', 'frave-peru' ),
				'not_found'     => __( 'Aún no hay mensajes.', 'frave-peru' ),
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_rest'        => false,
			'menu_icon'           => 'dashicons-email-alt',
			'menu_position'       => 57,
			'supports'            => false,
			'rewrite'             => false,
			'query_var'           => false,
			'map_meta_cap'        => true,
			// Messages can be read and deleted (e.g. on a data deletion request), not created or edited.
			'capabilities'        => array(
				'create_posts'           => 'do_not_allow',
				'edit_posts'             => 'manage_woocommerce',
				'edit_others_posts'      => 'manage_woocommerce',
				'edit_published_posts'   => 'manage_woocommerce',
				'publish_posts'          => 'manage_woocommerce',
				'read_private_posts'     => 'manage_woocommerce',
				'delete_posts'           => 'manage_woocommerce',
				'delete_published_posts' => 'manage_woocommerce',
				'delete_others_posts'    => 'manage_woocommerce',
			),
		)
	);
}
add_action( 'init', 'frave_peru_register_message_post_type' );

/* ---------- Validation ---------- */

function frave_peru_contact_defaults(): array {
	return array(
		'name'         => '',
		'email'        => '',
		'phone'        => '',
		'company'      => '',
		'city'         => '',
		'line'         => '',
		'subject'      => 'productos',
		'order_number' => '',
		'message'      => '',
		'privacy'      => '',
	);
}

function frave_peru_sanitize_contact_input( array $input ): array {
	$values                 = frave_peru_contact_defaults();
	$values['name']         = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
	$values['email']        = sanitize_email( (string) ( $input['email'] ?? '' ) );
	$values['phone']        = sanitize_text_field( (string) ( $input['phone'] ?? '' ) );
	$values['company']      = sanitize_text_field( (string) ( $input['company'] ?? '' ) );
	$values['city']         = sanitize_text_field( (string) ( $input['city'] ?? '' ) );
	$line                   = sanitize_title( (string) ( $input['line'] ?? '' ) );
	$values['line']         = isset( frave_peru_contact_lines()[ $line ] ) ? $line : '';
	$values['order_number'] = ltrim( sanitize_text_field( (string) ( $input['order_number'] ?? '' ) ), '#' );
	$values['message']      = sanitize_textarea_field( (string) ( $input['message'] ?? '' ) );
	$subject                = (string) ( $input['subject'] ?? '' );
	$values['subject']      = isset( frave_peru_contact_subjects()[ $subject ] ) ? $subject : '';
	$values['privacy']      = empty( $input['privacy'] ) ? '' : '1';
	return $values;
}

/** @return array<string, string> Field key => message. */
function frave_peru_validate_contact( array $values ): array {
	$errors = array();
	if ( '' === $values['name'] ) {
		$errors['name'] = __( 'Ingresa tu nombre.', 'frave-peru' );
	} elseif ( mb_strlen( $values['name'] ) > 120 ) {
		$errors['name'] = __( 'El nombre admite como máximo 120 caracteres.', 'frave-peru' );
	}
	if ( ! is_email( $values['email'] ) ) {
		$errors['email'] = __( 'Ingresa un correo electrónico válido para poder responderte.', 'frave-peru' );
	}
	if ( '' !== $values['phone'] && 1 !== preg_match( '/^[0-9+()\s-]{6,20}$/', $values['phone'] ) ) {
		$errors['phone'] = __( 'Ingresa un teléfono válido o deja el campo vacío.', 'frave-peru' );
	}
	if ( mb_strlen( $values['company'] ) > 120 ) {
		$errors['company'] = __( 'El nombre de la empresa admite como máximo 120 caracteres.', 'frave-peru' );
	}
	if ( mb_strlen( $values['city'] ) > 80 ) {
		$errors['city'] = __( 'La ciudad admite como máximo 80 caracteres.', 'frave-peru' );
	}
	if ( '' === $values['subject'] ) {
		$errors['subject'] = __( 'Elige el tema de tu mensaje.', 'frave-peru' );
	}
	if ( mb_strlen( $values['order_number'] ) > 20 ) {
		$errors['order_number'] = __( 'El número de pedido admite como máximo 20 caracteres.', 'frave-peru' );
	}
	if ( mb_strlen( $values['message'] ) < 10 ) {
		$errors['message'] = __( 'Escribe tu mensaje (al menos 10 caracteres).', 'frave-peru' );
	} elseif ( mb_strlen( $values['message'] ) > 3000 ) {
		$errors['message'] = __( 'El mensaje admite como máximo 3000 caracteres.', 'frave-peru' );
	}
	if ( '' === $values['privacy'] ) {
		$errors['privacy'] = __( 'Debes autorizar el tratamiento de tus datos para que podamos responderte.', 'frave-peru' );
	}
	return $errors;
}

/* ---------- Submission ---------- */

function frave_peru_contact_form_state( ?array $state = null ): array {
	static $current = array(
		'values' => array(),
		'errors' => array(),
	);
	if ( null !== $state ) {
		$current = $state;
	}
	return $current;
}

/** A page with the shortcode, or one a theme template adds the form to (filter). */
function frave_peru_is_contact_page(): bool {
	$page     = get_queried_object();
	$has_form = is_page() && $page instanceof WP_Post && has_shortcode( $page->post_content, 'frave_contacto' );
	return (bool) apply_filters( 'frave_peru_is_contact_page', $has_form );
}

/** Store the message and email the store. @return int|WP_Error */
function frave_peru_create_contact_message( array $values ) {
	$subject_label          = frave_peru_contact_subjects()[ $values['subject'] ] ?? '';
	$values['submitted_at'] = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( DATE_ATOM );
	// Proof of consent (Ley 29733): the box was ticked when the message was sent.
	$values['privacy'] = '1' === $values['privacy'] ? $values['submitted_at'] : '';

	$post_id = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'frave_mensaje',
				'post_status'  => 'publish',
				'post_title'   => $values['name'] . ' · ' . $subject_label,
				'post_excerpt' => implode( ' ', array_filter( array( $values['company'], $values['email'], $values['phone'], $values['order_number'] ) ) ),
				'meta_input'   => array( '_frave_message' => $values ),
			)
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	$sent = frave_peru_send_html_email(
		frave_peru_contact_recipient(),
		/* translators: 1: subject, 2: sender name. */
		sprintf( __( '[Contacto] %1$s — %2$s', 'frave-peru' ), $subject_label, $values['name'] ),
		__( 'Nuevo mensaje de contacto', 'frave-peru' ),
		frave_peru_render_contact_message( $values ) . sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) ),
			esc_html__( 'Ver en el administrador', 'frave-peru' )
		),
		$values['email']
	);
	update_post_meta( $post_id, '_frave_message_emailed', $sent ? '1' : '0' );

	return $post_id;
}

/** The message as a table with inline styles, for email and the admin screen. */
function frave_peru_render_contact_message( array $values ): string {
	$rows = array_filter(
		array(
			__( 'Nombre', 'frave-peru' )             => $values['name'],
			__( 'Correo electrónico', 'frave-peru' ) => $values['email'],
			__( 'Teléfono', 'frave-peru' )           => $values['phone'],
			__( 'Empresa', 'frave-peru' )            => $values['company'] ?? '',
			__( 'Ciudad', 'frave-peru' )             => $values['city'] ?? '',
			__( 'Tema', 'frave-peru' )               => frave_peru_contact_subjects()[ $values['subject'] ] ?? $values['subject'],
			__( 'Línea de interés', 'frave-peru' )   => '' !== ( $values['line'] ?? '' ) ? ( frave_peru_contact_lines()[ $values['line'] ] ?? $values['line'] ) : '',
			__( 'Número de pedido', 'frave-peru' )   => $values['order_number'],
			__( 'Fecha', 'frave-peru' )              => isset( $values['submitted_at'] ) ? frave_peru_format_date( $values['submitted_at'], true ) : '',
			__( 'Mensaje', 'frave-peru' )            => $values['message'],
			__( 'Consentimiento', 'frave-peru' )     => ! empty( $values['privacy'] ) ? __( 'Autorizó el tratamiento de sus datos (Ley 29733)', 'frave-peru' ) : '',
		),
		static fn( $value ) => '' !== (string) $value
	);

	$cell = 'padding:8px 10px;border:1px solid #e5e1dc;text-align:left;vertical-align:top;';
	$html = '<table cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:0 0 16px;">';
	foreach ( $rows as $label => $value ) {
		$html .= sprintf(
			'<tr><th scope="row" style="%1$swidth:30%%;font-weight:600;">%2$s</th><td style="%1$s">%3$s</td></tr>',
			esc_attr( $cell ),
			esc_html( $label ),
			nl2br( esc_html( (string) $value ), false )
		);
	}
	return $html . '</table>';
}

/**
 * Process submissions (anti-spam: includes/antispam.php). The page itself can be cached:
 * the form has no nonce and its signed timestamp stays valid, and every GET state (the
 * line or product of ?linea= / ?producto=, the «enviado» notice) depends only on the URL.
 * A submission with errors shows what the person typed, so that response is never cached.
 */
function frave_peru_handle_contact_submission(): void {
	if ( ! frave_peru_is_contact_page() ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- see includes/antispam.php.
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['frave_contact_submit'] ) ) {
		return;
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Convention read by page cache plugins.
	}
	nocache_headers();
	$input = isset( $_POST['contact'] ) && is_array( $_POST['contact'] ) ? wp_unslash( $_POST['contact'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field.
	// phpcs:enable

	$values     = frave_peru_sanitize_contact_input( $input );
	$errors     = frave_peru_validate_contact( $values );
	$spam       = frave_peru_posted_spam_fields();
	$spam_error = frave_peru_spam_error( $spam['honeypot'], $spam['token'], 'contact', FRAVE_PERU_CONTACT_RATE_LIMIT );
	if ( '' !== $spam_error ) {
		$errors = array( 'form' => $spam_error ) + $errors;
	}

	if ( ! $errors ) {
		$post_id = frave_peru_create_contact_message( $values );
		if ( is_wp_error( $post_id ) ) {
			$errors = array( 'form' => __( 'No pudimos enviar tu mensaje por un error del sistema. Inténtalo de nuevo en unos minutos.', 'frave-peru' ) );
		}
	}

	if ( $errors ) {
		frave_peru_contact_form_state(
			array(
				'values' => $values,
				'errors' => $errors,
			)
		);
		return;
	}

	frave_peru_rate_hit( 'contact' );
	wp_safe_redirect( add_query_arg( 'mensaje', 'enviado', get_permalink( get_queried_object_id() ) ) . '#frave-contact' );
	exit;
}
add_action( 'template_redirect', 'frave_peru_handle_contact_submission' );

/* ---------- Form ---------- */

/** One labelled control with its error, sharing markup with the claims form. */
function frave_peru_contact_field( string $key, string $label, array $state, array $args = array() ): void {
	$args  = wp_parse_args(
		$args,
		array(
			'type'         => 'text',
			'required'     => true,
			'autocomplete' => '',
			'options'      => array(),
			'help'         => '',
			'maxlength'    => 0,
			'class'        => '',
		)
	);
	$id    = 'frave-contact-' . str_replace( '_', '-', $key );
	$error = $state['errors'][ $key ] ?? '';
	$value = (string) ( $state['values'][ $key ] ?? '' );

	$described      = implode( ' ', array_filter( array( $args['help'] ? $id . '-help' : '', $error ? $id . '-error' : '' ) ) );
	$attributes     = array_filter(
		array(
			'id'               => $id,
			'name'             => 'contact[' . $key . ']',
			'required'         => $args['required'] ? 'required' : '',
			'maxlength'        => $args['maxlength'] ? (string) $args['maxlength'] : '',
			'autocomplete'     => $args['autocomplete'],
			'aria-invalid'     => $error ? 'true' : '',
			'aria-describedby' => $described,
		),
		static fn( $attribute ) => '' !== $attribute
	);
	$attribute_html = '';
	foreach ( $attributes as $name => $attribute ) {
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
			<textarea rows="6"<?php echo $attribute_html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>><?php echo esc_textarea( $value ); ?></textarea>
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

/** Prefill name and email for signed-in customers, and the quote request for a product or a product line. */
function frave_peru_contact_prefill(): array {
	$values = frave_peru_contact_defaults();
	if ( is_user_logged_in() && function_exists( 'WC' ) && WC()->customer ) {
		$customer        = WC()->customer;
		$values['name']  = trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() );
		$values['email'] = $customer->get_billing_email();
		$values['phone'] = $customer->get_billing_phone();
	}

	$category = frave_peru_contact_requested_category();
	if ( $category ) {
		$subjects          = frave_peru_contact_subjects();
		$values['subject'] = isset( $subjects['cotizacion'] ) ? 'cotizacion' : $values['subject'];
		// The line is the top-level ancestor; a line itself has none.
		$ancestors         = get_ancestors( $category->term_id, 'product_cat', 'taxonomy' );
		$line              = $ancestors ? get_term( (int) end( $ancestors ), 'product_cat' ) : null;
		$values['line']    = frave_peru_contact_line_of( $category );
		$values['message'] = $line instanceof WP_Term
			/* translators: 1: subcategory name, 2: product line name. */
			? sprintf( __( "Hola, quisiera información y una cotización de %1\$s (%2\$s).\n\n", 'frave-peru' ), $category->name, $line->name )
			/* translators: %s: product line name. */
			: sprintf( __( "Hola, quisiera información y una cotización de la línea %s.\n\n", 'frave-peru' ), $category->name );
	}

	$product = frave_peru_contact_requested_product();
	if ( $product ) {
		$subjects          = frave_peru_contact_subjects();
		$values['subject'] = isset( $subjects['cotizacion'] ) ? 'cotizacion' : $values['subject'];
		$categories        = get_the_terms( $product->get_id(), 'product_cat' );
		$values['line']    = is_array( $categories ) && $categories ? frave_peru_contact_line_of( $categories[0] ) : '';
		$values['message'] = $product->get_sku()
			/* translators: 1: product name, 2: product SKU, 3: product URL. */
			? sprintf( __( "Hola, quisiera una cotización de %1\$s (código %2\$s).\n%3\$s\n\n", 'frave-peru' ), $product->get_name(), $product->get_sku(), $product->get_permalink() )
			/* translators: 1: product name, 2: product URL. */
			: sprintf( __( "Hola, quisiera una cotización de %1\$s.\n%2\$s\n\n", 'frave-peru' ), $product->get_name(), $product->get_permalink() );
	}
	return $values;
}

function frave_peru_render_contact_form(): string {
	ob_start();
	echo '<div class="frave-claims frave-contact" id="frave-contact">';

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	if ( 'enviado' === sanitize_key( wp_unslash( $_GET['mensaje'] ?? '' ) ) ) {
		?>
		<div class="frave-claims__notice frave-claims__notice--success" role="status">
			<h2><?php esc_html_e( 'Mensaje enviado', 'frave-peru' ); ?></h2>
			<p><?php esc_html_e( 'Gracias por escribirnos. Te responderemos al correo que indicaste.', 'frave-peru' ); ?></p>
		</div>
		<?php
		echo '</div>';
		return (string) ob_get_clean();
	}

	$state = frave_peru_contact_form_state();
	if ( ! $state['values'] ) {
		$state['values'] = frave_peru_contact_prefill();
	}
	$errors  = $state['errors'];
	$privacy = get_privacy_policy_url();
	?>
	<form class="frave-claims__form" method="post" action="#frave-contact">
		<?php if ( $errors ) : ?>
			<div class="frave-claims__notice frave-claims__notice--error" role="alert">
				<p><strong><?php esc_html_e( 'Revisa los siguientes datos:', 'frave-peru' ); ?></strong></p>
				<ul>
					<?php foreach ( $errors as $field => $message ) : ?>
						<?php if ( 'form' === $field ) : ?>
							<li><?php echo esc_html( $message ); ?></li>
						<?php else : ?>
							<li><a href="#frave-contact-<?php echo esc_attr( str_replace( '_', '-', $field ) ); ?>"><?php echo esc_html( $message ); ?></a></li>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<div class="frave-claims__grid">
			<?php
			frave_peru_contact_field(
				'name',
				__( 'Nombre', 'frave-peru' ),
				$state,
				array(
					'autocomplete' => 'name',
					'maxlength'    => 120,
				)
			);
			frave_peru_contact_field(
				'email',
				__( 'Correo electrónico', 'frave-peru' ),
				$state,
				array(
					'type'         => 'email',
					'autocomplete' => 'email',
				)
			);
			frave_peru_contact_field(
				'phone',
				__( 'Teléfono', 'frave-peru' ),
				$state,
				array(
					'type'         => 'tel',
					'required'     => false,
					'autocomplete' => 'tel',
					'maxlength'    => 20,
				)
			);
			frave_peru_contact_field(
				'company',
				__( 'Empresa o marca', 'frave-peru' ),
				$state,
				array(
					'required'     => false,
					'autocomplete' => 'organization',
					'maxlength'    => 120,
				)
			);
			frave_peru_contact_field(
				'city',
				__( 'Ciudad', 'frave-peru' ),
				$state,
				array(
					'required'     => false,
					'autocomplete' => 'address-level2',
					'maxlength'    => 80,
				)
			);
			frave_peru_contact_field(
				'subject',
				__( 'Tema', 'frave-peru' ),
				$state,
				array(
					'type'    => 'select',
					'options' => frave_peru_contact_subjects(),
				)
			);
			frave_peru_contact_field(
				'line',
				__( 'Línea de interés', 'frave-peru' ),
				$state,
				array(
					'type'     => 'select',
					'required' => false,
					'options'  => frave_peru_contact_lines(),
				)
			);
			if ( ! frave_peru_catalog_mode() ) {
				frave_peru_contact_field(
					'order_number',
					__( 'Número de pedido', 'frave-peru' ),
					$state,
					array(
						'required'  => false,
						'maxlength' => 20,
						'help'      => __( 'Si tu consulta es sobre una compra.', 'frave-peru' ),
					)
				);
			}
			frave_peru_contact_field(
				'message',
				__( 'Mensaje', 'frave-peru' ),
				$state,
				array(
					'type'      => 'textarea',
					'maxlength' => 3000,
					'class'     => 'frave-claims__field--wide',
				)
			);
			?>
		</div>

		<?php $privacy_error = $errors['privacy'] ?? ''; ?>
		<p class="frave-claims__checkbox<?php echo $privacy_error ? ' has-error' : ''; ?>">
			<input type="checkbox" id="frave-contact-privacy" name="contact[privacy]" value="1" required <?php checked( '1', (string) ( $state['values']['privacy'] ?? '' ) ); ?><?php echo $privacy_error ? ' aria-invalid="true" aria-describedby="frave-contact-privacy-error"' : ''; ?>>
			<label for="frave-contact-privacy">
				<?php
				if ( $privacy ) {
					printf(
						wp_kses(
							/* translators: %s: privacy policy URL. */
							__( 'Autorizo el tratamiento de mis datos personales para atender mi consulta, conforme a la Ley N.° 29733 de Protección de Datos Personales y la <a href="%s" target="_blank" rel="noopener">política de privacidad</a>.', 'frave-peru' ),
							array(
								'a' => array(
									'href'   => true,
									'target' => true,
									'rel'    => true,
								),
							)
						),
						esc_url( $privacy )
					);
				} else {
					esc_html_e( 'Autorizo el tratamiento de mis datos personales para atender mi consulta, conforme a la Ley N.° 29733 de Protección de Datos Personales.', 'frave-peru' );
				}
				?>
			</label>
			<?php if ( $privacy_error ) : ?>
				<span class="frave-claims__error" id="frave-contact-privacy-error"><?php echo esc_html( $privacy_error ); ?></span>
			<?php endif; ?>
		</p>
		<p class="frave-claims__help frave-contact__rights"><?php esc_html_e( 'Usamos tus datos solo para responderte. Puedes pedir acceder a ellos, rectificarlos, cancelarlos u oponerte a su uso escribiéndonos por este mismo formulario.', 'frave-peru' ); ?></p>

		<?php frave_peru_spam_fields(); ?>
		<p class="frave-claims__actions">
			<button type="submit" name="frave_contact_submit" value="1" class="button button--primary"><?php esc_html_e( 'Enviar mensaje', 'frave-peru' ); ?></button>
		</p>
	</form>
	<?php
	echo '</div>';
	return (string) ob_get_clean();
}
add_shortcode( 'frave_contacto', 'frave_peru_render_contact_form' );
