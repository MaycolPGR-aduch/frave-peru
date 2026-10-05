<?php
/**
 * Libro de Reclamaciones virtual: storage, correlative numbering, response deadline
 * and the claim sheet shared by the confirmation page, emails and admin screen.
 *
 * Each sheet is a private `frave_reclamo` post. The consumer's submission is stored once
 * in `_frave_claim` and never edited; the provider's answer lives in separate meta.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Business days the provider has to answer a claim or complaint. */
const FRAVE_PERU_CLAIM_RESPONSE_DAYS = 15;

function frave_peru_claims_settings(): array {
	$defaults = array(
		'business_name' => '',
		'ruc'           => '',
		'address'       => '',
		'page_id'       => 0,
		'notify_email'  => '',
	);
	return array_merge( $defaults, (array) get_option( 'frave_peru_claims_settings', array() ) );
}

/** URL of the published page holding the book, or '' so themes can hide the link. */
function frave_peru_claims_page_url(): string {
	$page_id = absint( frave_peru_claims_settings()['page_id'] );
	return $page_id && 'publish' === get_post_status( $page_id ) ? (string) get_permalink( $page_id ) : '';
}

function frave_peru_claim_document_types(): array {
	return frave_peru_id_types() + array( 'RUC' => __( 'RUC', 'frave-peru' ) );
}

function frave_peru_claim_types(): array {
	return array(
		'RECLAMO' => __( 'Reclamo', 'frave-peru' ),
		'QUEJA'   => __( 'Queja', 'frave-peru' ),
	);
}

function frave_peru_claim_good_types(): array {
	return array(
		'PRODUCTO' => __( 'Producto', 'frave-peru' ),
		'SERVICIO' => __( 'Servicio', 'frave-peru' ),
	);
}

/**
 * Sheets cannot be created or deleted from the admin: they come only from the public
 * form and must be kept as a record. The team can read them and record the answer.
 */
function frave_peru_register_claim_post_type(): void {
	register_post_type(
		'frave_reclamo',
		array(
			'labels'              => array(
				'name'          => __( 'Libro de Reclamaciones', 'frave-peru' ),
				'singular_name' => __( 'Hoja de reclamación', 'frave-peru' ),
				'menu_name'     => __( 'Reclamaciones', 'frave-peru' ),
				'all_items'     => __( 'Hojas de reclamación', 'frave-peru' ),
				'edit_item'     => __( 'Hoja de reclamación', 'frave-peru' ),
				'search_items'  => __( 'Buscar hojas', 'frave-peru' ),
				'not_found'     => __( 'Aún no hay reclamos ni quejas.', 'frave-peru' ),
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_rest'        => false,
			'menu_icon'           => 'dashicons-book-alt',
			'menu_position'       => 56,
			'supports'            => false,
			'rewrite'             => false,
			'query_var'           => false,
			'map_meta_cap'        => true,
			'capabilities'        => array(
				'create_posts'           => 'do_not_allow',
				'edit_posts'             => 'manage_woocommerce',
				'edit_others_posts'      => 'manage_woocommerce',
				'edit_published_posts'   => 'manage_woocommerce',
				'publish_posts'          => 'manage_woocommerce',
				'read_private_posts'     => 'manage_woocommerce',
				'delete_posts'           => 'do_not_allow',
				'delete_published_posts' => 'do_not_allow',
				'delete_others_posts'    => 'do_not_allow',
				'delete_private_posts'   => 'do_not_allow',
			),
		)
	);
}
add_action( 'init', 'frave_peru_register_claim_post_type' );

/**
 * Next correlative number for the year, e.g. 000001-2026. The row-locking UPDATE keeps
 * numbers unique and consecutive when two consumers submit at the same time.
 */
function frave_peru_next_claim_number( int $year ): string {
	global $wpdb;

	$option = 'frave_peru_claims_counter_' . $year;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '0', 'off')", $option ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(option_value + 1) WHERE option_name = %s", $option ) );
	$sequence = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
	// phpcs:enable
	wp_cache_delete( $option, 'options' );

	return sprintf( '%06d-%d', $sequence, $year );
}

/** Easter Sunday (anonymous Gregorian algorithm), used for Holy Thursday and Good Friday. */
function frave_peru_easter_sunday( int $year ): DateTimeImmutable {
	$a     = $year % 19;
	$b     = intdiv( $year, 100 );
	$c     = $year % 100;
	$d     = intdiv( $b, 4 );
	$e     = $b % 4;
	$f     = intdiv( $b + 8, 25 );
	$g     = intdiv( $b - $f + 1, 3 );
	$h     = ( 19 * $a + $b - $d - $g + 15 ) % 30;
	$i     = intdiv( $c, 4 );
	$k     = $c % 4;
	$l     = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
	$m     = intdiv( $a + 11 * $h + 22 * $l, 451 );
	$month = intdiv( $h + $l - 7 * $m + 114, 31 );
	$day   = ( ( $h + $l - 7 * $m + 114 ) % 31 ) + 1;

	return new DateTimeImmutable( sprintf( '%04d-%02d-%02d', $year, $month, $day ), wp_timezone() );
}

