<?php
/**
 * Confirma el pago con la API y ejecuta las acciones retenidas (correo…).
 *
 * Lo llaman el webhook, el retorno del checkout, el cron de conciliación
 * y los botones del panel. Nunca se confía solo en lo que llega del
 * navegador o del webhook: siempre se consulta GET /checkouts/{id}.
 *
 * @package Recurrente_Elementor_Form
 */

namespace REF;

defined( 'ABSPATH' ) || exit;

class Finalizer {

	/**
	 * Consulta el checkout en Recurrente y actúa según su estado.
	 *
	 * @param array  $log    Fila del log.
	 * @param string $source webhook|return|cron|admin.
	 * @return string Estado del checkout (paid, unpaid, payment_in_progress, expired) o 'error'.
	 */
	public static function verify( array $log, $source ) {
		if ( '' === (string) $log['checkout_id'] ) {
			return 'error';
		}

		$api    = new Api( $log['mode'] );
		$result = $api->get_checkout( $log['checkout_id'] );

		if ( ! $result['ok'] ) {
			Logger::add_event(
				(int) $log['id'],
				'verify_error',
				sprintf(
					/* translators: 1: origen, 2: error */
					__( 'No se pudo verificar el checkout (%1$s): %2$s', 'recurrente-elementor-form' ),
					$source,
					$result['error']
				)
			);
			return 'error';
		}

		$body   = $result['body'];
		$status = isset( $body['status'] ) ? (string) $body['status'] : '';

		// Seguridad: el checkout debe ser el de este registro y del ambiente correcto.
		if ( isset( $body['id'] ) && (string) $body['id'] !== (string) $log['checkout_id'] ) {
			Logger::add_event( (int) $log['id'], 'verify_error', __( 'El checkout devuelto no coincide con el registro.', 'recurrente-elementor-form' ) );
			return 'error';
		}

		// Validación cruzada: la metadata que guardó Recurrente debe apuntar a este registro.
		$meta_log = isset( $body['metadata']['wp_log_id'] ) ? (string) $body['metadata']['wp_log_id'] : '';
		if ( '' !== $meta_log && (int) $meta_log !== (int) $log['id'] ) {
			Logger::add_event(
				(int) $log['id'],
				'verify_error',
				sprintf(
					/* translators: %s: id en metadata */
					__( 'La metadata del checkout apunta a otro registro (wp_log_id=%s). No se envía nada.', 'recurrente-elementor-form' ),
					$meta_log
				)
			);
			return 'error';
		}

		$update = array();

		$intent_id = '';
		if ( ! empty( $body['latest_intent']['id'] ) ) {
			$intent_id = (string) $body['latest_intent']['id'];
		} elseif ( ! empty( $body['payment']['id'] ) ) {
			$intent_id = (string) $body['payment']['id'];
		}
		if ( '' !== $intent_id && $intent_id !== $log['intent_id'] ) {
			$update['intent_id'] = $intent_id;
		}

		if ( $update ) {
			Logger::update( (int) $log['id'], $update );
		}

		switch ( $status ) {
			case 'paid':
				$problems = self::fraud_checks( $log, $body );
				if ( $problems ) {
					if ( Logger::claim( (int) $log['id'], Logger::open_statuses(), Logger::STATUS_REVIEW ) ) {
						$msg = __( 'Pago recibido con inconsistencias: NO se envió el formulario. Revisa en Recurrente antes de entregar. ', 'recurrente-elementor-form' ) . implode( ' · ', $problems );
						Logger::update( (int) $log['id'], array( 'message' => $msg ) );
						Logger::add_event( (int) $log['id'], 'security_review', $msg, array( 'problems' => $problems ) );
						Notifier::alert( (int) $log['id'], __( 'Pago sospechoso: requiere revisión', 'recurrente-elementor-form' ), $msg );
					}
					return 'review';
				}
				if ( in_array( $log['status'], Logger::open_statuses(), true ) ) {
					Logger::add_event(
						(int) $log['id'],
						'recurrente_paid',
						sprintf(
							/* translators: 1: checkout, 2: intent, 3: monto, 4: referencia */
							__( 'Recurrente confirma checkout %1$s pagado · pago %2$s · %3$s · referencia %4$s', 'recurrente-elementor-form' ),
							$log['checkout_id'],
							'' !== $intent_id ? $intent_id : '—',
							Api::format_amount( isset( $body['total_in_cents'] ) ? (int) $body['total_in_cents'] : (int) $log['amount_in_cents'], isset( $body['currency'] ) ? (string) $body['currency'] : $log['currency'] ),
							isset( $body['metadata']['referencia_web'] ) ? (string) $body['metadata']['referencia_web'] : ( isset( $log['reference'] ) ? $log['reference'] : '' )
						)
					);
				}
				self::complete( (int) $log['id'], $source );
				break;

			case 'payment_in_progress':
				if ( in_array( $log['status'], array( Logger::STATUS_PENDING, Logger::STATUS_CANCELLED ), true ) ) {
					Logger::update(
						(int) $log['id'],
						array(
							'status'  => Logger::STATUS_IN_PROGRESS,
							'message' => __( 'Pago en proceso en Recurrente (p. ej. transferencia pendiente).', 'recurrente-elementor-form' ),
						)
					);
					Logger::add_event( (int) $log['id'], 'payment_in_progress', sprintf( '%s: payment_in_progress', $source ) );
				}
				break;

			case 'expired':
				if ( in_array( $log['status'], array( Logger::STATUS_PENDING, Logger::STATUS_IN_PROGRESS, Logger::STATUS_FAILED, Logger::STATUS_CANCELLED ), true ) ) {
					Logger::update(
						(int) $log['id'],
						array(
							'status'  => Logger::STATUS_EXPIRED,
							'message' => __( 'El checkout expiró sin pago. No se envió el formulario.', 'recurrente-elementor-form' ),
						)
					);
					Logger::add_event( (int) $log['id'], 'expired', sprintf( '%s: expired', $source ) );
				}
				break;
		}

		return '' !== $status ? $status : 'error';
	}

