<?php
/**
 * Ajustes globales (credenciales, mensajes, alertas).
 *
 * @package Recurrente_Elementor_Form
 */

namespace REF;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'ref_settings';
	const GROUP  = 'ref_settings_group';

	const MODE_DEV = 'dev';
	const MODE_PRO = 'pro';

	/**
	 * Claves cuyo valor no se muestra ni se borra si el campo llega vacío.
	 *
	 * @var string[]
	 */
	protected static $secret_keys = array( 'dev_secret_key', 'pro_secret_key', 'dev_webhook_secret', 'pro_webhook_secret' );

	/**
	 * @return array
	 */
	public static function defaults() {
		return array(
			'dev_secret_key'      => '',
			'pro_secret_key'      => '',
			'dev_webhook_secret'  => '',
			'pro_webhook_secret'  => '',
			'account_id'          => '',
			'force_dev'           => false,
			'reference_prefix'    => 'WEB-',
			'reference_in_name'   => true,
			'send_form_data'      => true,
			'default_currency'    => 'GTQ',
			'add_payment_fields'  => true,
			'msg_checkout_error'  => __( 'No pudimos iniciar el pago. Tu solicitud no fue enviada; por favor intenta de nuevo en unos minutos.', 'recurrente-elementor-form' ),
			'msg_paid'            => __( '¡Pago recibido! Tu solicitud fue enviada correctamente.', 'recurrente-elementor-form' ),
			'msg_pending'         => __( 'Tu pago está en proceso. Enviaremos tu solicitud en cuanto Recurrente lo confirme.', 'recurrente-elementor-form' ),
			'msg_cancelled'       => __( 'Cancelaste el pago. Tu solicitud no fue enviada.', 'recurrente-elementor-form' ),
			'msg_failed'          => __( 'El pago no se pudo completar. Tu solicitud no fue enviada; puedes intentarlo de nuevo.', 'recurrente-elementor-form' ),
			'alert_enabled'       => false,
			'alert_email'         => get_option( 'admin_email' ),
			'log_retention_days'  => 180,
			'reconcile_hours'     => 72,
			'rate_limit'          => 5,
			'checkout_hosts'      => 'app.recurrente.com',
			'delete_on_uninstall' => false,
		);
	}

	/**
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * @param string $key     Clave.
	 * @param mixed  $default Valor por defecto.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Normaliza el modo (dev|pro) aplicando el «forzar desarrollo» global.
	 *
	 * @param string $mode Modo pedido por el formulario.
	 * @return string
	 */
	public static function effective_mode( $mode ) {
		if ( self::get( 'force_dev' ) ) {
			return self::MODE_DEV;
		}
		return self::MODE_PRO === $mode ? self::MODE_PRO : self::MODE_DEV;
	}

	/**
	 * @param string $mode dev|pro.
	 * @return string
	 */
	public static function secret_key( $mode ) {
		return trim( (string) self::get( self::MODE_PRO === $mode ? 'pro_secret_key' : 'dev_secret_key', '' ) );
	}

	/**
	 * Secretos de firma de webhook configurados, indexados por modo.
	 *
	 * @return array
	 */
	public static function webhook_secrets() {
		$out = array();
		foreach ( array( self::MODE_DEV, self::MODE_PRO ) as $mode ) {
			$secret = trim( (string) self::get( $mode . '_webhook_secret', '' ) );
			if ( '' !== $secret ) {
				$out[ $mode ] = $secret;
			}
		}
		return $out;
	}

	/**
	 * @param string $mode dev|pro.
	 * @return string
	 */
	public static function mode_label( $mode ) {
		return self::MODE_PRO === $mode
			? __( 'Producción', 'recurrente-elementor-form' )
			: __( 'Desarrollo (Sandbox)', 'recurrente-elementor-form' );
	}

	/**
	 * Enmascara un secreto para mostrarlo (sk_live_…a1b2).
	 *
	 * @param string $value Secreto.
	 * @return string
	 */
	public static function mask( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		$prefix = '';
		if ( preg_match( '/^(sk_(?:live|test)_|whsec_)/', $value, $m ) ) {
			$prefix = $m[1];
		}
		return $prefix . '••••••••' . substr( $value, -4 );
	}

	/**
	 * ¿La URL de pago devuelta por la API es de un dominio permitido y usa HTTPS?
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_allowed_checkout_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host    = strtolower( $parts['host'] );
		$allowed = array_filter( array_map( 'trim', explode( ',', strtolower( (string) self::get( 'checkout_hosts', 'app.recurrente.com' ) ) ) ) );
		$allowed[] = 'app.recurrente.com';
		return in_array( $host, $allowed, true );
	}

	/**
	 * URL pública del webhook.
	 *
	 * @return string
	 */
	public static function webhook_url() {
		return rest_url( Webhook::NAMESPACE_V1 . Webhook::ROUTE );
	}

	/**
	 * Sanitiza al guardar. Los secretos vacíos conservan el valor actual.
	 *
	 * @param mixed $input Datos enviados.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$current = self::all();
		$out     = self::defaults();

		foreach ( self::$secret_keys as $key ) {
			$value = isset( $input[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $input[ $key ] ) ) ) : '';
			if ( ! empty( $input[ $key . '_clear' ] ) ) {
				$out[ $key ] = '';
			} elseif ( '' === $value ) {
				$out[ $key ] = $current[ $key ];
			} else {
				$out[ $key ] = $value;
			}
		}

		$out['account_id']       = isset( $input['account_id'] ) ? sanitize_text_field( wp_unslash( $input['account_id'] ) ) : '';
		$prefix                  = isset( $input['reference_prefix'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', wp_unslash( $input['reference_prefix'] ) ) ) : 'WEB-';
		$out['reference_prefix'] = '' !== $prefix ? substr( $prefix, 0, 12 ) : 'WEB-';
		$out['default_currency'] = ( isset( $input['default_currency'] ) && 'USD' === $input['default_currency'] ) ? 'USD' : 'GTQ';

		foreach ( array( 'force_dev', 'add_payment_fields', 'alert_enabled', 'delete_on_uninstall', 'reference_in_name', 'send_form_data' ) as $bool ) {
			$out[ $bool ] = ! empty( $input[ $bool ] );
		}

		foreach ( array( 'msg_checkout_error', 'msg_paid', 'msg_pending', 'msg_cancelled', 'msg_failed' ) as $msg ) {
			$out[ $msg ] = isset( $input[ $msg ] ) ? sanitize_textarea_field( wp_unslash( $input[ $msg ] ) ) : $current[ $msg ];
		}

		$email              = isset( $input['alert_email'] ) ? sanitize_email( wp_unslash( $input['alert_email'] ) ) : '';
		$out['alert_email'] = is_email( $email ) ? $email : '';

		$out['log_retention_days'] = isset( $input['log_retention_days'] ) ? max( 0, absint( $input['log_retention_days'] ) ) : 180;
		$out['rate_limit']         = isset( $input['rate_limit'] ) ? min( 100, absint( $input['rate_limit'] ) ) : 5;
		$hosts                     = isset( $input['checkout_hosts'] ) ? preg_split( '/[\s,]+/', strtolower( sanitize_text_field( wp_unslash( $input['checkout_hosts'] ) ) ) ) : array();
		$hosts                     = array_filter( array_map( function ( $h ) { return preg_replace( '/[^a-z0-9.\-]/', '', $h ); }, $hosts ) );
		$out['checkout_hosts']     = $hosts ? implode( ', ', array_unique( $hosts ) ) : 'app.recurrente.com';
		$out['reconcile_hours']    = isset( $input['reconcile_hours'] ) ? min( 720, max( 1, absint( $input['reconcile_hours'] ) ) ) : 72;

		return $out;
	}
}