/**
 * National holidays skipped when counting business days, as Y-m-d strings.
 * The list is deliberately conservative: a missing holiday makes the computed deadline
 * earlier, never later. Add holidays confirmed for the year with the filter.
 */
function frave_peru_holidays( int $year ): array {
	$fixed  = array( '01-01', '05-01', '06-29', '07-28', '07-29', '08-30', '10-08', '11-01', '12-08', '12-25' );
	$dates  = array_map( static fn( string $month_day ) => $year . '-' . $month_day, $fixed );
	$easter = frave_peru_easter_sunday( $year );

	$dates[] = $easter->modify( '-3 days' )->format( 'Y-m-d' );
	$dates[] = $easter->modify( '-2 days' )->format( 'Y-m-d' );

	return (array) apply_filters( 'frave_peru_holidays', $dates, $year );
}

/** Count business days after $start, skipping weekends and holidays. */
function frave_peru_add_business_days( DateTimeImmutable $start, int $days ): DateTimeImmutable {
	$date     = $start->setTime( 0, 0 );
	$holidays = array();
	$added    = 0;

	while ( $added < $days ) {
		$date                = $date->modify( '+1 day' );
		$year                = (int) $date->format( 'Y' );
		$holidays[ $year ] ??= array_flip( frave_peru_holidays( $year ) );

		if ( (int) $date->format( 'N' ) >= 6 || isset( $holidays[ $year ][ $date->format( 'Y-m-d' ) ] ) ) {
			continue;
		}
		++$added;
	}

	return $date;
}

/**
 * Store a validated submission. The provider data is copied into the sheet so later
 * changes to the settings do not alter sheets already issued.
 *
 * @return int|WP_Error Post ID of the new sheet.
 */
function frave_peru_create_claim( array $values ) {
	$now      = new DateTimeImmutable( 'now', wp_timezone() );
	$settings = frave_peru_claims_settings();
	$number   = frave_peru_next_claim_number( (int) $now->format( 'Y' ) );

	$claim = array_merge(
		$values,
		array(
			'number'           => $number,
			'submitted_at'     => $now->format( DATE_ATOM ),
			'provider_name'    => $settings['business_name'],
			'provider_ruc'     => $settings['ruc'],
			'provider_address' => $settings['address'],
		)
	);
	unset( $claim['privacy'] );

	// wp_insert_post() and its meta_input unslash their input.
	return wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'frave_reclamo',
				'post_status'  => 'publish',
				'post_title'   => $number,
				// Searchable from the admin list: name, document, email and order.
				'post_excerpt' => implode( ' ', array_filter( array( $values['first_name'], $values['last_name'], $values['doc_number'], $values['email'], $values['order_number'] ) ) ),
				'meta_input'   => array(
					'_frave_claim'        => $claim,
					'_frave_claim_number' => $number,
					'_frave_claim_type'   => $values['claim_type'],
					'_frave_claim_status' => 'pendiente',
					'_frave_claim_due'    => frave_peru_add_business_days( $now, FRAVE_PERU_CLAIM_RESPONSE_DAYS )->format( 'Y-m-d' ),
				),
			)
		),
		true
	);
}

/** The stored submission plus its current handling state, or null. */
function frave_peru_get_claim( int $post_id ): ?array {
	if ( 'frave_reclamo' !== get_post_type( $post_id ) ) {
		return null;
	}
	$claim = get_post_meta( $post_id, '_frave_claim', true );
	if ( ! is_array( $claim ) ) {
		return null;
	}

	return array_merge(
		$claim,
		array(
			'id'             => $post_id,
			'status'         => (string) get_post_meta( $post_id, '_frave_claim_status', true ),
			'due'            => (string) get_post_meta( $post_id, '_frave_claim_due', true ),
			'response'       => (string) get_post_meta( $post_id, '_frave_claim_response', true ),
			'response_date'  => (string) get_post_meta( $post_id, '_frave_claim_response_date', true ),
			'response_draft' => (string) get_post_meta( $post_id, '_frave_claim_response_draft', true ),
			'receipt_sent'   => (string) get_post_meta( $post_id, '_frave_claim_receipt_sent', true ),
		)
	);
}

