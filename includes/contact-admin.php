<?php
/** Admin side of the contact form: recipient setting, message list and message screen. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function frave_peru_contact_admin_menu(): void {
	add_submenu_page(
		'edit.php?post_type=frave_mensaje',
		__( 'Ajustes del formulario de contacto', 'frave-peru' ),
		__( 'Ajustes', 'frave-peru' ),
		'manage_woocommerce',
		'frave-mensajes-ajustes',
		'frave_peru_render_contact_settings_page'
	);
}
add_action( 'admin_menu', 'frave_peru_contact_admin_menu' );

function frave_peru_register_contact_settings(): void {
	register_setting(
		'frave_peru_contact',
		'frave_peru_contact_email',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'frave_peru_sanitize_contact_email',
			'default'           => '',
		)
	);
}
add_action( 'admin_init', 'frave_peru_register_contact_settings' );

function frave_peru_contact_settings_capability(): string {
	return 'manage_woocommerce';
}
add_filter( 'option_page_capability_frave_peru_contact', 'frave_peru_contact_settings_capability' );

function frave_peru_sanitize_contact_email( $value ): string {
	$email = sanitize_email( (string) $value );
	if ( '' !== trim( (string) $value ) && ! is_email( $email ) ) {
		add_settings_error( 'frave_peru_contact_email', 'invalid_email', __( 'El correo no es válido; se conserva el valor anterior.', 'frave-peru' ) );
		return (string) get_option( 'frave_peru_contact_email', '' );
	}
	return $email;
}

function frave_peru_render_contact_settings_page(): void {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Ajustes del formulario de contacto', 'frave-peru' ); ?></h1>
		<?php settings_errors( 'frave_peru_contact_email' ); ?>
		<p><?php esc_html_e( 'Coloca el shortcode [frave_contacto] en la página de contacto. Cada mensaje se guarda en «Mensajes» y se envía por correo a esta dirección.', 'frave-peru' ); ?></p>
		<form method="post" action="options.php">
			<?php settings_fields( 'frave_peru_contact' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="frave-contact-email"><?php esc_html_e( 'Enviar mensajes a', 'frave-peru' ); ?></label></th>
					<td>
						<input type="email" class="regular-text" id="frave-contact-email" name="frave_peru_contact_email" value="<?php echo esc_attr( (string) get_option( 'frave_peru_contact_email', '' ) ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Si queda vacío, se usa el correo del administrador.', 'frave-peru' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

function frave_peru_messages_columns( array $columns ): array {
	return array(
		'cb'            => $columns['cb'] ?? '',
		'title'         => __( 'Remitente y tema', 'frave-peru' ),
		'frave_email'   => __( 'Correo', 'frave-peru' ),
		'frave_order'   => __( 'Pedido', 'frave-peru' ),
		'frave_emailed' => __( 'Aviso por correo', 'frave-peru' ),
		'date'          => __( 'Recibido', 'frave-peru' ),
	);
}
add_filter( 'manage_frave_mensaje_posts_columns', 'frave_peru_messages_columns' );

function frave_peru_messages_column( string $column, int $post_id ): void {
	$message = get_post_meta( $post_id, '_frave_message', true );
	if ( ! is_array( $message ) ) {
		return;
	}
	switch ( $column ) {
		case 'frave_email':
			printf( '<a href="mailto:%1$s">%2$s</a>', esc_attr( $message['email'] ), esc_html( $message['email'] ) );
			break;
		case 'frave_order':
			echo esc_html( $message['order_number'] ? '#' . $message['order_number'] : '—' );
			break;
		case 'frave_emailed':
			echo '1' === get_post_meta( $post_id, '_frave_message_emailed', true )
				? esc_html__( 'Enviado', 'frave-peru' )
				: '<strong style="color:#b32d2e;">' . esc_html__( 'Falló: revisa el mensaje aquí', 'frave-peru' ) . '</strong>';
			break;
	}
}
add_action( 'manage_frave_mensaje_posts_custom_column', 'frave_peru_messages_column', 10, 2 );

function frave_peru_messages_row_actions( array $actions, WP_Post $post ): array {
	if ( 'frave_mensaje' === $post->post_type ) {
		unset( $actions['inline hide-if-no-js'] );
		if ( isset( $actions['edit'] ) ) {
			$actions['edit'] = sprintf( '<a href="%s">%s</a>', esc_url( (string) get_edit_post_link( $post->ID ) ), esc_html__( 'Leer', 'frave-peru' ) );
		}
	}
	return $actions;
}
add_filter( 'post_row_actions', 'frave_peru_messages_row_actions', 10, 2 );

function frave_peru_message_meta_boxes(): void {
	add_meta_box( 'frave-message-box', __( 'Mensaje', 'frave-peru' ), 'frave_peru_render_message_meta_box', 'frave_mensaje', 'normal', 'high' );
	remove_meta_box( 'slugdiv', 'frave_mensaje', 'normal' );
	remove_meta_box( 'submitdiv', 'frave_mensaje', 'side' );
}
add_action( 'add_meta_boxes_frave_mensaje', 'frave_peru_message_meta_boxes' );

function frave_peru_render_message_meta_box( WP_Post $post ): void {
	$message = get_post_meta( $post->ID, '_frave_message', true );
	if ( ! is_array( $message ) ) {
		esc_html_e( 'No se encontraron los datos de este mensaje.', 'frave-peru' );
		return;
	}
	echo frave_peru_render_contact_message( $message ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while rendering.
	$subject = rawurlencode( 'Re: ' . ( frave_peru_contact_subjects()[ $message['subject'] ] ?? '' ) );
	printf(
		'<p><a class="button button-primary" href="mailto:%1$s?subject=%2$s">%3$s</a> <a class="submitdelete" style="margin-left:12px;" href="%4$s">%5$s</a></p>',
		esc_attr( $message['email'] ),
		esc_attr( $subject ),
		esc_html__( 'Responder por correo', 'frave-peru' ),
		esc_url( (string) get_delete_post_link( $post->ID ) ),
		esc_html__( 'Mover a la papelera', 'frave-peru' )
	);
}

/** Messages have no title or editor; autosave would only add misleading notices. */
function frave_peru_messages_disable_autosave(): void {
	$screen = get_current_screen();
	if ( $screen && 'frave_mensaje' === $screen->post_type && 'post' === $screen->base ) {
		wp_dequeue_script( 'autosave' );
	}
}
add_action( 'admin_enqueue_scripts', 'frave_peru_messages_disable_autosave', 100 );
