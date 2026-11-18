<?php
/**
 * Crea el checkout de Recurrente al enviar el formulario y retiene
 * las acciones elegidas (correo, etc.) hasta que el pago se confirme.
 *
 * Flujo (Elementor Pro ≥ 3.5):
 * 1. Elementor valida el formulario.
 * 2. Filtro `elementor_pro/forms/record/actions_before`: se crea el checkout.
 *    - Si falla: se registra en el log y se responde con error al cliente
 *      (no se ejecuta NINGUNA acción → no se envía el correo).
 * 3. Filtro `elementor_pro/forms/submit_actions` (y fallback sobre el record):
 *    se quitan las acciones diferidas y «Redirigir».
 * 4. La acción «Recurrente» entrega `redirect_url` = checkout_url.
 *
 * @package Recurrente_Elementor_Form
 */

namespace REF;

defined( 'ABSPATH' ) || exit;

class Form_Handler {

	/**
	 * Estado del envío en curso (una petición = un envío).
	 *
	 * @var array|null
	 */
	protected static $state = null;

	public static function init() {
		add_filter( 'elementor_pro/forms/record/actions_before', array( __CLASS__, 'before_actions' ), 20, 2 );
		add_filter( 'elementor_pro/forms/submit_actions', array( __CLASS__, 'filter_submit_actions' ), 99, 3 );
	}

	/**
	 * @return array|null
	 */
	public static function current_state() {
		return self::$state;
	}

	/**
	 * Acciones que nunca se retrasan.
	 *
	 * @return string[]
	 */
	public static function never_defer() {
		return array( Plugin::ACTION_NAME, 'redirect', 'save-to-database', 'activity-log', 'cf7db' );
	}

