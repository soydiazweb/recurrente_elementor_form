<?php
/**
 * Cliente mínimo de la API oficial de Recurrente.
 *
 * Docs: https://docs.recurrente.com/guias-espanol/comenzar/introduccion
 * - Autenticación: header X-SECRET-KEY (sk_test_ = Sandbox, sk_live_ = Producción).
 * - Base: https://app.recurrente.com/api (misma URL para ambos ambientes).
 *
 * @package Recurrente_Elementor_Form
 */

namespace REF;

defined( 'ABSPATH' ) || exit;

class Api {

	const BASE_URL = 'https://app.recurrente.com/api';
	const TIMEOUT  = 30;

	/**
	 * Mínimos de cobro en centavos (docs: 500 GTQ = Q5, 100 USD = $1).
	 */
	const MIN_AMOUNT = array(
		'GTQ' => 500,
		'USD' => 100,
	);

	/**
	 * @var string
	 */
	protected $mode;

	/**
	 * @var string
	 */
	protected $secret;

	/**
	 * @param string $mode dev|pro.
	 */
	public function __construct( $mode ) {
		$this->mode   = Settings::MODE_PRO === $mode ? Settings::MODE_PRO : Settings::MODE_DEV;
		$this->secret = Settings::secret_key( $this->mode );
	}

	/**
	 * @return bool
	 */
	public function has_credentials() {
		return '' !== $this->secret;
	}

	/**
	 * @return string
	 */
	public function mode() {
		return $this->mode;
	}

	/**
	 * GET /test — confirma la cuenta y el ambiente (sandbox|live).
	 *
	 * @return array
	 */
	public function test() {
		return $this->request( 'GET', '/test' );
	}

	/**
	 * POST /checkouts
	 *
	 * @param array  $payload         Cuerpo.
	 * @param string $idempotency_key Llave de idempotencia (24 h).
	 * @return array
	 */
	public function create_checkout( array $payload, $idempotency_key = '' ) {
		$headers = array();
		if ( '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}
		return $this->request( 'POST', '/checkouts', $payload, $headers );
	}

	/**
	 * GET /checkouts/{id}
	 *
	 * @param string $checkout_id ID (ch_…).
	 * @return array
	 */
	public function get_checkout( $checkout_id ) {
		return $this->request( 'GET', '/checkouts/' . rawurlencode( $checkout_id ) );
	}

	/**
	 * Ejecuta una petición.
	 *
	 * @param string     $method  Método.
	 * @param string     $path    Ruta (/checkouts).
	 * @param array|null $body    Cuerpo JSON.
	 * @param array      $headers Cabeceras extra.
	 * @return array {ok:bool, code:int, body:array, raw:string, error:string}
	 */
	public function request( $method, $path, $body = null, array $headers = array() ) {
		if ( ! $this->has_credentials() ) {
			return array(
				'ok'    => false,
				'code'  => 0,
				'body'  => array(),
				'raw'   => '',
				'error' => sprintf(
					/* translators: %s: modo */
					__( 'No hay llave secreta configurada para el modo %s.', 'recurrente-elementor-form' ),
					Settings::mode_label( $this->mode )
				),
			);
		}

		$headers = array_merge(
			array(
				'X-SECRET-KEY' => $this->secret,
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
				'User-Agent'   => 'RecurrenteElementorForm/' . REF_VERSION . '; WordPress',
			),
			$headers
		);

		$account_id = trim( (string) Settings::get( 'account_id', '' ) );
		if ( '' !== $account_id ) {
			$headers['X-ACCOUNT-ID'] = $account_id;
		}

		$args = array(
			'method'  => $method,
			'timeout' => (int) apply_filters( 'ref_api_timeout', self::TIMEOUT ),
			'headers' => $headers,
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::BASE_URL . $path, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'code'  => 0,
				'body'  => array(),
				'raw'   => '',
				'error' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		$data = is_array( $data ) ? $data : array();
		$ok   = $code >= 200 && $code < 300;

		return array(
			'ok'    => $ok,
			'code'  => $code,
			'body'  => $data,
			'raw'   => $raw,
			'error' => $ok ? '' : self::error_message( $code, $data, $raw ),
		);
	}

	/**
	 * Mensaje legible a partir de la respuesta de error.
	 *
	 * @param int    $code Código HTTP.
	 * @param array  $data JSON.
	 * @param string $raw  Cuerpo crudo.
	 * @return string
	 */
	public static function error_message( $code, array $data, $raw = '' ) {
		$parts = array();

		if ( ! empty( $data['message'] ) && is_string( $data['message'] ) ) {
			$parts[] = $data['message'];
		}
		if ( ! empty( $data['error'] ) && is_string( $data['error'] ) ) {
			$parts[] = $data['error'];
		}
		if ( ! empty( $data['code'] ) && is_string( $data['code'] ) ) {
			$parts[] = '(' . $data['code'] . ')';
		}
		if ( ! empty( $data['errors'] ) ) {
			$parts[] = self::flatten_errors( $data['errors'] );
		}

		$msg = trim( implode( ' ', array_filter( $parts ) ) );

		if ( '' === $msg ) {
			$msg = '' !== $raw ? wp_html_excerpt( wp_strip_all_tags( $raw ), 200, '…' ) : __( 'Respuesta vacía.', 'recurrente-elementor-form' );
		}

		return sprintf( 'HTTP %d: %s', (int) $code, $msg );
	}

	/**
	 * @param mixed $errors Estructura de errores.
	 * @return string
	 */
	protected static function flatten_errors( $errors ) {
		if ( is_string( $errors ) ) {
			return $errors;
		}
		if ( ! is_array( $errors ) ) {
			return '';
		}
		$out = array();
		foreach ( $errors as $key => $value ) {
			$text  = is_array( $value ) ? implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) : (string) $value;
			$out[] = is_string( $key ) ? $key . ': ' . $text : $text;
		}
		return implode( '; ', $out );
	}

	/**
	 * Convierte un monto decimal («Q 1,250.50», «150») a centavos.
	 *
	 * @param mixed $value Valor.
	 * @return int 0 si no es válido.
	 */
	public static function to_cents( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return max( 0, (int) round( $value * 100 ) );
		}

		$value = trim( wp_strip_all_tags( (string) $value ) );
		$value = preg_replace( '/[^0-9.,\-]/', '', $value );

		if ( '' === $value || false !== strpos( $value, '-' ) ) {
			return 0;
		}

		$last_dot   = strrpos( $value, '.' );
		$last_comma = strrpos( $value, ',' );

		if ( false !== $last_dot && false !== $last_comma ) {
			// El último separador es el decimal.
			if ( $last_comma > $last_dot ) {
				$value = str_replace( '.', '', $value );
				$value = str_replace( ',', '.', $value );
			} else {
				$value = str_replace( ',', '', $value );
			}
		} elseif ( false !== $last_comma ) {
			// «1,250» (miles) vs «150,50» (decimal).
			$value = ( strlen( $value ) - $last_comma - 1 ) === 3 ? str_replace( ',', '', $value ) : str_replace( ',', '.', $value );
		}

		if ( ! is_numeric( $value ) ) {
			return 0;
		}

		return (int) round( (float) $value * 100 );
	}

	/**
	 * @param int    $cents    Centavos.
	 * @param string $currency GTQ|USD.
	 * @return string
	 */
	public static function format_amount( $cents, $currency ) {
		$symbol = 'USD' === $currency ? '$' : 'Q';
		return $symbol . number_format_i18n( ( (int) $cents ) / 100, 2 );
	}
}
