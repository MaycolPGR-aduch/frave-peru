<?php
/** Admin side of the Libro de Reclamaciones: settings, sheet list and provider response. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------- Settings ---------- */

function frave_peru_claims_admin_menu(): void {
	add_submenu_page(
		'edit.php?post_type=frave_reclamo',
		__( 'Ajustes del Libro de Reclamaciones', 'frave-peru' ),
		__( 'Ajustes', 'frave-peru' ),
		'manage_woocommerce',
		'frave-reclamos-ajustes',
		'frave_peru_render_claims_settings_page'
	);
}
add_action( 'admin_menu', 'frave_peru_claims_admin_menu' );

function frave_peru_register_claims_settings(): void {
	register_setting(
		'frave_peru_claims',
		'frave_peru_claims_settings',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'frave_peru_sanitize_claims_settings',
			'default'           => array(),
		)
	);
}
add_action( 'admin_init', 'frave_peru_register_claims_settings' );

/** Shop managers can edit these settings, not only administrators. */
function frave_peru_claims_settings_capability(): string {
	return 'manage_woocommerce';
}
add_filter( 'option_page_capability_frave_peru_claims', 'frave_peru_claims_settings_capability' );

function frave_peru_sanitize_claims_settings( $input ): array {
	$input   = is_array( $input ) ? $input : array();
	$current = frave_peru_claims_settings();

	$ruc = frave_peru_normalize_document( sanitize_text_field( (string) ( $input['ruc'] ?? '' ) ) );
	if ( '' !== $ruc && ! frave_peru_is_valid_ruc( $ruc ) ) {
		add_settings_error( 'frave_peru_claims_settings', 'invalid_ruc', __( 'El RUC no es válido; se conserva el valor anterior.', 'frave-peru' ) );
		$ruc = $current['ruc'];
	}

	$email = sanitize_email( (string) ( $input['notify_email'] ?? '' ) );
	if ( '' !== trim( (string) ( $input['notify_email'] ?? '' ) ) && ! is_email( $email ) ) {
		add_settings_error( 'frave_peru_claims_settings', 'invalid_email', __( 'El correo de avisos no es válido; se conserva el valor anterior.', 'frave-peru' ) );
		$email = $current['notify_email'];
	}

	return array(
		'business_name' => sanitize_text_field( (string) ( $input['business_name'] ?? '' ) ),
		'ruc'           => $ruc,
		'address'       => sanitize_text_field( (string) ( $input['address'] ?? '' ) ),
		'page_id'       => absint( $input['page_id'] ?? 0 ),
		'notify_email'  => $email,
	);
}