/** Format a stored ATOM or Y-m-d date in the site timezone. */
function frave_peru_format_date( string $date, bool $with_time = false ): string {
	if ( '' === $date ) {
		return '';
	}
	$timestamp = ( new DateTimeImmutable( $date, wp_timezone() ) )->getTimestamp();
	return (string) wp_date( $with_time ? 'd/m/Y H:i' : 'd/m/Y', $timestamp );
}

/** Legal notes printed with every sheet. */
function frave_peru_claim_legal_notes(): array {
	return array(
		__( 'Reclamo: disconformidad relacionada con los productos o servicios.', 'frave-peru' ),
		__( 'Queja: disconformidad no relacionada con los productos o servicios, o malestar o descontento respecto a la atención al público.', 'frave-peru' ),
		sprintf(
			/* translators: %d: number of business days. */
			__( 'El proveedor deberá dar respuesta al reclamo o queja en un plazo no mayor a %d días hábiles.', 'frave-peru' ),
			FRAVE_PERU_CLAIM_RESPONSE_DAYS
		),
		__( 'La formulación del reclamo no impide acudir a otras vías de solución de controversias ni es requisito previo para interponer una denuncia ante el INDECOPI.', 'frave-peru' ),
	);
}

/** The sheet as an HTML table with inline styles, so it renders the same in email clients. */
function frave_peru_render_claim_sheet( array $claim ): string {
	$label = static fn( array $options, string $key ): string => $options[ $key ] ?? $key;
	$name  = trim( $claim['first_name'] . ' ' . $claim['last_name'] );

	$sections = array(
		__( 'Proveedor', 'frave-peru' ) => array(
			__( 'Razón social', 'frave-peru' ) => $claim['provider_name'],
			__( 'RUC', 'frave-peru' )          => $claim['provider_ruc'],
			__( 'Domicilio', 'frave-peru' )    => $claim['provider_address'],
		),
		__( '1. Identificación del consumidor reclamante', 'frave-peru' ) => array(
			__( 'Nombre', 'frave-peru' )                   => $name,
			__( 'Documento', 'frave-peru' )                => $label( frave_peru_claim_document_types(), $claim['doc_type'] ) . ' ' . $claim['doc_number'],
			__( 'Domicilio', 'frave-peru' )                => $claim['address'],
			__( 'Teléfono', 'frave-peru' )                 => $claim['phone'],
			__( 'Correo electrónico', 'frave-peru' )       => $claim['email'],
			__( 'Padre, madre o apoderado', 'frave-peru' ) => $claim['is_minor'] ? $claim['guardian'] : '',
		),
		__( '2. Identificación del bien contratado', 'frave-peru' ) => array(
			__( 'Tipo', 'frave-peru' )             => $label( frave_peru_claim_good_types(), $claim['good_type'] ),
			__( 'Número de pedido', 'frave-peru' ) => $claim['order_number'],
			__( 'Monto reclamado', 'frave-peru' )  => '' !== $claim['amount'] ? 'S/ ' . number_format_i18n( (float) $claim['amount'], 2 ) : '',
			__( 'Descripción', 'frave-peru' )      => $claim['good_description'],
		),
		__( '3. Detalle de la reclamación y pedido del consumidor', 'frave-peru' ) => array(
			__( 'Tipo', 'frave-peru' )    => $label( frave_peru_claim_types(), $claim['claim_type'] ),
			__( 'Detalle', 'frave-peru' ) => $claim['detail'],
			__( 'Pedido', 'frave-peru' )  => $claim['request'],
		),
		__( '4. Observaciones y acciones adoptadas por el proveedor', 'frave-peru' ) => array(
			__( 'Respuesta', 'frave-peru' ) => '' !== ( $claim['response_date'] ?? '' ) ? $claim['response'] : __( 'Pendiente', 'frave-peru' ),
			__( 'Fecha de comunicación de la respuesta', 'frave-peru' ) => frave_peru_format_date( $claim['response_date'] ?? '' ),
		),
	);

	$cell = 'padding:8px 10px;border:1px solid #e5e1dc;text-align:left;vertical-align:top;';
	ob_start();
	?>
	<table class="frave-claim-sheet" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:0 0 16px;">
		<caption style="padding:0 0 10px;text-align:left;font-weight:700;">
			<?php
			printf(
				/* translators: 1: sheet number, 2: submission date and time. */
				esc_html__( 'Hoja de reclamación N.º %1$s — %2$s', 'frave-peru' ),
				esc_html( $claim['number'] ),
				esc_html( frave_peru_format_date( $claim['submitted_at'], true ) )
			);
			?>
		</caption>
		<?php foreach ( $sections as $section => $rows ) : ?>
			<?php $rows = array_filter( $rows, static fn( $value ) => '' !== trim( (string) $value ) ); ?>
			<?php
			if ( ! $rows ) {
				continue; }
			?>
			<tr><th colspan="2" scope="colgroup" style="<?php echo esc_attr( $cell ); ?>background:#f6f4f1;"><?php echo esc_html( $section ); ?></th></tr>
			<?php foreach ( $rows as $row_label => $value ) : ?>
				<tr>
					<th scope="row" style="<?php echo esc_attr( $cell ); ?>width:34%;font-weight:600;"><?php echo esc_html( $row_label ); ?></th>
					<td style="<?php echo esc_attr( $cell ); ?>"><?php echo nl2br( esc_html( (string) $value ), false ); ?></td>
				</tr>
			<?php endforeach; ?>
		<?php endforeach; ?>
	</table>
	<ul class="frave-claim-sheet__notes" style="margin:0;padding-left:18px;font-size:13px;color:#6b6b68;">
		<?php foreach ( frave_peru_claim_legal_notes() as $note ) : ?>
			<li><?php echo esc_html( $note ); ?></li>
		<?php endforeach; ?>
	</ul>
	<?php
	return (string) ob_get_clean();
}

