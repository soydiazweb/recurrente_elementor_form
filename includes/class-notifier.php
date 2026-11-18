<?php
/**
 * Alertas por correo al administrador cuando algo falla.
 *
 * @package Recurrente_Elementor_Form
 */

namespace REF;

defined( 'ABSPATH' ) || exit;

class Notifier {

	/**
	 * @param int    $log_id  ID del registro.
	 * @param string $subject Asunto corto.
	 * @param string $detail  Detalle.
	 */
	public static function alert( $log_id, $subject, $detail ) {
		if ( ! Settings::get( 'alert_enabled' ) ) {
			return;
		}

		$to = (string) Settings::get( 'alert_email' );
		if ( ! is_email( $to ) ) {
			return;
		}

		// Máximo una alerta por registro y asunto cada 10 minutos.
		$key = 'ref_alert_' . md5( $log_id . '|' . $subject );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );

		$log  = Logger::get( $log_id );
		$link = admin_url( 'admin.php?page=ref-logs&view=' . (int) $log_id );

		$lines   = array();
		$lines[] = $subject;
		$lines[] = '';
		$lines[] = __( 'Detalle:', 'recurrente-elementor-form' ) . ' ' . $detail;
		if ( $log ) {
			$lines[] = __( 'Formulario:', 'recurrente-elementor-form' ) . ' ' . $log['form_name'];
			$lines[] = __( 'Modo:', 'recurrente-elementor-form' ) . ' ' . Settings::mode_label( $log['mode'] );
			$lines[] = __( 'Cliente:', 'recurrente-elementor-form' ) . ' ' . trim( $log['customer_name'] . ' ' . $log['customer_email'] );
			if ( (int) $log['amount_in_cents'] > 0 ) {
				$lines[] = __( 'Monto:', 'recurrente-elementor-form' ) . ' ' . Api::format_amount( $log['amount_in_cents'], $log['currency'] );
			}
			if ( '' !== $log['checkout_id'] ) {
				$lines[] = 'Checkout: ' . $log['checkout_id'];
			}
		}
		$lines[] = '';
		$lines[] = __( 'Ver registro:', 'recurrente-elementor-form' ) . ' ' . $link;

		wp_mail(
			$to,
			sprintf( '[%s] Recurrente: %s', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $subject ),
			implode( "\n", $lines )
		);
	}
}