	/**
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record       Registro.
	 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler Handler.
	 * @return \ElementorPro\Modules\Forms\Classes\Form_Record
	 */
	public static function before_actions( $record, $ajax_handler ) {
		self::$state = null;

		$settings = $record->get( 'form_settings' );
		$actions  = isset( $settings['submit_actions'] ) && is_array( $settings['submit_actions'] ) ? $settings['submit_actions'] : array();

		if ( ! in_array( Plugin::ACTION_NAME, $actions, true ) ) {
			return $record;
		}

		$form_name  = isset( $settings['form_name'] ) ? (string) $settings['form_name'] : '';
		$mode       = Settings::effective_mode( isset( $settings['ref_mode'] ) ? $settings['ref_mode'] : Settings::MODE_DEV );
		$context    = self::request_context( $settings );
		$token      = wp_generate_password( 32, false, false );
		$deferred   = self::deferred_actions( $settings, $actions );
		$customer   = self::customer( $record, $settings );
		$return     = self::return_urls( $record, $settings, $context );
		$user_error = ! empty( $settings['ref_error_message'] ) ? (string) $settings['ref_error_message'] : (string) Settings::get( 'msg_checkout_error' );

		// Snapshot del registro ANTES de modificar la lista de acciones.
		$form_data = array(
			'id'         => isset( $settings['id'] ) ? $settings['id'] : '',
			'widgetType' => (string) $record->get( 'form_type' ),
		);
		if ( method_exists( $ajax_handler, 'get_current_form' ) ) {
			$current = $ajax_handler->get_current_form();
			if ( is_array( $current ) ) {
				$form_data['id']         = isset( $current['id'] ) ? $current['id'] : $form_data['id'];
				$form_data['widgetType'] = isset( $current['widgetType'] ) ? $current['widgetType'] : $form_data['widgetType'];
			}
		}

		// Antiabuso: límite de cobros creados por IP cada 10 minutos.
		$limit = (int) Settings::get( 'rate_limit', 5 );
		if ( $limit > 0 && '' !== $context['ip'] ) {
			$key  = 'ref_rl_' . md5( $context['ip'] );
			$hits = (int) get_transient( $key );
			if ( $hits >= $limit ) {
				$ajax_handler->add_error_message( __( 'Has enviado demasiadas solicitudes en poco tiempo. Espera unos minutos e inténtalo de nuevo.', 'recurrente-elementor-form' ) );
				$ajax_handler->send();
				exit;
			}
			set_transient( $key, $hits + 1, 10 * MINUTE_IN_SECONDS );
		}

		$log_id = Logger::insert(
			array(
				'status'           => Logger::STATUS_PENDING,
				'mode'             => $mode,
				'token'            => $token,
				'form_name'        => $form_name,
				'form_id'          => (string) $form_data['id'],
				'post_id'          => $context['post_id'],
				'page_url'         => $context['page_url'],
				'customer_email'   => $customer['email'],
				'customer_name'    => $customer['name'],
				'deferred_actions' => wp_json_encode( $deferred ),
				'success_url'      => $return['success'],
				'cancel_url'       => $return['cancel'],
				'record_data'      => Record_Store::export( $record, $form_data ),
				'ip'               => $context['ip'],
			)
		);

		$reference = Logger::make_reference( $log_id );
		Logger::update( $log_id, array( 'reference' => $reference ) );

		$built = self::build_payload( $record, $settings, $mode, $token, $log_id, $customer, $reference );

		if ( is_wp_error( $built ) ) {
			self::fail( $ajax_handler, $log_id, $built->get_error_message(), $user_error );
		}

		$api = new Api( $mode );

		if ( ! $api->has_credentials() ) {
			self::fail(
				$ajax_handler,
				$log_id,
				sprintf(
					/* translators: %s: modo */
					__( 'Falta la llave secreta de %s en Recurrente → Ajustes.', 'recurrente-elementor-form' ),
					Settings::mode_label( $mode )
				),
				$user_error
			);
		}

		Logger::update(
			$log_id,
			array(
				'item_name'       => $built['item_name'],
				'amount_in_cents' => $built['amount_in_cents'],
				'currency'        => $built['currency'],
				'request'         => wp_json_encode( $built['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);

		$result = $api->create_checkout( $built['payload'], 'ref-' . $token );

		Logger::update(
			$log_id,
			array(
				'response_code' => $result['code'],
				'response'      => '' !== $result['raw'] ? $result['raw'] : $result['error'],
			)
		);

		$checkout_id  = isset( $result['body']['id'] ) ? (string) $result['body']['id'] : '';
		$checkout_url = isset( $result['body']['checkout_url'] ) ? esc_url_raw( (string) $result['body']['checkout_url'] ) : '';

		if ( ! $result['ok'] || '' === $checkout_id || '' === $checkout_url ) {
			$detail = $result['ok']
				? __( 'Recurrente respondió sin «id» o «checkout_url».', 'recurrente-elementor-form' )
				: $result['error'];
			self::fail( $ajax_handler, $log_id, $detail, $user_error );
		}

		// Nunca redirigir al cliente a un dominio que no sea de Recurrente.
		if ( ! Settings::is_allowed_checkout_url( $checkout_url ) ) {
			self::fail(
				$ajax_handler,
				$log_id,
				sprintf(
					/* translators: %s: url */
					__( 'URL de pago rechazada por seguridad (dominio no permitido o sin HTTPS): %s. Si usas un dominio propio de Recurrente, agrégalo en Ajustes → Seguridad.', 'recurrente-elementor-form' ),
					$checkout_url
				),
				$user_error
			);
		}

		// El ambiente del checkout debe coincidir con el modo del formulario.
		if ( isset( $result['body']['live_mode'] ) && (bool) $result['body']['live_mode'] !== ( Settings::MODE_PRO === $mode ) ) {
			self::fail( $ajax_handler, $log_id, __( 'La llave configurada no corresponde al ambiente del formulario (live_mode distinto).', 'recurrente-elementor-form' ), $user_error );
		}

		// Recurrente calcula el total real (p. ej. con product_id).
		$update = array(
			'checkout_id'  => $checkout_id,
			'checkout_url' => $checkout_url,
			'message'      => __( 'Checkout creado. Esperando el pago del cliente.', 'recurrente-elementor-form' ),
		);
		if ( isset( $result['body']['total_in_cents'] ) ) {
			$update['amount_in_cents'] = (int) $result['body']['total_in_cents'];
		}
		if ( ! empty( $result['body']['currency'] ) ) {
			$update['currency'] = (string) $result['body']['currency'];
		}
		Logger::update( $log_id, $update );

		Logger::add_event(
			$log_id,
			'checkout_created',
			sprintf(
				/* translators: 1: checkout id, 2: acciones */
				__( 'Checkout %1$s creado. Acciones retenidas hasta el pago: %2$s', 'recurrente-elementor-form' ),
				$checkout_id,
				$deferred ? implode( ', ', $deferred ) : __( 'ninguna', 'recurrente-elementor-form' )
			)
		);

		self::$state = array(
			'log_id'       => $log_id,
			'checkout_url' => $checkout_url,
			'deferred'     => $deferred,
		);

		// Datos de Recurrente visibles en «Envíos» de Elementor y en su CSV.
		$fresh = Logger::get( $log_id );
		if ( $fresh ) {
			Submission_Sync::add_to_record( $record, $fresh );
		}

		// Compatibilidad con versiones de Elementor Pro que recorren
		// `$record->get('form_settings')['submit_actions']`.
		$settings['submit_actions'] = self::filter_list( $actions );
		if ( method_exists( $record, 'set' ) ) {
			$record->set( 'form_settings', $settings );
		}

		/**
		 * Se creó un checkout para un envío.
		 *
		 * @param int    $log_id       ID del registro.
		 * @param string $checkout_url URL de pago.
		 * @param object $record       Form_Record.
		 */
		do_action( 'ref_checkout_created', $log_id, $checkout_url, $record );

		return $record;
	}

	/**
	 * @param string[] $actions      Acciones.
	 * @param object   $record       Registro.
	 * @param object   $ajax_handler Handler.
	 * @return string[]
	 */
	public static function filter_submit_actions( $actions, $record = null, $ajax_handler = null ) {
		if ( empty( self::$state ) || ! is_array( $actions ) ) {
			return $actions;
		}
		return self::filter_list( $actions );
	}

	/**
	 * Quita de la lista las acciones diferidas y «redirect».
	 *
	 * @param string[] $actions Acciones.
	 * @return string[]
	 */
	protected static function filter_list( array $actions ) {
		$remove = array_merge( self::$state ? self::$state['deferred'] : array(), array( 'redirect' ) );
		$out    = array_values( array_diff( $actions, $remove ) );
		if ( ! in_array( Plugin::ACTION_NAME, $out, true ) ) {
			$out[] = Plugin::ACTION_NAME;
		}
		return $out;
	}

	/**
	 * Registra el error, avisa al admin y corta el envío mostrando un mensaje al cliente.
	 * No se ejecuta ninguna acción (no se envía el correo).
	 *
	 * @param object $ajax_handler Handler.
	 * @param int    $log_id       ID.
	 * @param string $detail       Detalle técnico.
	 * @param string $user_error   Mensaje para el cliente.
	 */
	protected static function fail( $ajax_handler, $log_id, $detail, $user_error ) {
		Logger::update(
			$log_id,
			array(
				'status'  => Logger::STATUS_ERROR,
				'message' => $detail,
			)
		);
		Logger::add_event( $log_id, 'checkout_error', $detail );
		Notifier::alert( $log_id, __( 'No se pudo crear el cobro', 'recurrente-elementor-form' ), $detail );

		self::$state = null;

		$ajax_handler->add_error_message( $user_error );
		// Solo visible para editores del sitio.
		$ajax_handler->add_admin_error_message( 'Recurrente: ' . $detail );
		$ajax_handler->send(); // wp_send_json_error → termina la petición.
		exit;
	}

	/**
	 * Acciones a diferir: las marcadas en el control que además estén activas en el formulario.
	 *
	 * @param array    $settings Ajustes del formulario.
	 * @param string[] $actions  Acciones activas.
	 * @return string[]
	 */
	protected static function deferred_actions( array $settings, array $actions ) {
		$wanted = isset( $settings['ref_deferred_actions'] ) ? (array) $settings['ref_deferred_actions'] : array( 'email', 'email2' );
		$wanted = array_diff( array_map( 'strval', $wanted ), self::never_defer() );
		return array_values( array_intersect( $actions, $wanted ) );
	}

	/**
	 * @param object $record   Registro.
	 * @param array  $settings Ajustes.
	 * @return array {email, name}
	 */
	protected static function customer( $record, array $settings ) {
		$fields = (array) $record->get( 'fields' );
		$email  = '';
		$name   = '';

		$email_id = isset( $settings['ref_email_field'] ) ? trim( (string) $settings['ref_email_field'] ) : '';
		if ( '' !== $email_id && isset( $fields[ $email_id ]['value'] ) ) {
			$email = (string) $fields[ $email_id ]['value'];
		}
		if ( '' === $email ) {
			foreach ( $fields as $field ) {
				if ( isset( $field['type'] ) && 'email' === $field['type'] && ! empty( $field['value'] ) ) {
					$email = (string) $field['value'];
					break;
				}
			}
		}

		$name_id = isset( $settings['ref_name_field'] ) ? trim( (string) $settings['ref_name_field'] ) : '';
		if ( '' !== $name_id && isset( $fields[ $name_id ]['value'] ) ) {
			$name = (string) $fields[ $name_id ]['value'];
		} elseif ( isset( $fields['name']['value'] ) ) {
			$name = (string) $fields['name']['value'];
		}

		return array(
			'email' => sanitize_email( $email ),
			'name'  => sanitize_text_field( $name ),
		);
	}

	/**
	 * Arma el payload de POST /checkouts.
	 *
	 * @param object $record   Registro.
	 * @param array  $settings Ajustes.
	 * @param string $mode     dev|pro.
	 * @param string $token    Token del log.
	 * @param int    $log_id   ID del log.
	 * @param array  $customer  Cliente.
	 * @param string $reference Referencia web (WEB-000123).
	 * @return array|\WP_Error {payload, item_name, amount_in_cents, currency}
	 */
	protected static function build_payload( $record, array $settings, $mode, $token, $log_id, array $customer, $reference = '' ) {
		$source   = isset( $settings['ref_amount_source'] ) ? (string) $settings['ref_amount_source'] : 'fixed';
		$fields   = (array) $record->get( 'fields' );
		$quantity = 1;

		$qty_field = isset( $settings['ref_quantity_field'] ) ? trim( (string) $settings['ref_quantity_field'] ) : '';
		if ( '' !== $qty_field ) {
			$raw      = isset( $fields[ $qty_field ]['value'] ) ? $fields[ $qty_field ]['value'] : '';
			$quantity = absint( $raw );
			if ( $quantity < 1 || $quantity > 9 ) {
				return new \WP_Error(
					'ref_quantity',
					sprintf(
						/* translators: %s: valor */
						__( 'Cantidad inválida («%s»): debe estar entre 1 y 9.', 'recurrente-elementor-form' ),
						is_scalar( $raw ) ? (string) $raw : ''
					)
				);
			}
		}

		$item = array( 'quantity' => $quantity );

		$form_name = isset( $settings['form_name'] ) ? (string) $settings['form_name'] : '';
		$item_name = '';
		$currency  = '';
		$cents     = 0;

		if ( 'product' === $source ) {
			$product = isset( $settings['ref_product_id'] ) ? trim( (string) $settings['ref_product_id'] ) : '';
			if ( '' === $product ) {
				return new \WP_Error( 'ref_product', __( 'El formulario usa «Producto existente» pero no tiene ID de producto o precio.', 'recurrente-elementor-form' ) );
			}
			if ( 0 === strpos( $product, 'price_' ) ) {
				$item['price_id'] = $product;
			} else {
				$item['product_id'] = $product;
			}
			$item_name = $product;
		} else {
			$currency = isset( $settings['ref_currency'] ) && in_array( $settings['ref_currency'], array( 'GTQ', 'USD' ), true )
				? $settings['ref_currency']
				: (string) Settings::get( 'default_currency', 'GTQ' );

			if ( 'field' === $source ) {
				$field_id = isset( $settings['ref_amount_field'] ) ? trim( (string) $settings['ref_amount_field'] ) : '';
				if ( '' === $field_id || ! isset( $fields[ $field_id ] ) ) {
					return new \WP_Error(
						'ref_amount_field',
						sprintf(
							/* translators: %s: id del campo */
							__( 'No existe el campo de monto «%s» en el formulario.', 'recurrente-elementor-form' ),
							$field_id
						)
					);
				}
				$raw   = $fields[ $field_id ]['value'];
				$cents = Api::to_cents( $raw );

				$allowed = isset( $settings['ref_allowed_amounts'] ) ? trim( (string) $settings['ref_allowed_amounts'] ) : '';
				if ( '' !== $allowed ) {
					$allowed_cents = array_filter( array_map( array( Api::class, 'to_cents' ), preg_split( '/[,;|\s]+/', $allowed ) ) );
					if ( ! in_array( $cents, $allowed_cents, true ) ) {
						return new \WP_Error(
							'ref_amount_not_allowed',
							sprintf(
								/* translators: %s: valor */
								__( 'Monto «%s» no permitido para este formulario (posible manipulación del campo).', 'recurrente-elementor-form' ),
								is_scalar( $raw ) ? (string) $raw : ''
							)
						);
					}
				}
			} else {
				$cents = Api::to_cents( isset( $settings['ref_amount'] ) ? $settings['ref_amount'] : 0 );
			}

			$min = isset( Api::MIN_AMOUNT[ $currency ] ) ? Api::MIN_AMOUNT[ $currency ] : 100;
			if ( $cents * $quantity < $min ) {
				return new \WP_Error(
					'ref_amount_min',
					sprintf(
						/* translators: 1: monto, 2: mínimo */
						__( 'Monto inválido (%1$s). El mínimo de Recurrente es %2$s.', 'recurrente-elementor-form' ),
						Api::format_amount( $cents * $quantity, $currency ),
						Api::format_amount( $min, $currency )
					)
				);
			}

			$item_name = isset( $settings['ref_item_name'] ) ? trim( (string) $settings['ref_item_name'] ) : '';
			$item_name = '' !== $item_name ? $record->replace_setting_shortcodes( $item_name ) : $form_name;
			$item_name = trim( wp_strip_all_tags( $item_name ) );
			if ( '' === $item_name ) {
				$item_name = get_bloginfo( 'name' );
			}
			if ( '' !== $reference && Settings::get( 'reference_in_name' ) ) {
				// Visible en el panel de Recurrente y en el recibo del cliente.
				$item_name = mb_substr( $item_name, 0, 180 ) . ' · Ref ' . $reference;
			}
			$item_name = mb_substr( $item_name, 0, 200 );

			$item['name']            = $item_name;
			$item['amount_in_cents'] = $cents;
			$item['currency']        = $currency;
			$item['charge_type']     = 'one_time';

			if ( ! empty( $settings['ref_image_url'] ) ) {
				$image = esc_url_raw( $record->replace_setting_shortcodes( (string) $settings['ref_image_url'] ) );
				if ( $image ) {
					$item['image_url'] = $image;
				}
			}

			foreach ( array( 'billing_info_requirement', 'phone_requirement', 'address_requirement' ) as $req ) {
				$value = isset( $settings[ 'ref_' . $req ] ) ? (string) $settings[ 'ref_' . $req ] : '';
				if ( in_array( $value, array( 'none', 'optional', 'required' ), true ) ) {
					$item[ $req ] = $value;
				}
			}

			if ( ! empty( $settings['ref_payment_methods'] ) && is_array( $settings['ref_payment_methods'] ) ) {
				$item['payment_method_types'] = array_values( array_intersect( $settings['ref_payment_methods'], array( 'card', 'bank_transfer', 'balance', 'stablecoins' ) ) );
			}

			$installments = ! empty( $settings['ref_installments'] ) && is_array( $settings['ref_installments'] )
				? array_values( array_intersect( array_map( 'intval', $settings['ref_installments'] ), array( 3, 6, 12, 18 ) ) )
				: array();
			if ( $installments && 'GTQ' === $currency ) {
				$item['available_installments'] = $installments;
			}

			$cents = $cents * $quantity;
		}

		$payload = array(
			'items'       => array( $item ),
			'success_url' => add_query_arg(
				array(
					Return_Handler::QV_ACTION => 'success',
					Return_Handler::QV_TOKEN  => $token,
				),
				home_url( '/' )
			),
			'cancel_url'  => add_query_arg(
				array(
					Return_Handler::QV_ACTION => 'cancel',
					Return_Handler::QV_TOKEN  => $token,
				),
				home_url( '/' )
			),
			'metadata'    => self::metadata( $record, $log_id, $reference, $form_name, $customer ),
		);

		$minutes = isset( $settings['ref_expires_minutes'] ) ? absint( $settings['ref_expires_minutes'] ) : 0;
		if ( $minutes > 0 ) {
			$payload['expires_at'] = gmdate( 'c', time() + $minutes * MINUTE_IN_SECONDS );
		}

		/**
		 * Permite ajustar el payload del checkout (p. ej. discount_code, customer_id).
		 *
		 * @param array  $payload  Payload.
		 * @param object $record   Form_Record.
		 * @param array  $settings Ajustes del formulario.
		 * @param string $mode     dev|pro.
		 */
		$payload = apply_filters( 'ref_checkout_payload', $payload, $record, $settings, $mode );

		return array(
			'payload'         => $payload,
			'item_name'       => $item_name,
			'amount_in_cents' => (int) $cents,
			'currency'        => '' !== $currency ? $currency : (string) Settings::get( 'default_currency', 'GTQ' ),
		);
	}

	/**
	 * Metadata del checkout: referencia web + datos que llenó el cliente.
	 * Recurrente la guarda en el checkout y la devuelve en GET /checkouts/{id}.
	 *
	 * @param object $record    Registro.
	 * @param int    $log_id    ID.
	 * @param string $reference Referencia.
	 * @param string $form_name Formulario.
	 * @param array  $customer  Cliente.
	 * @return array
	 */
	protected static function metadata( $record, $log_id, $reference, $form_name, array $customer ) {
		$meta = array(
			'referencia_web' => $reference,
			'wp_log_id'      => (string) $log_id,
			'wp_site'        => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'form_name'      => mb_substr( $form_name, 0, 100 ),
			'customer_email' => $customer['email'],
			'customer_name'  => $customer['name'],
		);

		if ( Settings::get( 'send_form_data' ) ) {
			$skip = array( 'honeypot', 'recaptcha', 'recaptcha_v3', 'step', 'html', 'password', 'upload' );
			$n    = 0;
			foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
				if ( $n >= 30 || in_array( isset( $field['type'] ) ? $field['type'] : '', $skip, true ) ) {
					continue;
				}
				$value = isset( $field['value'] ) && is_scalar( $field['value'] ) ? trim( (string) $field['value'] ) : '';
				if ( '' === $value ) {
					continue;
				}
				$key          = 'campo_' . substr( preg_replace( '/[^a-z0-9_]/', '_', strtolower( (string) $id ) ), 0, 40 );
				$meta[ $key ] = mb_substr( $value, 0, 250 );
				++$n;
			}
		}

		return $meta;
	}

	/**
	 * URLs a donde mandar al cliente al volver del checkout.
	 *
	 * @param object $record   Registro.
	 * @param array  $settings Ajustes.
	 * @param array  $context  Contexto.
	 * @return array {success, cancel}
	 */
	protected static function return_urls( $record, array $settings, array $context ) {
		$success = isset( $settings['ref_success_url'] ) ? trim( (string) $settings['ref_success_url'] ) : '';

		if ( '' === $success && ! empty( $settings['submit_actions'] ) && in_array( 'redirect', (array) $settings['submit_actions'], true ) && ! empty( $settings['redirect_to'] ) ) {
			$success = (string) $settings['redirect_to'];
		}

		$cancel = isset( $settings['ref_cancel_url'] ) ? trim( (string) $settings['ref_cancel_url'] ) : '';

		$success = '' !== $success ? esc_url_raw( $record->replace_setting_shortcodes( $success, true ) ) : '';
		$cancel  = '' !== $cancel ? esc_url_raw( $record->replace_setting_shortcodes( $cancel, true ) ) : '';

		return array(
			'success' => $success ? $success : $context['page_url'],
			'cancel'  => $cancel ? $cancel : $context['page_url'],
		);
	}

	/**
	 * @param array $settings Ajustes.
	 * @return array {post_id, page_url, ip}
	 */
	protected static function request_context( array $settings ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$post_id = isset( $_POST['queried_id'] ) ? absint( $_POST['queried_id'] ) : ( isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0 );
		$referer = isset( $_POST['referrer'] ) ? esc_url_raw( wp_unslash( $_POST['referrer'] ) ) : '';
		// phpcs:enable

		if ( '' === $referer ) {
			$referer = (string) wp_get_referer();
		}

		// Solo URLs del propio sitio.
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $referer || wp_parse_url( $referer, PHP_URL_HOST ) !== $home_host ) {
			$referer = $post_id ? (string) get_permalink( $post_id ) : home_url( '/' );
		}

		$referer = remove_query_arg( array( Return_Handler::QV_STATUS, Return_Handler::QV_TOKEN, Return_Handler::QV_ACTION ), $referer );

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return array(
			'post_id'  => $post_id,
			'page_url' => mb_substr( $referer, 0, 500 ),
			'ip'       => $ip,
		);
	}
}