/** Send an HTML email with the WooCommerce template, so it matches the store's emails. */
function frave_peru_send_html_email( string $to, string $subject, string $heading, string $html, string $reply_to = '' ): bool {
	$headers = "Content-Type: text/html\r\n";
	if ( is_email( $reply_to ) ) {
		$headers .= 'Reply-To: ' . $reply_to . "\r\n";
	}

	$mailer = WC()->mailer();
	return (bool) $mailer->send( $to, $subject, $mailer->wrap_message( $heading, $html ), $headers );
}

/** Copy of the sheet for the consumer. Returns whether the email was accepted for delivery. */
function frave_peru_send_claim_receipt( int $post_id ): bool {
	$claim = frave_peru_get_claim( $post_id );
	if ( ! $claim ) {
		return false;
	}

	$type  = frave_peru_claim_types()[ $claim['claim_type'] ] ?? '';
	$intro = sprintf(
		/* translators: 1: "reclamo" or "queja", 2: sheet number, 3: deadline date. */
		__( 'Registramos tu %1$s con la hoja N.º %2$s. Te responderemos a más tardar el %3$s. Conserva este correo como constancia.', 'frave-peru' ),
		strtolower( $type ),
		$claim['number'],
		frave_peru_format_date( $claim['due'] )
	);
	/* translators: %s: sheet number. */
	$subject = sprintf( __( 'Constancia de tu hoja de reclamación N.º %s', 'frave-peru' ), $claim['number'] );

	$sent = frave_peru_send_html_email(
		$claim['email'],
		$subject,
		__( 'Libro de Reclamaciones', 'frave-peru' ),
		'<p>' . esc_html( $intro ) . '</p>' . frave_peru_render_claim_sheet( $claim )
	);
	if ( $sent ) {
		update_post_meta( $post_id, '_frave_claim_receipt_sent', ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( DATE_ATOM ) );
	}
	return $sent;
}

/** Alert the team about a new sheet; replies go straight to the consumer. */
function frave_peru_notify_new_claim( int $post_id ): bool {
	$claim = frave_peru_get_claim( $post_id );
	if ( ! $claim ) {
		return false;
	}

	$settings = frave_peru_claims_settings();
	$to       = is_email( $settings['notify_email'] ) ? $settings['notify_email'] : (string) get_option( 'admin_email' );
	$type     = frave_peru_claim_types()[ $claim['claim_type'] ] ?? '';
	$edit_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );

	$body = sprintf(
		'<p>%s</p><p><a href="%s">%s</a></p>',
		esc_html(
			sprintf(
				/* translators: 1: "Reclamo" or "Queja", 2: sheet number, 3: deadline date. */
				__( 'Nueva hoja: %1$s N.º %2$s. Plazo de respuesta: %3$s.', 'frave-peru' ),
				$type,
				$claim['number'],
				frave_peru_format_date( $claim['due'] )
			)
		),
		esc_url( $edit_url ),
		esc_html__( 'Ver y responder en el administrador', 'frave-peru' )
	) . frave_peru_render_claim_sheet( $claim );

	return frave_peru_send_html_email(
		$to,
		/* translators: 1: "Reclamo" or "Queja", 2: sheet number. */
		sprintf( __( '[Libro de Reclamaciones] %1$s N.º %2$s', 'frave-peru' ), $type, $claim['number'] ),
		__( 'Nueva hoja de reclamación', 'frave-peru' ),
		$body,
		$claim['email']
	);
}
