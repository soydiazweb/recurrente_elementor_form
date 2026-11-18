<?php
/**
 * Endpoint de webhooks de Recurrente (firmados con Svix).
 *
 * URL: /wp-json/recurrente-ef/v1/webhook
 * Eventos: intent.succeeded, intent.failed, intent.pending, intent.canceled
 * y los legacy payment_intent.* / bank_transfer_intent.* …
 *
 * El webhook solo dispara la verificación: el pago se confirma siempre
 * consultando GET /checkouts/{id} con la llave del modo del registro.
 *
 * @package Recurrente_Elementor_Form
 */

namespace REF;

defined( 'ABSPATH' ) || exit;

class Webhook {

	const NAMESPACE_V1 = 'recurrente-ef/v1';
	const ROUTE        = '/webhook';
	const TOLERANCE    = 300; // Segundos (anti-replay).

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	public static function register_route() {
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true', // Autenticado por firma Svix + verificación con la API.
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Petición.
	 * @return \WP_REST_Response
	 */
	public static function handle( \WP_REST_Request $request ) {
		$raw = (string) $request->get_body();

		$svix_id        = (string) $request->get_header( 'svix-id' );
		$svix_timestamp = (string) $request->get_header( 'svix-timestamp' );
		$svix_signature = (string) $request->get_header( 'svix-signature' );

		$secrets  = Settings::webhook_secrets();
		$verified = false;

		if ( $secrets ) {
			foreach ( $secrets as $secret ) {
				if ( self::verify_signature( $secret, $svix_id, $svix_timestamp, $svix_signature, $raw ) ) {
					$verified = true;
					break;
				}
			}
			if ( ! $verified ) {
				return new \WP_REST_Response( array( 'error' => 'invalid_signature' ), 401 );
			}
		}

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_json' ), 400 );
		}

		$event_type  = isset( $payload['event_type'] ) ? (string) $payload['event_type'] : '';
		$checkout_id = self::checkout_id( $payload );

		if ( '' === $checkout_id ) {
			return new \WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$log = Logger::get_by( 'checkout_id', $checkout_id );
		if ( ! $log ) {
			// Pago que no salió de un formulario de este sitio.
			return new \WP_REST_Response( array( 'ok' => true ), 200 );
		}

		// Ya pagado y enviado: nada más que hacer (reintentos de Recurrente o eventos tardíos).
		if ( ! in_array( $log['status'], Logger::open_statuses(), true ) ) {
			return new \WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$event_id  = '' !== $svix_id ? $svix_id : ( isset( $payload['id'] ) ? $event_type . ':' . $payload['id'] : '' );
		$duplicate = Logger::has_event_id( $log, $event_id );

		$failure = '';
		if ( ! empty( $payload['details']['failure_reason'] ) ) {
			$failure = (string) $payload['details']['failure_reason'];
		} elseif ( ! empty( $payload['failure_reason'] ) ) {
			$failure = (string) $payload['failure_reason'];
		}

		// Un reintento del mismo mensaje se vuelve a procesar (por si falló la
		// verificación la primera vez), pero no se duplica en el historial.
		if ( ! $duplicate ) {
			Logger::add_event(
				(int) $log['id'],
				'webhook',
				trim(
					sprintf(
						/* translators: 1: evento, 2: firma */
						__( 'Webhook %1$s recibido (%2$s).', 'recurrente-elementor-form' ),
						$event_type,
						$verified ? __( 'firma válida', 'recurrente-elementor-form' ) : __( 'sin secreto de firma configurado', 'recurrente-elementor-form' )
					) . ( '' !== $failure ? ' ' . $failure : '' )
				),
				array(
					'event_id'   => $event_id,
					'event_type' => $event_type,
					'intent_id'  => isset( $payload['id'] ) ? (string) $payload['id'] : '',
				)
			);
		}

		// Datos del cliente que reporta Recurrente (si el formulario no los tenía).
		$customer = isset( $payload['customer'] ) && is_array( $payload['customer'] ) ? $payload['customer'] : array();
		$update   = array();
		if ( '' === $log['customer_email'] && ! empty( $customer['email'] ) ) {
			$update['customer_email'] = sanitize_email( $customer['email'] );
		}
		if ( '' === $log['customer_name'] && ! empty( $customer['full_name'] ) ) {
			$update['customer_name'] = sanitize_text_field( $customer['full_name'] );
		}
		if ( $update ) {
			Logger::update( (int) $log['id'], $update );
			$log = array_merge( $log, $update );
		}

		$suffix = substr( $event_type, strrpos( $event_type, '.' ) !== false ? strrpos( $event_type, '.' ) + 1 : 0 );

		switch ( $suffix ) {
			case 'succeeded':
				// Sin firma configurada, limita las consultas a la API por checkout
				// (evita que alguien use el webhook para saturar la API).
				$lock = 'ref_wh_' . md5( $checkout_id );
				if ( ! $verified && get_transient( $lock ) ) {
					break;
				}
				set_transient( $lock, 1, 15 );
				Finalizer::verify( $log, 'webhook' );
				break;

			case 'failed':
				if ( in_array( $log['status'], array( Logger::STATUS_PENDING, Logger::STATUS_IN_PROGRESS ), true ) ) {
					$msg = __( 'Pago rechazado por Recurrente. No se envió el formulario.', 'recurrente-elementor-form' ) . ( '' !== $failure ? ' (' . $failure . ')' : '' );
					Logger::update(
						(int) $log['id'],
						array(
							'status'  => Logger::STATUS_FAILED,
							'message' => $msg,
						)
					);
					Notifier::alert( (int) $log['id'], __( 'Pago rechazado', 'recurrente-elementor-form' ), $msg );
				}
				break;

			case 'pending':
			case 'requires_capture':
			case 'requires_verification':
				if ( in_array( $log['status'], array( Logger::STATUS_PENDING, Logger::STATUS_FAILED, Logger::STATUS_CANCELLED ), true ) ) {
					Logger::update(
						(int) $log['id'],
						array(
							'status'  => Logger::STATUS_IN_PROGRESS,
							'message' => __( 'Pago iniciado, pendiente de confirmación.', 'recurrente-elementor-form' ),
						)
					);
				}
				break;

			case 'canceled':
			case 'cancelled':
				if ( in_array( $log['status'], array( Logger::STATUS_PENDING, Logger::STATUS_IN_PROGRESS ), true ) ) {
					Logger::update(
						(int) $log['id'],
						array(
							'status'  => Logger::STATUS_CANCELLED,
							'message' => __( 'Intento de pago cancelado. No se envió el formulario.', 'recurrente-elementor-form' ),
						)
					);
				}
				break;
		}

		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Extrae el ID del checkout de los distintos formatos de evento.
	 *
	 * @param array $payload Evento.
	 * @return string
	 */
	protected static function checkout_id( array $payload ) {
		if ( ! empty( $payload['checkout']['id'] ) && is_string( $payload['checkout']['id'] ) ) {
			return $payload['checkout']['id'];
		}
		if ( ! empty( $payload['checkout_id'] ) && is_string( $payload['checkout_id'] ) ) {
			return $payload['checkout_id'];
		}
		if ( ! empty( $payload['data']['checkout']['id'] ) && is_string( $payload['data']['checkout']['id'] ) ) {
			return $payload['data']['checkout']['id'];
		}
		return '';
	}

	/**
	 * Verificación manual de firma Svix (docs de Recurrente → Webhooks → Verificar manualmente).
	 *
	 * @param string $secret    whsec_…
	 * @param string $id        svix-id.
	 * @param string $timestamp svix-timestamp.
	 * @param string $signature svix-signature (uno o varios «v1,xxx» separados por espacio).
	 * @param string $body      Cuerpo crudo.
	 * @return bool
	 */
	public static function verify_signature( $secret, $id, $timestamp, $signature, $body ) {
		if ( '' === $id || '' === $timestamp || '' === $signature || ! ctype_digit( $timestamp ) ) {
			return false;
		}

		if ( abs( time() - (int) $timestamp ) > self::TOLERANCE ) {
			return false;
		}

		$secret = trim( $secret );
		if ( 0 === strpos( $secret, 'whsec_' ) ) {
			$secret = substr( $secret, 6 );
		}
		$key = base64_decode( $secret, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $key || '' === $key ) {
			return false;
		}

		$expected = base64_encode( hash_hmac( 'sha256', $id . '.' . $timestamp . '.' . $body, $key, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		foreach ( preg_split( '/\s+/', trim( $signature ) ) as $part ) {
			$pieces = explode( ',', $part, 2 );
			if ( 2 === count( $pieces ) && 'v1' === $pieces[0] && hash_equals( $expected, $pieces[1] ) ) {
				return true;
			}
		}

		return false;
	}
}