	/**
	 * Controles antifraude sobre un checkout pagado. Devuelve los problemas
	 * encontrados (vacío = todo correcto).
	 *
	 * @param array $log  Registro.
	 * @param array $body Respuesta de GET /checkouts/{id}.
	 * @return string[]
	 */
	public static function fraud_checks( array $log, array $body ) {
		$problems = array();

		// 1. La metadata debe existir y apuntar a este registro y referencia.
		$meta = isset( $body['metadata'] ) && is_array( $body['metadata'] ) ? $body['metadata'] : array();
		if ( ! isset( $meta['wp_log_id'] ) || (int) $meta['wp_log_id'] !== (int) $log['id'] ) {
			$problems[] = __( 'la metadata wp_log_id no coincide', 'recurrente-elementor-form' );
		}
		if ( ! empty( $log['reference'] ) && isset( $meta['referencia_web'] ) && (string) $meta['referencia_web'] !== (string) $log['reference'] ) {
			$problems[] = __( 'la referencia web no coincide', 'recurrente-elementor-form' );
		}

		// 2. Ambiente: Sandbox nunca puede contar como pago real ni al revés.
		if ( isset( $body['live_mode'] ) && (bool) $body['live_mode'] !== ( Settings::MODE_PRO === $log['mode'] ) ) {
			$problems[] = __( 'el ambiente (live_mode) no coincide con el modo del formulario', 'recurrente-elementor-form' );
		}

		// 3. Moneda y monto: lo pagado no puede ser menor a lo cobrado al crear el checkout.
		if ( ! empty( $body['currency'] ) && '' !== (string) $log['currency'] && strtoupper( (string) $body['currency'] ) !== strtoupper( (string) $log['currency'] ) ) {
			$problems[] = sprintf( /* translators: 1: moneda pagada 2: esperada */ __( 'moneda %1$s distinta de %2$s', 'recurrente-elementor-form' ), $body['currency'], $log['currency'] );
		}
		if ( isset( $body['total_in_cents'] ) && (int) $log['amount_in_cents'] > 0 && (int) $body['total_in_cents'] < (int) $log['amount_in_cents'] ) {
			$problems[] = sprintf(
				/* translators: 1: pagado 2: esperado */
				__( 'monto pagado %1$s menor al esperado %2$s', 'recurrente-elementor-form' ),
				Api::format_amount( $body['total_in_cents'], $log['currency'] ),
				Api::format_amount( $log['amount_in_cents'], $log['currency'] )
			);
		}

		// 4. Monto fijado por el navegador (botón de Clave sin firma del servidor).
		if ( ! empty( $body['amount_set_in_browser'] ) ) {
			$problems[] = __( 'el monto lo fijó el navegador del comprador', 'recurrente-elementor-form' );
		}

		/**
		 * Permite añadir controles propios.
		 *
		 * @param string[] $problems Problemas.
		 * @param array    $log      Registro.
		 * @param array    $body     Checkout.
		 */
		return (array) apply_filters( 'ref_fraud_checks', $problems, $log, $body );
	}

	/**
	 * Marca como pagado y ejecuta las acciones retenidas (una sola vez).
	 *
	 * @param int    $log_id ID.
	 * @param string $source Origen.
	 * @return bool true si este proceso las ejecutó.
	 */
	public static function complete( $log_id, $source ) {
		if ( ! Logger::claim( $log_id, Logger::open_statuses(), Logger::STATUS_PROCESSING ) ) {
			return false; // Ya lo procesó otro (webhook vs retorno vs cron).
		}

		Logger::update(
			$log_id,
			array(
				'paid_at' => current_time( 'mysql' ),
				'message' => __( 'Pago confirmado. Ejecutando acciones retenidas…', 'recurrente-elementor-form' ),
			)
		);
		Logger::add_event(
			$log_id,
			'paid',
			sprintf(
				/* translators: %s: origen */
				__( 'Pago confirmado con la API (origen: %s).', 'recurrente-elementor-form' ),
				$source
			)
		);

		self::run_actions( $log_id, $source );

		return true;
	}