function frave_peru_render_claims_settings_page(): void {
	$settings = frave_peru_claims_settings();
	$name     = 'frave_peru_claims_settings';
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Ajustes del Libro de Reclamaciones', 'frave-peru' ); ?></h1>
		<?php settings_errors( 'frave_peru_claims_settings' ); ?>
		<p><?php esc_html_e( 'Estos datos del proveedor aparecen en el formulario y se copian en cada hoja al registrarse.', 'frave-peru' ); ?></p>
		<form method="post" action="options.php">
			<?php settings_fields( 'frave_peru_claims' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="frave-business-name"><?php esc_html_e( 'Razón social', 'frave-peru' ); ?></label></th>
					<td><input type="text" class="regular-text" id="frave-business-name" name="<?php echo esc_attr( $name ); ?>[business_name]" value="<?php echo esc_attr( $settings['business_name'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="frave-ruc"><?php esc_html_e( 'RUC', 'frave-peru' ); ?></label></th>
					<td><input type="text" class="regular-text" id="frave-ruc" name="<?php echo esc_attr( $name ); ?>[ruc]" value="<?php echo esc_attr( $settings['ruc'] ); ?>" maxlength="11" inputmode="numeric"></td>
				</tr>
				<tr>
					<th scope="row"><label for="frave-address"><?php esc_html_e( 'Domicilio', 'frave-peru' ); ?></label></th>
					<td><input type="text" class="large-text" id="frave-address" name="<?php echo esc_attr( $name ); ?>[address]" value="<?php echo esc_attr( $settings['address'] ); ?>">
					<p class="description"><?php esc_html_e( 'Dirección del establecimiento o domicilio fiscal que debe figurar en la hoja.', 'frave-peru' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="frave-page"><?php esc_html_e( 'Página del libro', 'frave-peru' ); ?></label></th>
					<td>
						<?php
						wp_dropdown_pages(
							array(
								'name'              => esc_attr( $name ) . '[page_id]',
								'id'                => 'frave-page',
								'selected'          => absint( $settings['page_id'] ),
								'show_option_none'  => esc_html__( '— Elige una página —', 'frave-peru' ),
								'option_none_value' => '0',
							)
						);
						?>
						<p class="description"><?php esc_html_e( 'Página publicada que contiene el shortcode [frave_libro_reclamaciones]. El tema enlaza a ella desde el pie de página.', 'frave-peru' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="frave-notify"><?php esc_html_e( 'Correo de avisos', 'frave-peru' ); ?></label></th>
					<td><input type="email" class="regular-text" id="frave-notify" name="<?php echo esc_attr( $name ); ?>[notify_email]" value="<?php echo esc_attr( $settings['notify_email'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>">
					<p class="description"><?php esc_html_e( 'Recibe una copia de cada hoja nueva. Si queda vacío, se usa el correo del administrador.', 'frave-peru' ); ?></p></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/** Missing provider data makes the sheets incomplete, so warn on the dashboard and claim screens. */
function frave_peru_claims_settings_notice(): void {
	$screen = get_current_screen();
	if ( ! current_user_can( 'manage_woocommerce' ) || ! $screen || ! in_array( $screen->id, array( 'dashboard', 'edit-frave_reclamo', 'frave_reclamo', 'frave_reclamo_page_frave-reclamos-ajustes' ), true ) ) {
		return;
	}

	$settings = frave_peru_claims_settings();
	$missing  = array_keys(
		array_filter(
			array(
				__( 'razón social', 'frave-peru' )     => '' === $settings['business_name'],
				__( 'RUC', 'frave-peru' )              => '' === $settings['ruc'],
				__( 'domicilio', 'frave-peru' )        => '' === $settings['address'],
				__( 'página publicada', 'frave-peru' ) => '' === frave_peru_claims_page_url(),
				// Sheet dates and deadlines use the site timezone.
				__( 'zona horaria America/Lima en Ajustes → Generales', 'frave-peru' ) => ! in_array( wp_timezone_string(), array( 'America/Lima', '-05:00' ), true ),
			)
		)
	);
	if ( ! $missing ) {
		return;
	}
	?>
	<div class="notice notice-warning"><p>
		<?php
		printf(
			/* translators: %s: comma-separated list of missing settings. */
			esc_html__( 'Libro de Reclamaciones: falta completar %s.', 'frave-peru' ),
			esc_html( implode( ', ', $missing ) )
		);
		?>
		<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=frave_reclamo&page=frave-reclamos-ajustes' ) ); ?>"><?php esc_html_e( 'Ir a los ajustes', 'frave-peru' ); ?></a>
	</p></div>
	<?php
}
add_action( 'admin_notices', 'frave_peru_claims_settings_notice' );

/* ---------- Sheet list ---------- */

function frave_peru_claims_columns( array $columns ): array {
	return array(
		'cb'             => $columns['cb'] ?? '',
		'title'          => __( 'Hoja N.º', 'frave-peru' ),
		'frave_type'     => __( 'Tipo', 'frave-peru' ),
		'frave_consumer' => __( 'Consumidor', 'frave-peru' ),
		'frave_status'   => __( 'Estado', 'frave-peru' ),
		'frave_due'      => __( 'Plazo de respuesta', 'frave-peru' ),
		'date'           => __( 'Registrada', 'frave-peru' ),
	);
}
add_filter( 'manage_frave_reclamo_posts_columns', 'frave_peru_claims_columns' );

function frave_peru_claims_column( string $column, int $post_id ): void {
	$claim = frave_peru_get_claim( $post_id );
	if ( ! $claim ) {
		return;
	}

	switch ( $column ) {
		case 'frave_type':
			echo esc_html( frave_peru_claim_types()[ $claim['claim_type'] ] ?? '' );
			break;
		case 'frave_consumer':
			echo esc_html( trim( $claim['first_name'] . ' ' . $claim['last_name'] ) ) . '<br><span class="description">' . esc_html( $claim['email'] ) . '</span>';
			break;
		case 'frave_status':
			if ( 'respondida' === $claim['status'] ) {
				/* translators: %s: date the answer was sent. */
				echo esc_html( sprintf( __( 'Respondida el %s', 'frave-peru' ), frave_peru_format_date( $claim['response_date'] ) ) );
			} else {
				esc_html_e( 'Pendiente', 'frave-peru' );
			}
			break;
		case 'frave_due':
			$overdue = 'respondida' !== $claim['status'] && $claim['due'] < wp_date( 'Y-m-d' );
			echo $overdue ? '<strong style="color:#b32d2e;">' : '';
			echo esc_html( frave_peru_format_date( $claim['due'] ) );
			echo $overdue ? ' · ' . esc_html__( 'vencido', 'frave-peru' ) . '</strong>' : '';
			break;
	}
}
add_action( 'manage_frave_reclamo_posts_custom_column', 'frave_peru_claims_column', 10, 2 );

/** Sheets are records: no quick edit or bulk edit. */
function frave_peru_claims_row_actions( array $actions, WP_Post $post ): array {
	if ( 'frave_reclamo' !== $post->post_type ) {
		return $actions;
	}
	unset( $actions['inline hide-if-no-js'] );
	if ( isset( $actions['edit'] ) ) {
		$actions['edit'] = sprintf( '<a href="%s">%s</a>', esc_url( (string) get_edit_post_link( $post->ID ) ), esc_html__( 'Ver y responder', 'frave-peru' ) );
	}
	return $actions;
}
add_filter( 'post_row_actions', 'frave_peru_claims_row_actions', 10, 2 );
add_filter( 'bulk_actions-edit-frave_reclamo', '__return_empty_array' );

/* ---------- Sheet screen and response ---------- */

function frave_peru_claim_meta_boxes(): void {
	// Box IDs must differ from the field IDs inside them (e.g. #frave-claim-response).
	add_meta_box( 'frave-claim-sheet-box', __( 'Hoja de reclamación', 'frave-peru' ), 'frave_peru_render_sheet_meta_box', 'frave_reclamo', 'normal', 'high' );
	add_meta_box( 'frave-claim-response-box', __( 'Respuesta del proveedor', 'frave-peru' ), 'frave_peru_render_response_meta_box', 'frave_reclamo', 'normal', 'default' );
	remove_meta_box( 'slugdiv', 'frave_reclamo', 'normal' );
}
add_action( 'add_meta_boxes_frave_reclamo', 'frave_peru_claim_meta_boxes' );

function frave_peru_render_sheet_meta_box( WP_Post $post ): void {
	$claim = frave_peru_get_claim( $post->ID );
	if ( ! $claim ) {
		esc_html_e( 'No se encontraron los datos de esta hoja.', 'frave-peru' );
		return;
	}
	echo frave_peru_render_claim_sheet( $claim ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while rendering.
}

function frave_peru_render_response_meta_box( WP_Post $post ): void {
	$claim = frave_peru_get_claim( $post->ID );
	if ( ! $claim ) {
		return;
	}
	wp_nonce_field( 'frave_claim_response_' . $post->ID, 'frave_claim_response_nonce' );
	$draft = '' !== $claim['response_draft'] ? $claim['response_draft'] : $claim['response'];
	?>
	<p>
		<?php
		printf(
			/* translators: 1: deadline date, 2: number of business days. */
			esc_html__( 'Plazo de respuesta: %1$s (%2$d días hábiles; revisa los feriados del año).', 'frave-peru' ),
			esc_html( frave_peru_format_date( $claim['due'] ) ),
			(int) FRAVE_PERU_CLAIM_RESPONSE_DAYS
		);
		?>
		<br>
		<?php if ( $claim['receipt_sent'] ) : ?>
			<?php
			/* translators: %s: date and time. */
			printf( esc_html__( 'Constancia enviada al consumidor el %s.', 'frave-peru' ), esc_html( frave_peru_format_date( $claim['receipt_sent'], true ) ) );
			?>
		<?php else : ?>
			<strong><?php esc_html_e( 'La constancia no se pudo enviar al consumidor.', 'frave-peru' ); ?></strong>
		<?php endif; ?>
	</p>
	<?php if ( 'respondida' === $claim['status'] ) : ?>
		<p>
			<?php
			/* translators: %s: date the answer was sent. */
			printf( esc_html__( 'Respuesta comunicada por correo el %s. El texto enviado figura en la hoja.', 'frave-peru' ), esc_html( frave_peru_format_date( $claim['response_date'], true ) ) );
			?>
		</p>
	<?php endif; ?>
	<p>
		<label for="frave-claim-response"><strong><?php esc_html_e( 'Observaciones y acciones adoptadas', 'frave-peru' ); ?></strong></label>
		<textarea class="widefat" rows="8" id="frave-claim-response" name="frave_claim_response"><?php echo esc_textarea( $draft ); ?></textarea>
	</p>
	<p>
		<label><input type="checkbox" name="frave_claim_notify" value="1" <?php checked( 'respondida' !== $claim['status'] ); ?>>
		<?php esc_html_e( 'Enviar la respuesta al consumidor por correo al guardar. La hoja queda como respondida con la fecha del envío.', 'frave-peru' ); ?></label>
	</p>
	<p>
		<label><input type="checkbox" name="frave_claim_resend_receipt" value="1" <?php checked( '' === $claim['receipt_sent'] ); ?>>
		<?php esc_html_e( 'Reenviar la constancia de la hoja al consumidor.', 'frave-peru' ); ?></label>
	</p>
	<?php
}

/** Sheets have no title or editor, so autosave only produces misleading backup and connection notices. */
function frave_peru_claims_disable_autosave(): void {
	$screen = get_current_screen();
	if ( $screen && 'frave_reclamo' === $screen->post_type && 'post' === $screen->base ) {
		wp_dequeue_script( 'autosave' );
	}
}
add_action( 'admin_enqueue_scripts', 'frave_peru_claims_disable_autosave', 100 );

/** Result of the last save, carried to the redirect so the screen can report it. */
function frave_peru_claim_mail_result( ?string $result = null ): string {
	static $current = '';
	if ( null !== $result ) {
		$current = $result;
	}
	return $current;
}

function frave_peru_save_claim_response( int $post_id ): void {
	$nonce = sanitize_key( wp_unslash( $_POST['frave_claim_response_nonce'] ?? '' ) );
	if ( ! wp_verify_nonce( $nonce, 'frave_claim_response_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$claim = frave_peru_get_claim( $post_id );
	if ( ! $claim ) {
		return;
	}

	$draft = sanitize_textarea_field( wp_unslash( $_POST['frave_claim_response'] ?? '' ) );
	update_post_meta( $post_id, '_frave_claim_response_draft', wp_slash( $draft ) );

	$results = array();
	if ( ! empty( $_POST['frave_claim_notify'] ) ) {
		$results[] = '' === $draft ? 'empty' : ( frave_peru_send_claim_response( $post_id, $draft ) ? 'response-sent' : 'response-failed' );
	}
	if ( ! empty( $_POST['frave_claim_resend_receipt'] ) ) {
		$results[] = frave_peru_send_claim_receipt( $post_id ) ? 'receipt-sent' : 'receipt-failed';
	}
	frave_peru_claim_mail_result( implode( ',', $results ) );
}
add_action( 'save_post_frave_reclamo', 'frave_peru_save_claim_response' );

/** Email the answer and, once accepted for delivery, record it as the communicated response. */
function frave_peru_send_claim_response( int $post_id, string $response ): bool {
	$claim = frave_peru_get_claim( $post_id );
	if ( ! $claim ) {
		return false;
	}

	$now   = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( DATE_ATOM );
	$sheet = array_merge(
		$claim,
		array(
			'response'      => $response,
			'response_date' => $now,
		)
	);
	$type  = strtolower( frave_peru_claim_types()[ $claim['claim_type'] ] ?? '' );

	$sent = frave_peru_send_html_email(
		$claim['email'],
		/* translators: 1: "reclamo" or "queja", 2: sheet number. */
		sprintf( __( 'Respuesta a tu %1$s N.º %2$s', 'frave-peru' ), $type, $claim['number'] ),
		__( 'Libro de Reclamaciones', 'frave-peru' ),
		'<p>' . nl2br( esc_html( $response ), false ) . '</p>' . frave_peru_render_claim_sheet( $sheet )
	);

	if ( $sent ) {
		update_post_meta( $post_id, '_frave_claim_response', wp_slash( $response ) );
		update_post_meta( $post_id, '_frave_claim_response_date', $now );
		update_post_meta( $post_id, '_frave_claim_status', 'respondida' );
	}
	return $sent;
}

function frave_peru_claim_redirect_location( string $location, int $post_id ): string {
	$result = frave_peru_claim_mail_result();
	if ( '' === $result || 'frave_reclamo' !== get_post_type( $post_id ) ) {
		return $location;
	}
	return add_query_arg( 'frave_claim_mail', $result, $location );
}
add_filter( 'redirect_post_location', 'frave_peru_claim_redirect_location', 10, 2 );

function frave_peru_claim_mail_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	$results  = array_filter( explode( ',', sanitize_text_field( wp_unslash( $_GET['frave_claim_mail'] ?? '' ) ) ) );
	$messages = array(
		'response-sent'   => array( 'success', __( 'Respuesta enviada al consumidor. La hoja figura como respondida.', 'frave-peru' ) ),
		'response-failed' => array( 'error', __( 'No se pudo enviar la respuesta por correo. El texto quedó guardado como borrador; revisa la configuración de correo e inténtalo otra vez.', 'frave-peru' ) ),
		'empty'           => array( 'warning', __( 'Escribe la respuesta antes de enviarla al consumidor.', 'frave-peru' ) ),
		'receipt-sent'    => array( 'success', __( 'Constancia reenviada al consumidor.', 'frave-peru' ) ),
		'receipt-failed'  => array( 'error', __( 'No se pudo reenviar la constancia por correo.', 'frave-peru' ) ),
	);
	foreach ( $results as $result ) {
		if ( isset( $messages[ $result ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $result ][0] ), esc_html( $messages[ $result ][1] ) );
		}
	}
}
add_action( 'admin_notices', 'frave_peru_claim_mail_notice' );
