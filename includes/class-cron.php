<?php
/**
 * Conciliación automática (por si un webhook no llega) y limpieza de registros.
 *
 * @package Recurrente_Elementor_Form
 */

namespace REF;

defined( 'ABSPATH' ) || exit;

class Cron {

	public static function init() {
		add_action( Plugin::CRON_HOOK, array( __CLASS__, 'run' ) );

		// Reprograma si el evento se perdió (p. ej. migración de base de datos).
		add_action(
			'init',
			function () {
				if ( ! wp_next_scheduled( Plugin::CRON_HOOK ) ) {
					wp_schedule_event( time() + 300, 'hourly', Plugin::CRON_HOOK );
				}
			}
		);
	}

	public static function run() {
		$hours = (int) Settings::get( 'reconcile_hours', 72 );

		foreach ( Logger::open_for_reconcile( $hours, 25 ) as $log ) {
			// Evita consultar registros recién creados (el cliente sigue en el checkout).
			if ( strtotime( $log['created_at'] ) > current_time( 'timestamp' ) - 10 * MINUTE_IN_SECONDS ) { // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
				continue;
			}
			Finalizer::verify( $log, 'cron' );
			Logger::update( (int) $log['id'], array() ); // Toca updated_at para rotar la cola.
		}

		$days = (int) Settings::get( 'log_retention_days', 180 );
		if ( $days > 0 ) {
			Logger::purge_older_than( $days );
		}
	}
}