	/**
	 * Ejecuta las acciones diferidas de Elementor con los datos guardados.
	 *
	 * @param int    $log_id ID.
	 * @param string $source Origen (admin = reenvío manual).
	 * @return array Resultados por acción.
	 */
	public static function run_actions( $log_id, $source = 'admin' ) {
		$log = Logger::get( $log_id );
		if ( ! $log ) {
			return array();
		}

		$deferred = json_decode( (string) $log['deferred_actions'], true );
		$deferred = is_array( $deferred ) ? $deferred : array();
		$results  = array();
		$labels   = array();

		if ( ! $deferred ) {
			self::finish( $log_id, $results, $source );
			return $results;
		}

		if ( ! Plugin::elementor_pro_ready() || ! class_exists( '\\ElementorPro\\Modules\\Forms\\Module' ) ) {
			$results['_'] = array(
				'ok'      => false,
				'message' => __( 'Elementor Pro no está activo: no se pudieron ejecutar las acciones.', 'recurrente-elementor-form' ),
			);
			self::finish( $log_id, $results, $source );
			return $results;
		}

		$restored = Record_Store::import( $log['record_data'] );
		if ( ! $restored ) {
			$results['_'] = array(
				'ok'      => false,
				'message' => __( 'No se pudieron reconstruir los datos del formulario.', 'recurrente-elementor-form' ),
			);
			self::finish( $log_id, $results, $source );
			return $results;
		}

		$record = $restored['record'];

		if ( Settings::get( 'add_payment_fields' ) ) {
			Record_Store::add_payment_fields( $record, $log );
		}

		$handler   = new Deferred_Ajax_Handler( $restored['form'] );
		$registrar = \ElementorPro\Modules\Forms\Module::instance()->actions_registrar;
		$available = $registrar ? (array) $registrar->get() : array();

		// Mismo orden que usa Elementor (orden del registrador).
		foreach ( $available as $name => $action ) {
			if ( ! in_array( $name, $deferred, true ) ) {
				continue;
			}

			$labels[ $name ] = $action->get_label();
			$before = $handler->ref_errors();

			try {
				$action->run( $record, $handler );
				$new_errors = array_values( array_diff( $handler->ref_errors(), $before ) );

				$results[ $name ] = array(
					'ok'      => empty( $new_errors ),
					'message' => $new_errors ? implode( ' | ', $new_errors ) : __( 'Ejecutada', 'recurrente-elementor-form' ),
				);
			} catch ( \Throwable $e ) {
				$results[ $name ] = array(
					'ok'      => false,
					'message' => $e->getMessage(),
				);
			}

			if ( ! $results[ $name ]['ok'] ) {
				// Que el error de esta acción no contamine la siguiente.
				$handler->messages = array(
					'success'     => array(),
					'error'       => array(),
					'admin_error' => array(),
				);
				$handler->errors   = array();
			}

			do_action( 'elementor_pro/forms/actions/after_run', $action, null );
		}

		foreach ( $deferred as $name ) {
			if ( ! isset( $results[ $name ] ) ) {
				$results[ $name ] = array(
					'ok'      => false,
					'message' => __( 'La acción ya no está registrada en Elementor.', 'recurrente-elementor-form' ),
				);
			}
		}

		self::finish( $log_id, $results, $source );
		Submission_Sync::log_actions( $log_id, $results, $labels );

		return $results;
	}

	/**
	 * @param int    $log_id  ID.
	 * @param array  $results Resultados.
	 * @param string $source  Origen.
	 */
	protected static function finish( $log_id, array $results, $source ) {
		$failed = array();
		foreach ( $results as $name => $res ) {
			if ( empty( $res['ok'] ) ) {
				$failed[] = ( '_' === $name ? '' : $name . ': ' ) . $res['message'];
			}
		}

		$ok = empty( $failed );

		Logger::update(
			$log_id,
			array(
				'status'         => $ok ? Logger::STATUS_COMPLETED : Logger::STATUS_ACTIONS_ERROR,
				'completed_at'   => current_time( 'mysql' ),
				'actions_result' => wp_json_encode( $results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'message'        => $ok
					? __( 'Pagado. Acciones retenidas enviadas correctamente.', 'recurrente-elementor-form' )
					: __( 'Pagado, pero falló el envío: ', 'recurrente-elementor-form' ) . implode( ' · ', $failed ),
			)
		);

		Logger::add_event(
			$log_id,
			$ok ? 'actions_ok' : 'actions_error',
			( 'admin' === $source ? __( '[Reenvío manual] ', 'recurrente-elementor-form' ) : '' ) .
			( $ok ? __( 'Acciones ejecutadas.', 'recurrente-elementor-form' ) : implode( ' · ', $failed ) ),
			array( 'results' => $results )
		);

		if ( ! $ok ) {
			Notifier::alert( $log_id, __( 'Pago recibido pero falló el envío del formulario', 'recurrente-elementor-form' ), implode( "\n", $failed ) );
		}

		/**
		 * Pago confirmado y acciones ejecutadas.
		 *
		 * @param int   $log_id  ID del registro.
		 * @param bool  $ok      Si todas las acciones funcionaron.
		 * @param array $results Resultado por acción.
		 */
		do_action( 'ref_payment_completed', $log_id, $ok, $results );
	}
}
