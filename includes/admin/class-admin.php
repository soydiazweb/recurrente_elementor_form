<?php
/**
 * Panel administrativo «Recurrente»: Registros y Ajustes.
 *
 * Usa los componentes nativos de wp-admin (form-table, postbox, widefat,
 * nav-tab) para integrarse con el resto del escritorio.
 *
 * @package Recurrente_Elementor_Form
 */

namespace REF\Admin;

use REF\Api;
use REF\Finalizer;
use REF\Logger;
use REF\Settings;

defined( 'ABSPATH' ) || exit;

class Admin {

	const CAP = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_ref_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'admin_post_ref_log_action', array( __CLASS__, 'handle_log_action' ) );
		add_action( 'admin_post_ref_export_csv', array( __CLASS__, 'export_csv' ) );
		add_filter( 'plugin_action_links_' . REF_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		$counts = Logger::counts();
		$alerts = ( isset( $counts[ Logger::STATUS_ACTIONS_ERROR ] ) ? $counts[ Logger::STATUS_ACTIONS_ERROR ] : 0 )
			+ ( isset( $counts[ Logger::STATUS_REVIEW ] ) ? $counts[ Logger::STATUS_REVIEW ] : 0 );
		$bubble = $alerts ? ' <span class="awaiting-mod">' . (int) $alerts . '</span>' : '';

		add_menu_page(
			__( 'Recurrente', 'recurrente-elementor-form' ),
			__( 'Recurrente', 'recurrente-elementor-form' ) . $bubble,
			self::CAP,
			'ref-logs',
			array( __CLASS__, 'render_logs' ),
			'dashicons-money-alt',
			58
		);

		add_submenu_page( 'ref-logs', __( 'Registros', 'recurrente-elementor-form' ), __( 'Registros', 'recurrente-elementor-form' ), self::CAP, 'ref-logs', array( __CLASS__, 'render_logs' ) );
		add_submenu_page( 'ref-logs', __( 'Ajustes', 'recurrente-elementor-form' ), __( 'Ajustes', 'recurrente-elementor-form' ), self::CAP, 'ref-settings', array( __CLASS__, 'render_settings' ) );
	}

	/**
	 * @param array $links Enlaces.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=ref-settings' ) ) . '">' . esc_html__( 'Ajustes', 'recurrente-elementor-form' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=ref-logs' ) ) . '">' . esc_html__( 'Registros', 'recurrente-elementor-form' ) . '</a>'
		);
		return $links;
	}

	public static function register_settings() {
		register_setting(
			Settings::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * @param string $hook Pantalla.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'ref-' ) ) {
			return;
		}
		wp_enqueue_style( 'ref-admin', REF_URL . 'assets/css/admin.css', array(), REF_VERSION );
		wp_enqueue_script( 'ref-admin', REF_URL . 'assets/js/admin.js', array(), REF_VERSION, true );
		wp_localize_script(
			'ref-admin',
			'REF_ADMIN',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'ref_test_connection' ),
				'i18n'  => array(
					'testing' => __( 'Probando…', 'recurrente-elementor-form' ),
					'copied'  => __( 'Copiado', 'recurrente-elementor-form' ),
					'error'   => __( 'Error de conexión', 'recurrente-elementor-form' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Ajustes
	 * ---------------------------------------------------------------- */

	/**
	 * @return array slug => etiqueta
	 */
	protected static function settings_tabs() {
		return array(
			'credentials' => __( 'Credenciales', 'recurrente-elementor-form' ),
			'general'     => __( 'General', 'recurrente-elementor-form' ),
			'messages'    => __( 'Mensajes', 'recurrente-elementor-form' ),
			'security'    => __( 'Seguridad y registros', 'recurrente-elementor-form' ),
		);
	}

	public static function render_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$tabs = self::settings_tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'credentials';
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'credentials';
		$s   = Settings::all();
		$opt = Settings::OPTION;
		?>
		<div class="wrap ref-wrap">
			<h1><?php esc_html_e( 'Recurrente — Ajustes', 'recurrente-elementor-form' ); ?></h1>

			<?php if ( $s['force_dev'] ) : ?>
				<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Modo desarrollo forzado:', 'recurrente-elementor-form' ); ?></strong> <?php esc_html_e( 'todos los formularios cobran en Sandbox aunque estén configurados en Producción.', 'recurrente-elementor-form' ); ?></p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper wp-clearfix">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ref-settings', 'tab' => $slug ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="options.php" autocomplete="off">
				<?php
				settings_fields( Settings::GROUP );
				// Conserva los valores de las otras pestañas al guardar.
				self::hidden_other_tabs( $tab, $s );

				switch ( $tab ) {
					case 'general':
						self::tab_general( $s, $opt );
						break;
					case 'messages':
						self::tab_messages( $s, $opt );
						break;
					case 'security':
						self::tab_security( $s, $opt );
						break;
					default:
						self::tab_credentials( $s, $opt );
				}

				submit_button();
				?>
			</form>
			<p class="description"><?php echo wp_kses_post( sprintf( /* translators: 1: versión 2: autor */ __( 'Recurrente Elementor Form %1$s · %2$s', 'recurrente-elementor-form' ), esc_html( REF_VERSION ), '<a href="https://www.soydiaz.com" target="_blank" rel="noopener">Jonathan Diaz</a>' ) ); ?></p>
		</div>
		<?php
	}

	/**
	 * Campos de cada pestaña (para no perder valores al guardar otra pestaña).
	 *
	 * @return array tab => keys
	 */
	protected static function tab_keys() {
		return array(
			'credentials' => array( 'dev_secret_key', 'pro_secret_key', 'dev_webhook_secret', 'pro_webhook_secret', 'account_id' ),
			'general'     => array( 'force_dev', 'default_currency', 'reference_prefix', 'reference_in_name', 'send_form_data', 'add_payment_fields' ),
			'messages'    => array( 'msg_checkout_error', 'msg_paid', 'msg_pending', 'msg_cancelled', 'msg_failed' ),
			'security'    => array( 'checkout_hosts', 'rate_limit', 'alert_enabled', 'alert_email', 'reconcile_hours', 'log_retention_days', 'delete_on_uninstall' ),
		);
	}

	/**
	 * @param string $current Pestaña actual.
	 * @param array  $s       Ajustes.
	 */
	protected static function hidden_other_tabs( $current, array $s ) {
		$secret = array( 'dev_secret_key', 'pro_secret_key', 'dev_webhook_secret', 'pro_webhook_secret' );
		foreach ( self::tab_keys() as $tab => $keys ) {
			if ( $tab === $current ) {
				continue;
			}
			foreach ( $keys as $key ) {
				if ( in_array( $key, $secret, true ) ) {
					continue; // Vacío = se conserva el secreto guardado.
				}
				$value = $s[ $key ];
				if ( is_bool( $value ) ) {
					if ( ! $value ) {
						continue;
					}
					$value = '1';
				}
				printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( Settings::OPTION . '[' . $key . ']' ), esc_attr( (string) $value ) );
			}
		}
	}

	/**
	 * @param array  $s   Ajustes.
	 * @param string $opt Nombre de la opción.
	 */
	protected static function tab_credentials( array $s, $opt ) {
		foreach ( array( Settings::MODE_DEV, Settings::MODE_PRO ) as $mode ) {
			$key_name = $mode . '_secret_key';
			$wh_name  = $mode . '_webhook_secret';
			$prefix   = Settings::MODE_PRO === $mode ? 'sk_live_' : 'sk_test_';
			$current  = (string) $s[ $key_name ];
			$mismatch = '' !== $current && 0 !== strpos( $current, $prefix );
			?>
			<h2 class="title"><?php echo esc_html( Settings::mode_label( $mode ) ); ?></h2>
			<p>
				<?php
				echo Settings::MODE_PRO === $mode
					? esc_html__( 'Llave LIVE: Recurrente → Configuración → Desarrolladores. Procesa cobros reales.', 'recurrente-elementor-form' )
					: esc_html__( 'Llave TEST: Recurrente → Configuración → Desarrolladores → Abrir Sandbox → Crear mi llave TEST.', 'recurrente-elementor-form' );
				?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ref-<?php echo esc_attr( $key_name ); ?>"><?php esc_html_e( 'Llave secreta', 'recurrente-elementor-form' ); ?></label></th>
					<td>
						<input type="password" class="regular-text code" id="ref-<?php echo esc_attr( $key_name ); ?>" name="<?php echo esc_attr( $opt . '[' . $key_name . ']' ); ?>" value="" placeholder="<?php echo esc_attr( '' !== $current ? Settings::mask( $current ) : $prefix . '…' ); ?>" autocomplete="new-password" />
						<button type="button" class="button ref-test" data-mode="<?php echo esc_attr( $mode ); ?>" <?php disabled( '' === $current ); ?>><?php esc_html_e( 'Probar conexión', 'recurrente-elementor-form' ); ?></button>
						<span class="ref-test-result" data-mode="<?php echo esc_attr( $mode ); ?>"></span>
						<p class="description">
							<?php esc_html_e( 'Déjalo vacío para conservar la guardada.', 'recurrente-elementor-form' ); ?>
							<?php if ( '' !== $current ) : ?>
								<label><input type="checkbox" name="<?php echo esc_attr( $opt . '[' . $key_name . '_clear]' ); ?>" value="1" /> <?php esc_html_e( 'Borrar llave', 'recurrente-elementor-form' ); ?></label>
							<?php endif; ?>
						</p>
						<?php if ( $mismatch ) : ?>
							<p class="description ref-text-error">
								<?php
								/* translators: %s: prefijo */
								printf( esc_html__( 'Esta llave no empieza por %s. Verifica que sea del ambiente correcto.', 'recurrente-elementor-form' ), '<code>' . esc_html( $prefix ) . '</code>' );
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ref-<?php echo esc_attr( $wh_name ); ?>"><?php esc_html_e( 'Secreto de firma del webhook', 'recurrente-elementor-form' ); ?></label></th>
					<td>
						<input type="password" class="regular-text code" id="ref-<?php echo esc_attr( $wh_name ); ?>" name="<?php echo esc_attr( $opt . '[' . $wh_name . ']' ); ?>" value="" placeholder="<?php echo esc_attr( '' !== $s[ $wh_name ] ? Settings::mask( $s[ $wh_name ] ) : 'whsec_…' ); ?>" autocomplete="new-password" />
						<p class="description">
							<?php esc_html_e( 'Recomendado. Con él se rechaza cualquier webhook sin firma válida.', 'recurrente-elementor-form' ); ?>
							<?php if ( '' !== $s[ $wh_name ] ) : ?>
								<label><input type="checkbox" name="<?php echo esc_attr( $opt . '[' . $wh_name . '_clear]' ); ?>" value="1" /> <?php esc_html_e( 'Borrar secreto', 'recurrente-elementor-form' ); ?></label>
							<?php endif; ?>
						</p>
					</td>
				</tr>
			</table>
			<?php
		}
		?>
		<h2 class="title"><?php esc_html_e( 'Webhook', 'recurrente-elementor-form' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'URL del webhook', 'recurrente-elementor-form' ); ?></th>
				<td>
					<input type="text" class="large-text code ref-copy-src" readonly value="<?php echo esc_attr( Settings::webhook_url() ); ?>" onclick="this.select()" />
					<p class="description"><?php esc_html_e( 'Regístrala en Recurrente → Configuración → Desarrolladores y API → Webhooks, en la cuenta LIVE y dentro del Sandbox. Eventos: intent.succeeded, intent.failed, intent.pending, intent.canceled.', 'recurrente-elementor-form' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ref-account"><?php esc_html_e( 'X-ACCOUNT-ID', 'recurrente-elementor-form' ); ?></label></th>
				<td>
					<input type="text" class="regular-text code" id="ref-account" name="<?php echo esc_attr( $opt . '[account_id]' ); ?>" value="<?php echo esc_attr( $s['account_id'] ); ?>" placeholder="ac_…" />
					<p class="description"><?php esc_html_e( 'Opcional. Solo si cobras en nombre de una cuenta conectada a tu plataforma.', 'recurrente-elementor-form' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * @param array  $s   Ajustes.
	 * @param string $opt Opción.
	 */
	protected static function tab_general( array $s, $opt ) {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Forzar modo desarrollo', 'recurrente-elementor-form' ); ?></th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $opt . '[force_dev]' ); ?>" value="1" <?php checked( $s['force_dev'] ); ?> /> <?php esc_html_e( 'Todos los formularios usan Sandbox (útil en staging).', 'recurrente-elementor-form' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="ref-currency"><?php esc_html_e( 'Moneda predeterminada', 'recurrente-elementor-form' ); ?></label></th>
				<td>
					<select id="ref-currency" name="<?php echo esc_attr( $opt . '[default_currency]' ); ?>">
						<option value="GTQ" <?php selected( $s['default_currency'], 'GTQ' ); ?>>GTQ (Q)</option>
						<option value="USD" <?php selected( $s['default_currency'], 'USD' ); ?>>USD ($)</option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ref-prefix"><?php esc_html_e( 'Prefijo de referencia', 'recurrente-elementor-form' ); ?></label></th>
				<td>
					<input type="text" class="small-text code" id="ref-prefix" name="<?php echo esc_attr( $opt . '[reference_prefix]' ); ?>" value="<?php echo esc_attr( $s['reference_prefix'] ); ?>" maxlength="12" />
					<p class="description"><?php esc_html_e( 'Cada envío recibe una referencia única (p. ej. WEB-000123) que se guarda en el sitio, en «Envíos» de Elementor y en Recurrente.', 'recurrente-elementor-form' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Datos enviados a Recurrente', 'recurrente-elementor-form' ); ?></th>
				<td>
					<fieldset>
						<label><input type="checkbox" name="<?php echo esc_attr( $opt . '[reference_in_name]' ); ?>" value="1" <?php checked( $s['reference_in_name'] ); ?> /> <?php esc_html_e( 'Mostrar la referencia en el concepto del cobro (panel de Recurrente y recibo del cliente).', 'recurrente-elementor-form' ); ?></label><br />
						<label><input type="checkbox" name="<?php echo esc_attr( $opt . '[send_form_data]' ); ?>" value="1" <?php checked( $s['send_form_data'] ); ?> /> <?php esc_html_e( 'Guardar en la metadata del cobro los datos que llenó el cliente (sin archivos ni contraseñas).', 'recurrente-elementor-form' ); ?></label>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Datos del pago en el correo', 'recurrente-elementor-form' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $opt . '[add_payment_fields]' ); ?>" value="1" <?php checked( $s['add_payment_fields'] ); ?> /> <?php esc_html_e( 'Añadir estado, monto, referencia e ID de pago a las acciones retenidas.', 'recurrente-elementor-form' ); ?></label>
					<p class="description"><?php echo wp_kses_post( __( 'Shortcodes: <code>[field id="recurrente_reference"]</code>, <code>[field id="recurrente_status"]</code>, <code>[field id="recurrente_amount"]</code>, <code>[field id="recurrente_payment_id"]</code>, <code>[field id="recurrente_checkout_id"]</code>. También aparecen en <code>[all-fields]</code>.', 'recurrente-elementor-form' ) ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * @param array  $s   Ajustes.
	 * @param string $opt Opción.
	 */
	protected static function tab_messages( array $s, $opt ) {
		$messages = array(
			'msg_checkout_error' => __( 'No se pudo crear el cobro', 'recurrente-elementor-form' ),
			'msg_paid'           => __( 'Pago exitoso', 'recurrente-elementor-form' ),
			'msg_pending'        => __( 'Pago en proceso', 'recurrente-elementor-form' ),
			'msg_cancelled'      => __( 'Pago cancelado', 'recurrente-elementor-form' ),
			'msg_failed'         => __( 'Pago fallido o expirado', 'recurrente-elementor-form' ),
		);
		?>
		<p><?php esc_html_e( 'El primero se muestra en el formulario. Los demás aparecen como aviso en la página del formulario al volver del checkout (salvo que el formulario tenga su propia página de gracias).', 'recurrente-elementor-form' ); ?></p>
		<table class="form-table" role="presentation">
			<?php foreach ( $messages as $key => $label ) : ?>
				<tr>
					<th scope="row"><label for="ref-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td><textarea class="large-text" rows="2" id="ref-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $opt . '[' . $key . ']' ); ?>"><?php echo esc_textarea( $s[ $key ] ); ?></textarea></td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	/**
	 * @param array  $s   Ajustes.
	 * @param string $opt Opción.
	 */
	protected static function tab_security( array $s, $opt ) {
		?>
		<h2 class="title"><?php esc_html_e( 'Seguridad', 'recurrente-elementor-form' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ref-hosts"><?php esc_html_e( 'Dominios de pago permitidos', 'recurrente-elementor-form' ); ?></label></th>
				<td>
					<input type="text" class="regular-text code" id="ref-hosts" name="<?php echo esc_attr( $opt . '[checkout_hosts]' ); ?>" value="<?php echo esc_attr( $s['checkout_hosts'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Solo se redirige al cliente a estos dominios (HTTPS). Agrega tu dominio propio de Recurrente si lo usas, separado por comas.', 'recurrente-elementor-form' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ref-rate"><?php esc_html_e( 'Límite de cobros por visitante', 'recurrente-elementor-form' ); ?></label></th>
				<td>
					<input type="number" min="0" max="100" class="small-text" id="ref-rate" name="<?php echo esc_attr( $opt . '[rate_limit]' ); ?>" value="<?php echo esc_attr( $s['rate_limit'] ); ?>" />
					<?php esc_html_e( 'por IP cada 10 minutos (0 = sin límite).', 'recurrente-elementor-form' ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Controles automáticos', 'recurrente-elementor-form' ); ?></th>
				<td>
					<ul class="ref-checklist">
						<li><?php esc_html_e( 'El pago se confirma siempre consultando la API de Recurrente; nunca se confía en el navegador ni en un webhook sin verificar.', 'recurrente-elementor-form' ); ?></li>
						<li><?php esc_html_e( 'Antes de enviar se comparan monto, moneda, ambiente (Sandbox/Producción), referencia y registro. Si algo no coincide, el envío queda en «Requiere revisión» y no se envía nada.', 'recurrente-elementor-form' ); ?></li>
						<li><?php esc_html_e( 'Montos tomados de un campo solo se aceptan si están en la lista de montos permitidos del formulario.', 'recurrente-elementor-form' ); ?></li>
						<li><?php esc_html_e( 'Cada pago ejecuta el correo una sola vez, aunque lleguen varios avisos.', 'recurrente-elementor-form' ); ?></li>
					</ul>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Alertas y registros', 'recurrente-elementor-form' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Alertas por correo', 'recurrente-elementor-form' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $opt . '[alert_enabled]' ); ?>" value="1" <?php checked( $s['alert_enabled'] ); ?> /> <?php esc_html_e( 'Avisar si falla la creación del cobro, se rechaza un pago, un pago requiere revisión o falla el envío tras pagar.', 'recurrente-elementor-form' ); ?></label>
					<p><input type="email" class="regular-text" name="<?php echo esc_attr( $opt . '[alert_email]' ); ?>" value="<?php echo esc_attr( $s['alert_email'] ); ?>" /></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ref-reconcile"><?php esc_html_e( 'Conciliar pagos pendientes', 'recurrente-elementor-form' ); ?></label></th>
				<td><input type="number" min="1" max="720" id="ref-reconcile" class="small-text" name="<?php echo esc_attr( $opt . '[reconcile_hours]' ); ?>" value="<?php echo esc_attr( $s['reconcile_hours'] ); ?>" /> <?php esc_html_e( 'horas (cada hora se consulta a Recurrente si se pagaron).', 'recurrente-elementor-form' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="ref-retention"><?php esc_html_e( 'Conservar registros', 'recurrente-elementor-form' ); ?></label></th>
				<td><input type="number" min="0" id="ref-retention" class="small-text" name="<?php echo esc_attr( $opt . '[log_retention_days]' ); ?>" value="<?php echo esc_attr( $s['log_retention_days'] ); ?>" /> <?php esc_html_e( 'días (0 = siempre).', 'recurrente-elementor-form' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Desinstalación', 'recurrente-elementor-form' ); ?></th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $opt . '[delete_on_uninstall]' ); ?>" value="1" <?php checked( $s['delete_on_uninstall'] ); ?> /> <?php esc_html_e( 'Borrar ajustes y registros al eliminar el plugin.', 'recurrente-elementor-form' ); ?></label></td>
			</tr>
		</table>
		<?php
	}

	public static function ajax_test_connection() {
		check_ajax_referer( 'ref_test_connection', 'nonce' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'recurrente-elementor-form' ) ), 403 );
		}

		$mode   = isset( $_POST['mode'] ) && Settings::MODE_PRO === $_POST['mode'] ? Settings::MODE_PRO : Settings::MODE_DEV;
		$result = ( new Api( $mode ) )->test();

		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}

		$env      = isset( $result['body']['environment'] ) ? (string) $result['body']['environment'] : '';
		$expected = Settings::MODE_PRO === $mode ? 'live' : 'sandbox';
		$message  = isset( $result['body']['message'] ) ? (string) $result['body']['message'] : 'OK';

		if ( '' !== $env && $env !== $expected ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: 1: mensaje, 2: ambiente real, 3: esperado */
						__( '%1$s — la llave es del ambiente «%2$s» y este modo espera «%3$s».', 'recurrente-elementor-form' ),
						$message,
						$env,
						$expected
					),
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => trim( $message . ( '' !== $env ? ' · ' . $env : '' ) . ( ! empty( $result['body']['account_id'] ) ? ' · ' . $result['body']['account_id'] : '' ) ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Registros
	 * ---------------------------------------------------------------- */

	/**
	 * Filtros actuales del listado (para CSV y enlaces).
	 *
	 * @return array
	 */
	public static function current_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		return array(
			'status'    => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'mode'      => isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : '',
			'form'      => isset( $_GET['form'] ) ? sanitize_text_field( wp_unslash( $_GET['form'] ) ) : '',
			'search'    => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
			'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
		);
		// phpcs:enable
	}

	public static function render_logs() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0;
		if ( $view ) {
			self::render_log_detail( $view );
			return;
		}

		$table = new Logs_Table();
		$table->prepare_items();

		$filters = self::current_filters();
		$query   = array_filter(
			array(
				'action'    => 'ref_export_csv',
				'status'    => $filters['status'],
				'mode'      => $filters['mode'],
				'form'      => $filters['form'],
				's'         => $filters['search'],
				'date_from' => $filters['date_from'],
				'date_to'   => $filters['date_to'],
			)
		);
		$export_url = wp_nonce_url( add_query_arg( $query, admin_url( 'admin-post.php' ) ), 'ref_export_csv' );
		?>
		<div class="wrap ref-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Registros de Recurrente', 'recurrente-elementor-form' ); ?></h1>
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Exportar CSV', 'recurrente-elementor-form' ); ?></a>
			<hr class="wp-header-end" />
			<?php self::admin_notice_from_query(); ?>
			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="ref-logs" />
				<?php
				$table->search_box( __( 'Buscar', 'recurrente-elementor-form' ), 'ref-search' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	protected static function admin_notice_from_query() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['ref_msg'] ) ) {
			return;
		}
		$msg  = sanitize_text_field( wp_unslash( $_GET['ref_msg'] ) );
		$type = ( isset( $_GET['ref_type'] ) && 'error' === $_GET['ref_type'] ) ? 'error' : 'success';
		// phpcs:enable
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $msg ) );
	}

	/**
	 * Abre un postbox nativo.
	 *
	 * @param string $title Título.
	 */
	protected static function box_open( $title ) {
		echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle">' . esc_html( $title ) . '</h2></div><div class="inside">';
	}

	protected static function box_close() {
		echo '</div></div>';
	}

	/**
	 * @param int $id ID.
	 */
	protected static function render_log_detail( $id ) {
		$log  = Logger::get( $id );
		$back = admin_url( 'admin.php?page=ref-logs' );

		if ( ! $log ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Registro no encontrado', 'recurrente-elementor-form' ) . '</h1><p><a href="' . esc_url( $back ) . '">' . esc_html__( 'Volver a registros', 'recurrente-elementor-form' ) . '</a></p></div>';
			return;
		}

		$events   = json_decode( (string) $log['events'], true );
		$events   = is_array( $events ) ? $events : array();
		$results  = json_decode( (string) $log['actions_result'], true );
		$fields   = Logger::form_fields( $log['record_data'] );
		$deferred = json_decode( (string) $log['deferred_actions'], true );

		$can_verify = '' !== $log['checkout_id'] && in_array( $log['status'], Logger::open_statuses(), true );
		$can_resend = in_array( $log['status'], array( Logger::STATUS_COMPLETED, Logger::STATUS_ACTIONS_ERROR, Logger::STATUS_REVIEW ), true );

		$submission_link = '';
		if ( (int) $log['submission_id'] ) {
			$submission_link = admin_url( 'admin.php?page=e-form-submissions#/' . (int) $log['submission_id'] );
		}
		?>
		<div class="wrap ref-wrap">
			<h1 class="wp-heading-inline">
				<?php
				/* translators: %s: referencia */
				printf( esc_html__( 'Registro %s', 'recurrente-elementor-form' ), esc_html( '' !== $log['reference'] ? $log['reference'] : '#' . $id ) );
				?>
			</h1>
			<a href="<?php echo esc_url( $back ); ?>" class="page-title-action"><?php esc_html_e( 'Volver a registros', 'recurrente-elementor-form' ); ?></a>
			<hr class="wp-header-end" />
			<?php self::admin_notice_from_query(); ?>

			<?php if ( Logger::STATUS_REVIEW === $log['status'] ) : ?>
				<div class="notice notice-error inline"><p><strong><?php esc_html_e( 'Pago con inconsistencias.', 'recurrente-elementor-form' ); ?></strong> <?php echo esc_html( $log['message'] ); ?></p></div>
			<?php endif; ?>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						<?php self::box_open( __( 'Pago', 'recurrente-elementor-form' ) ); ?>
						<table class="widefat striped ref-kv">
							<tbody>
							<?php
							$rows = array(
								__( 'Referencia web', 'recurrente-elementor-form' )        => $log['reference'],
								__( 'Estado', 'recurrente-elementor-form' )                => Logger::status_label( $log['status'] ),
								__( 'Detalle', 'recurrente-elementor-form' )               => $log['message'],
								__( 'Ambiente', 'recurrente-elementor-form' )              => Settings::mode_label( $log['mode'] ),
								__( 'Concepto', 'recurrente-elementor-form' )              => $log['item_name'],
								__( 'Monto', 'recurrente-elementor-form' )                 => (int) $log['amount_in_cents'] > 0 ? Api::format_amount( $log['amount_in_cents'], $log['currency'] ) : '',
								__( 'Checkout Recurrente', 'recurrente-elementor-form' )   => $log['checkout_id'],
								__( 'ID de pago Recurrente', 'recurrente-elementor-form' ) => $log['intent_id'],
								__( 'Acciones retenidas', 'recurrente-elementor-form' )    => is_array( $deferred ) ? implode( ', ', $deferred ) : '',
							);
							foreach ( $rows as $label => $value ) :
								if ( '' === (string) $value ) {
									continue;
								}
								?>
								<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $value ); ?></td></tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<p class="description"><?php esc_html_e( 'En Recurrente puedes buscarlo por el ID de checkout o por la referencia web, que aparece en el concepto y en la metadata «referencia_web».', 'recurrente-elementor-form' ); ?></p>
						<?php self::box_close(); ?>

						<?php self::box_open( __( 'Datos del formulario', 'recurrente-elementor-form' ) ); ?>
						<?php if ( $fields ) : ?>
							<table class="widefat striped ref-kv">
								<tbody>
								<?php foreach ( $fields as $field ) : ?>
									<tr><th scope="row"><?php echo esc_html( $field['title'] ); ?></th><td><?php echo esc_html( $field['value'] ); ?></td></tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						<?php else : ?>
							<p><?php esc_html_e( 'Sin datos.', 'recurrente-elementor-form' ); ?></p>
						<?php endif; ?>
						<?php self::box_close(); ?>

						<?php self::box_open( __( 'Historial', 'recurrente-elementor-form' ) ); ?>
						<?php if ( $events ) : ?>
							<table class="widefat striped">
								<thead><tr><th class="ref-col-date"><?php esc_html_e( 'Fecha', 'recurrente-elementor-form' ); ?></th><th class="ref-col-type"><?php esc_html_e( 'Evento', 'recurrente-elementor-form' ); ?></th><th><?php esc_html_e( 'Detalle', 'recurrente-elementor-form' ); ?></th></tr></thead>
								<tbody>
								<?php foreach ( $events as $event ) : ?>
									<tr><td><?php echo esc_html( $event['at'] ); ?></td><td><code><?php echo esc_html( $event['type'] ); ?></code></td><td><?php echo esc_html( $event['message'] ); ?></td></tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						<?php else : ?>
							<p><?php esc_html_e( 'Sin eventos.', 'recurrente-elementor-form' ); ?></p>
						<?php endif; ?>
						<?php self::box_close(); ?>

						<?php self::box_open( __( 'Comunicación con la API', 'recurrente-elementor-form' ) ); ?>
						<p><strong>POST /checkouts</strong></p>
						<pre class="ref-pre"><?php echo esc_html( self::pretty( $log['request'] ) ); ?></pre>
						<p><strong><?php /* translators: %d: código HTTP */ printf( esc_html__( 'Respuesta (HTTP %d)', 'recurrente-elementor-form' ), (int) $log['response_code'] ); ?></strong></p>
						<pre class="ref-pre"><?php echo esc_html( self::pretty( $log['response'] ) ); ?></pre>
						<?php self::box_close(); ?>
					</div>

					<div id="postbox-container-1" class="postbox-container">
						<?php self::box_open( __( 'Acciones', 'recurrente-elementor-form' ) ); ?>
						<p><?php esc_html_e( 'Estado:', 'recurrente-elementor-form' ); ?> <?php echo self::status_badge( $log['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
						<?php if ( $can_verify ) : ?>
							<p><a class="button button-primary" href="<?php echo esc_url( self::action_url( 'verify', $id ) ); ?>"><?php esc_html_e( 'Verificar pago en Recurrente', 'recurrente-elementor-form' ); ?></a></p>
						<?php endif; ?>
						<?php if ( $can_resend ) : ?>
							<?php $confirm = Logger::STATUS_REVIEW === $log['status'] ? __( 'Este pago tiene inconsistencias. ¿Seguro que quieres ejecutar las acciones retenidas (enviar el correo)?', 'recurrente-elementor-form' ) : __( '¿Volver a ejecutar las acciones retenidas (reenviar el correo)?', 'recurrente-elementor-form' ); ?>
							<p><a class="button" href="<?php echo esc_url( self::action_url( 'resend', $id ) ); ?>" onclick="return confirm('<?php echo esc_js( $confirm ); ?>');"><?php echo Logger::STATUS_REVIEW === $log['status'] ? esc_html__( 'Aprobar y enviar', 'recurrente-elementor-form' ) : esc_html__( 'Reenviar correo/acciones', 'recurrente-elementor-form' ); ?></a></p>
						<?php endif; ?>
						<?php if ( $submission_link ) : ?>
							<p><a href="<?php echo esc_url( $submission_link ); ?>"><?php /* translators: %d: id */ printf( esc_html__( 'Ver envío #%d en Elementor', 'recurrente-elementor-form' ), (int) $log['submission_id'] ); ?></a></p>
						<?php endif; ?>
						<hr />
						<p><a class="submitdelete" href="<?php echo esc_url( self::action_url( 'delete', $id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( '¿Eliminar este registro?', 'recurrente-elementor-form' ) ); ?>');"><?php esc_html_e( 'Eliminar registro', 'recurrente-elementor-form' ); ?></a></p>
						<?php self::box_close(); ?>

						<?php self::box_open( __( 'Información', 'recurrente-elementor-form' ) ); ?>
						<table class="ref-meta">
							<?php
							$side = array(
								__( 'Formulario', 'recurrente-elementor-form' ) => $log['form_name'] . ( $log['form_id'] ? ' (' . $log['form_id'] . ')' : '' ),
								__( 'Página', 'recurrente-elementor-form' )     => $log['page_url'],
								__( 'Cliente', 'recurrente-elementor-form' )    => trim( $log['customer_name'] . ' · ' . $log['customer_email'], ' ·' ),
								__( 'Creado', 'recurrente-elementor-form' )     => $log['created_at'],
								__( 'Pagado', 'recurrente-elementor-form' )     => $log['paid_at'],
								__( 'Enviado', 'recurrente-elementor-form' )    => $log['completed_at'],
								__( 'IP', 'recurrente-elementor-form' )         => $log['ip'],
							);
							foreach ( $side as $label => $value ) :
								if ( '' === (string) $value ) {
									continue;
								}
								?>
								<tr><th><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $value ); ?></td></tr>
							<?php endforeach; ?>
						</table>
						<?php self::box_close(); ?>

						<?php if ( is_array( $results ) && $results ) : ?>
							<?php self::box_open( __( 'Resultado de las acciones', 'recurrente-elementor-form' ) ); ?>
							<table class="ref-meta">
								<?php foreach ( $results as $name => $res ) : ?>
									<tr><th><?php echo esc_html( '_' === $name ? __( 'Sistema', 'recurrente-elementor-form' ) : $name ); ?></th><td class="<?php echo ! empty( $res['ok'] ) ? 'ref-text-ok' : 'ref-text-error'; ?>"><?php echo esc_html( $res['message'] ); ?></td></tr>
								<?php endforeach; ?>
							</table>
							<?php self::box_close(); ?>
						<?php endif; ?>
					</div>
				</div>
				<br class="clear" />
			</div>
		</div>
		<?php
	}

	/**
	 * @param string $json JSON.
	 * @return string
	 */
	protected static function pretty( $json ) {
		$data = json_decode( (string) $json, true );
		return null === $data ? (string) $json : (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * @param string $status Estado.
	 * @return string HTML.
	 */
	public static function status_badge( $status ) {
		return '<mark class="ref-status ref-status--' . esc_attr( $status ) . '"><span>' . esc_html( Logger::status_label( $status ) ) . '</span></mark>';
	}

	/**
	 * @param string $do Acción.
	 * @param int    $id ID.
	 * @return string
	 */
	public static function action_url( $do, $id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'ref_log_action',
					'do'     => $do,
					'id'     => (int) $id,
				),
				admin_url( 'admin-post.php' )
			),
			'ref_log_action_' . $do . '_' . (int) $id
		);
	}

	public static function handle_log_action() {
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		check_admin_referer( 'ref_log_action_' . $do . '_' . $id );

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'recurrente-elementor-form' ) );
		}

		$log  = Logger::get( $id );
		$type = 'success';
		$msg  = '';
		$dest = admin_url( 'admin.php?page=ref-logs&view=' . $id );

		if ( ! $log ) {
			$type = 'error';
			$msg  = __( 'Registro no encontrado.', 'recurrente-elementor-form' );
			$dest = admin_url( 'admin.php?page=ref-logs' );
		} elseif ( 'verify' === $do ) {
			Logger::add_event( $id, 'admin_verify', __( 'Verificación manual desde el panel.', 'recurrente-elementor-form' ) );
			$status = Finalizer::verify( $log, 'admin' );
			$fresh  = Logger::get( $id );
			/* translators: 1: estado checkout, 2: estado registro */
			$msg  = sprintf( __( 'Estado en Recurrente: %1$s. Estado del registro: %2$s.', 'recurrente-elementor-form' ), $status, Logger::status_label( $fresh['status'] ) );
			$type = in_array( $status, array( 'error', 'review' ), true ) ? 'error' : 'success';
		} elseif ( 'resend' === $do ) {
			if ( Logger::STATUS_REVIEW === $log['status'] ) {
				$user = wp_get_current_user();
				Logger::add_event( $id, 'admin_approved', sprintf( /* translators: %s: usuario */ __( 'Aprobado manualmente por %s pese a las inconsistencias.', 'recurrente-elementor-form' ), $user->user_login ) );
				Logger::update( $id, array( 'paid_at' => current_time( 'mysql' ) ) );
			}
			Finalizer::run_actions( $id, 'admin' );
			$fresh = Logger::get( $id );
			$type  = Logger::STATUS_COMPLETED === $fresh['status'] ? 'success' : 'error';
			$msg   = $fresh['message'];
		} elseif ( 'delete' === $do ) {
			Logger::delete( array( $id ) );
			$msg  = __( 'Registro eliminado.', 'recurrente-elementor-form' );
			$dest = admin_url( 'admin.php?page=ref-logs' );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'ref_msg'  => rawurlencode( $msg ),
					'ref_type' => $type,
				),
				$dest
			)
		);
		exit;
	}

	public static function export_csv() {
		check_admin_referer( 'ref_export_csv' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'recurrente-elementor-form' ) );
		}

		$args = array_merge(
			self::current_filters(),
			array(
				'per_page'  => 5000,
				'paged'     => 1,
				'with_data' => true,
			)
		);

		$data = Logger::query( $args );

		// Columnas dinámicas: una por cada campo de formulario presente.
		$columns = array();
		$parsed  = array();
		foreach ( $data['items'] as $row ) {
			$fields              = Logger::form_fields( isset( $row['record_data'] ) ? $row['record_data'] : '' );
			$parsed[ $row['id'] ] = $fields;
			foreach ( $fields as $fid => $field ) {
				if ( 0 === strpos( (string) $fid, 'recurrente_' ) ) {
					continue;
				}
				if ( ! isset( $columns[ $fid ] ) ) {
					$columns[ $fid ] = $field['title'];
				}
			}
		}

		$name = 'recurrente-registros-' . ( '' !== $args['form'] ? sanitize_file_name( $args['form'] ) . '-' : '' ) . gmdate( 'Ymd-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $name );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $out, array_merge( array( 'ID', 'Referencia', 'Fecha', 'Estado', 'Ambiente', 'Formulario', 'Monto', 'Moneda', 'Checkout Recurrente', 'ID de pago Recurrente', 'Pagado', 'Enviado', 'Detalle' ), array_values( $columns ) ) );

		foreach ( $data['items'] as $row ) {
			$line = array(
				$row['id'],
				$row['reference'],
				$row['created_at'],
				Logger::status_label( $row['status'] ),
				Settings::mode_label( $row['mode'] ),
				$row['form_name'],
				number_format( ( (int) $row['amount_in_cents'] ) / 100, 2, '.', '' ),
				$row['currency'],
				$row['checkout_id'],
				$row['intent_id'],
				$row['paid_at'],
				$row['completed_at'],
				$row['message'],
			);
			foreach ( array_keys( $columns ) as $fid ) {
				$line[] = isset( $parsed[ $row['id'] ][ $fid ] ) ? $parsed[ $row['id'] ][ $fid ]['value'] : '';
			}
			fputcsv( $out, array_map( array( __CLASS__, 'csv_safe' ), $line ) );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Evita inyección de fórmulas en Excel.
	 *
	 * @param mixed $value Valor.
	 * @return string
	 */
	public static function csv_safe( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! is_numeric( $value ) ) {
			$value = "'" . $value;
		}
		return $value;
	}
}
