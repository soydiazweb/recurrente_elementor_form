<?php
/**
 * Plugin Name: Recurrente Elementor Form
 * Plugin URI:  https://www.soydiaz.com
 * Description: Cobra con Recurrente (API oficial) desde los formularios de Elementor Pro. Credenciales de Desarrollo (Sandbox) y Producción, modo por formulario, envío de correos/acciones solo cuando el pago se confirma, webhook firmado (Svix), conciliación automática y registro (log) de cada envío y fallo.
 * Version:     1.2.0
 * Author:      Jonathan Diaz
 * Author URI:  https://www.soydiaz.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: recurrente-elementor-form
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Elementor Pro tested up to: 4.0
 *
 * @package Recurrente_Elementor_Form
 */

defined( 'ABSPATH' ) || exit;

define( 'REF_VERSION', '1.2.0' );
define( 'REF_FILE', __FILE__ );
define( 'REF_PATH', plugin_dir_path( __FILE__ ) );
define( 'REF_URL', plugin_dir_url( __FILE__ ) );
define( 'REF_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader:
 * REF\Logger                 -> includes/class-logger.php
 * REF\Actions\Recurrente_Action -> includes/actions/class-recurrente-action.php
 * REF\Admin\Logs_Table       -> includes/admin/class-logs-table.php
 */
spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'REF\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( 'REF\\' ) );
		$parts    = explode( '\\', $relative );
		$class_nm = array_pop( $parts );
		$dirs     = array_map( 'strtolower', $parts );

		$file = REF_PATH . 'includes/';
		if ( $dirs ) {
			$file .= implode( '/', $dirs ) . '/';
		}
		$file .= 'class-' . str_replace( '_', '-', strtolower( $class_nm ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'REF\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'REF\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'REF\\Plugin', 'instance' ), 20 );
