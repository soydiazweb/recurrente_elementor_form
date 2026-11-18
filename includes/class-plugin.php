<?php
/**
 * Arranque del plugin.
 *
 * @package Recurrente_Elementor_Form
 */

namespace REF;

defined( 'ABSPATH' ) || exit;

class Plugin {

	const ACTION_NAME = 'recurrente';
	const CRON_HOOK   = 'ref_cron_reconcile';

	/**
	 * @var Plugin|null
	 */
	protected static $instance = null;

	/**
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	protected function __construct() {
		load_plugin_textdomain( 'recurrente-elementor-form', false, dirname( REF_BASENAME ) . '/languages' );

		Logger::maybe_upgrade_table();

		// Panel administrativo, webhook, retorno y cron funcionan aunque
		// Elementor Pro no esté activo (para poder ver los registros).
		if ( is_admin() ) {
			Admin\Admin::init();
		}

		Webhook::init();
		Return_Handler::init();
		Cron::init();
		Submission_Sync::init();

		if ( ! self::elementor_pro_ready() ) {
			add_action( 'admin_notices', array( $this, 'notice_missing_elementor' ) );
			return;
		}

		add_action( 'elementor_pro/forms/actions/register', array( $this, 'register_action' ) );
		Form_Handler::init();
	}

	/**
	 * ¿Está disponible Elementor Pro (o Pro Elements) con soporte de acciones?
	 *
	 * @return bool
	 */
	public static function elementor_pro_ready() {
		return defined( 'ELEMENTOR_PRO_VERSION' ) || class_exists( '\\ElementorPro\\Plugin' );
	}

	/**
	 * Registra la acción «Recurrente» en «Acciones después de enviar».
	 *
	 * @param \ElementorPro\Modules\Forms\Registrars\Form_Actions_Registrar $registrar Registrar.
	 */
	public function register_action( $registrar ) {
		$registrar->register( new Actions\Recurrente_Action() );
	}

	public function notice_missing_elementor() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Recurrente Elementor Form necesita Elementor Pro (versión 3.5 o superior) para añadir el cobro a los formularios. El panel de registros sigue disponible.', 'recurrente-elementor-form' );
		echo '</p></div>';
	}

	public static function activate() {
		Logger::create_table();

		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}
