<?php
/**
 * Retorno del cliente desde el checkout (success_url / cancel_url)
 * y aviso en pantalla con el resultado.
 *
 * @package Recurrente_Elementor_Form
 */

namespace REF;

defined( 'ABSPATH' ) || exit;

class Return_Handler {

	const QV_ACTION = 'ref_return';
	const QV_TOKEN  = 'ref_t';
	const QV_STATUS = 'ref_status';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_return' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_notice' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_notice' ) );
	}

	public static function maybe_handle_return() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET[ self::QV_ACTION ] ) || empty( $_GET[ self::QV_TOKEN ] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET[ self::QV_ACTION ] ) );
		$token  = preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET[ self::QV_TOKEN ] ) );
		// phpcs:enable

		nocache_headers();

		$log = Logger::get_by( 'token', $token );

		if ( ! $log || strlen( $token ) < 20 ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		// Recurrente agrega ?checkout_id=… al volver: debe ser el del registro.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$returned = isset( $_GET['checkout_id'] ) ? sanitize_text_field( wp_unslash( $_GET['checkout_id'] ) ) : '';
		if ( '' !== $returned && '' !== (string) $log['checkout_id'] && ! hash_equals( (string) $log['checkout_id'], $returned ) ) {
			Logger::add_event( (int) $log['id'], 'security', __( 'Retorno con checkout_id distinto al del registro: ignorado.', 'recurrente-elementor-form' ), array( 'returned' => mb_substr( $returned, 0, 60 ) ) );
			wp_safe_redirect( add_query_arg( self::QV_STATUS, 'failed', '' !== $log['page_url'] ? $log['page_url'] : home_url( '/' ) ) );
			exit;
		}

		if ( 'cancel' === $action ) {
			self::handle_cancel( $log );
		}

		self::handle_success( $log );
	}

	/**
	 * @param array $log Registro.
	 */
	protected static function handle_success( array $log ) {
		$id = (int) $log['id'];

		Logger::add_event( $id, 'return_success', __( 'El cliente volvió del checkout (URL de éxito).', 'recurrente-elementor-form' ) );

		$status = 'pending';

		if ( in_array( $log['status'], array( Logger::STATUS_COMPLETED, Logger::STATUS_ACTIONS_ERROR, Logger::STATUS_PROCESSING ), true ) ) {
			$status = 'paid';
		} else {
			$checkout_status = Finalizer::verify( $log, 'return' );

			// El cobro con tarjeta puede tardar unos segundos en reflejarse.
			if ( 'unpaid' === $checkout_status ) {
				sleep( 2 );
				$fresh           = Logger::get( $id );
				$checkout_status = $fresh ? Finalizer::verify( $fresh, 'return' ) : $checkout_status;
			}

			if ( 'paid' === $checkout_status ) {
				$status = 'paid';
			} elseif ( 'expired' === $checkout_status ) {
				$status = 'failed';
			}
		}

		if ( 'paid' === $status ) {
			$url = '' !== $log['success_url'] ? $log['success_url'] : home_url( '/' );
			// Si es la misma página del formulario, se muestra el aviso.
			if ( self::same_page( $url, $log['page_url'] ) ) {
				$url = add_query_arg( self::QV_STATUS, 'paid', $url );
			}
		} else {
			$url = add_query_arg( self::QV_STATUS, $status, '' !== $log['page_url'] ? $log['page_url'] : home_url( '/' ) );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * @param array $log Registro.
	 */
	protected static function handle_cancel( array $log ) {
		$id = (int) $log['id'];

		if ( in_array( $log['status'], array( Logger::STATUS_PENDING, Logger::STATUS_FAILED ), true ) ) {
			Logger::update(
				$id,
				array(
					'status'  => Logger::STATUS_CANCELLED,
					'message' => __( 'El cliente canceló/abandonó el pago. No se envió el formulario.', 'recurrente-elementor-form' ),
				)
			);
		}
		Logger::add_event( $id, 'return_cancel', __( 'El cliente volvió desde el checkout con «cancelar».', 'recurrente-elementor-form' ) );

		$url = '' !== $log['cancel_url'] ? $log['cancel_url'] : ( '' !== $log['page_url'] ? $log['page_url'] : home_url( '/' ) );
		if ( self::same_page( $url, $log['page_url'] ) ) {
			$url = add_query_arg( self::QV_STATUS, 'cancelled', $url );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * @param string $a URL.
	 * @param string $b URL.
	 * @return bool
	 */
	protected static function same_page( $a, $b ) {
		$norm = function ( $url ) {
			$parts = wp_parse_url( (string) $url );
			return untrailingslashit( ( isset( $parts['host'] ) ? $parts['host'] : '' ) . ( isset( $parts['path'] ) ? $parts['path'] : '' ) );
		};
		return '' !== (string) $b && $norm( $a ) === $norm( $b );
	}

	/**
	 * @return string paid|pending|cancelled|failed o ''.
	 */
	protected static function notice_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET[ self::QV_STATUS ] ) ? sanitize_key( wp_unslash( $_GET[ self::QV_STATUS ] ) ) : '';
		return in_array( $status, array( 'paid', 'pending', 'cancelled', 'failed' ), true ) ? $status : '';
	}

	public static function maybe_enqueue_notice() {
		if ( '' === self::notice_status() ) {
			return;
		}
		wp_enqueue_style( 'ref-front', REF_URL . 'assets/css/front.css', array(), REF_VERSION );
	}

	public static function render_notice() {
		$status = self::notice_status();
		if ( '' === $status ) {
			return;
		}

		$map = array(
			'paid'      => 'msg_paid',
			'pending'   => 'msg_pending',
			'cancelled' => 'msg_cancelled',
			'failed'    => 'msg_failed',
		);

		$message = (string) Settings::get( $map[ $status ] );
		if ( '' === trim( $message ) ) {
			return;
		}

		printf(
			'<div class="ref-notice ref-notice--%1$s" role="status" aria-live="polite"><span class="ref-notice__text">%2$s</span><button type="button" class="ref-notice__close" aria-label="%3$s" onclick="this.parentNode.remove()">&times;</button></div>',
			esc_attr( $status ),
			esc_html( $message ),
			esc_attr__( 'Cerrar', 'recurrente-elementor-form' )
		);
		// Limpia el parámetro de la barra de direcciones (sin recargar).
		echo "<script>try{var u=new URL(location.href);u.searchParams.delete('" . esc_js( self::QV_STATUS ) . "');history.replaceState(null,'',u.toString());}catch(e){}</script>";
	}
}
