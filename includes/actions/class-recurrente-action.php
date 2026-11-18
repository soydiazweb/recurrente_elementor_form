<?php
/**
 * Acción «Recurrente» para «Acciones después de enviar» de Elementor Forms.
 *
 * Añadir esta acción al formulario es el interruptor que activa el cobro.
 * El checkout se crea antes de que corran las demás acciones (ver Form_Handler);
 * aquí solo se entrega la URL de pago al navegador.
 *
 * @package Recurrente_Elementor_Form
 */

namespace REF\Actions;

use Elementor\Controls_Manager;
use ElementorPro\Modules\Forms\Classes\Action_Base;
use REF\Form_Handler;
use REF\Plugin;
use REF\Settings;

defined( 'ABSPATH' ) || exit;

class Recurrente_Action extends Action_Base {

	public function get_name() {
		return Plugin::ACTION_NAME;
	}

	public function get_label() {
		return esc_html__( 'Recurrente (cobro)', 'recurrente-elementor-form' );
	}

	/**
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record       Registro.
	 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler Handler.
	 */
	public function run( $record, $ajax_handler ) {
		$state = Form_Handler::current_state();

		if ( empty( $state['checkout_url'] ) ) {
			// No debería ocurrir: si falla la creación del cobro, Form_Handler corta el envío antes.
			throw new \Exception( esc_html__( 'No se generó la URL de pago de Recurrente.', 'recurrente-elementor-form' ) );
		}

		$ajax_handler->add_response_data( 'redirect_url', $state['checkout_url'] );
	}

	/**
	 * Acciones que se pueden retrasar hasta confirmar el pago.
	 *
	 * @return array name => label
	 */
	protected function deferrable_actions() {
		$options = array(
			'email'   => esc_html__( 'Correo electrónico', 'recurrente-elementor-form' ),
			'email2'  => esc_html__( 'Correo electrónico 2', 'recurrente-elementor-form' ),
			'webhook' => esc_html__( 'Webhook', 'recurrente-elementor-form' ),
		);

		try {
			if ( class_exists( '\\ElementorPro\\Modules\\Forms\\Module' ) ) {
				$registrar = \ElementorPro\Modules\Forms\Module::instance()->actions_registrar;
				if ( $registrar ) {
					foreach ( $registrar->get() as $name => $action ) {
						if ( in_array( $name, Form_Handler::never_defer(), true ) ) {
							continue;
						}
						$options[ $name ] = $action->get_label();
					}
				}
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Se usan las opciones básicas.
		}

		return $options;
	}

	/**
	 * @param \ElementorPro\Modules\Forms\Widgets\Form $widget Widget.
	 */
	public function register_settings_section( $widget ) {
		$widget->start_controls_section(
			'section_recurrente',
			array(
				'label'     => esc_html__( 'Recurrente', 'recurrente-elementor-form' ),
				'condition' => array(
					'submit_actions' => $this->get_name(),
				),
			)
		);

		$settings_url = admin_url( 'admin.php?page=ref-settings' );

		$widget->add_control(
			'ref_intro',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => sprintf(
					/* translators: %s: URL de ajustes */
					__( 'Al enviar, el cliente es redirigido al checkout de Recurrente. Las acciones marcadas abajo (p. ej. el correo) <strong>solo se ejecutan cuando el pago se confirma</strong>. Credenciales en <a href="%s" target="_blank">Recurrente → Ajustes</a>.', 'recurrente-elementor-form' ),
					esc_url( $settings_url )
				),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
			)
		);

		$widget->add_control(
			'ref_mode',
			array(
				'label'   => esc_html__( 'Modo', 'recurrente-elementor-form' ),
				'type'    => Controls_Manager::SELECT,
				'default' => Settings::MODE_DEV,
				'options' => array(
					Settings::MODE_DEV => esc_html__( 'Desarrollo (Sandbox · llave sk_test_)', 'recurrente-elementor-form' ),
					Settings::MODE_PRO => esc_html__( 'Producción (llave sk_live_)', 'recurrente-elementor-form' ),
				),
			)
		);

		// --- Monto ---------------------------------------------------------
		$widget->add_control(
			'ref_heading_amount',
			array(
				'label'     => esc_html__( 'Cobro', 'recurrente-elementor-form' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$widget->add_control(
			'ref_amount_source',
			array(
				'label'   => esc_html__( 'Origen del monto', 'recurrente-elementor-form' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'fixed',
				'options' => array(
					'fixed'   => esc_html__( 'Monto fijo', 'recurrente-elementor-form' ),
					'field'   => esc_html__( 'Tomado de un campo del formulario', 'recurrente-elementor-form' ),
					'product' => esc_html__( 'Producto/precio existente en Recurrente', 'recurrente-elementor-form' ),
				),
			)
		);

		$widget->add_control(
			'ref_amount',
			array(
				'label'       => esc_html__( 'Monto', 'recurrente-elementor-form' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'step'        => 0.01,
				'placeholder' => '150.00',
				'description' => esc_html__( 'En unidades (no centavos). Mínimo Q5 o $1.', 'recurrente-elementor-form' ),
				'condition'   => array( 'ref_amount_source' => 'fixed' ),
			)
		);

		$widget->add_control(
			'ref_amount_field',
			array(
				'label'       => esc_html__( 'ID del campo con el monto', 'recurrente-elementor-form' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => 'monto',
				'description' => esc_html__( 'Usa el «ID» del campo (pestaña Avanzado del campo). Recomendado: campo select, radio u oculto.', 'recurrente-elementor-form' ),
				'condition'   => array( 'ref_amount_source' => 'field' ),
			)
		);

		$widget->add_control(
			'ref_allowed_amounts',
			array(
				'label'       => esc_html__( 'Montos permitidos (seguridad)', 'recurrente-elementor-form' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => '100, 250, 500',
				'description' => esc_html__( 'Opcional pero recomendado: lista separada por comas, con punto decimal y sin separador de miles (100, 250.50, 1500). Si el monto enviado no coincide, el cobro se rechaza (evita que alguien manipule el valor del campo).', 'recurrente-elementor-form' ),
				'condition'   => array( 'ref_amount_source' => 'field' ),
			)
		);

		$widget->add_control(
			'ref_product_id',
			array(
				'label'       => esc_html__( 'ID de producto o precio', 'recurrente-elementor-form' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => 'prod_xxxxx / price_xxxxx',
				'description' => esc_html__( 'Debe existir en el mismo ambiente (Sandbox o Producción) del modo elegido.', 'recurrente-elementor-form' ),
				'condition'   => array( 'ref_amount_source' => 'product' ),
			)
		);

		$widget->add_control(
			'ref_quantity_field',
			array(
				'label'       => esc_html__( 'ID del campo con la cantidad (opcional)', 'recurrente-elementor-form' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => 'cantidad',
				'description' => esc_html__( 'Entre 1 y 9. Vacío = 1.', 'recurrente-elementor-form' ),
			)
		);

		$widget->add_control(
			'ref_currency',
			array(
				'label'     => esc_html__( 'Moneda', 'recurrente-elementor-form' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'default',
				'options'   => array(
					'default' => esc_html__( 'Predeterminada (Ajustes)', 'recurrente-elementor-form' ),
					'GTQ'     => 'GTQ (Q)',
					'USD'     => 'USD ($)',
				),
				'condition' => array( 'ref_amount_source!' => 'product' ),
			)
		);

		$widget->add_control(
			'ref_item_name',
			array(
				'label'       => esc_html__( 'Concepto del cobro', 'recurrente-elementor-form' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'placeholder' => esc_html__( 'Inscripción [field id="nombre"]', 'recurrente-elementor-form' ),
				'description' => esc_html__( 'Admite shortcodes de campo. Vacío = nombre del formulario.', 'recurrente-elementor-form' ),
				'condition'   => array( 'ref_amount_source!' => 'product' ),
			)
		);

		$widget->add_control(
			'ref_image_url',
			array(
				'label'       => esc_html__( 'URL de imagen del producto (opcional)', 'recurrente-elementor-form' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'condition'   => array( 'ref_amount_source!' => 'product' ),
			)
		);

		$widget->add_control(
			'ref_installments',
			array(
				'label'       => esc_html__( 'Cuotas disponibles', 'recurrente-elementor-form' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => array(
					'3'  => esc_html__( '3 meses', 'recurrente-elementor-form' ),
					'6'  => esc_html__( '6 meses', 'recurrente-elementor-form' ),
					'12' => esc_html__( '12 meses', 'recurrente-elementor-form' ),
					'18' => esc_html__( '18 meses', 'recurrente-elementor-form' ),
				),
				'description' => esc_html__( 'Solo GTQ y cuentas empresariales. Vacío = sin cuotas.', 'recurrente-elementor-form' ),
				'condition'   => array( 'ref_amount_source!' => 'product' ),
			)
		);

		$widget->add_control(
			'ref_payment_methods',
			array(
				'label'       => esc_html__( 'Métodos de pago', 'recurrente-elementor-form' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => array(
					'card'          => esc_html__( 'Tarjeta', 'recurrente-elementor-form' ),
					'bank_transfer' => esc_html__( 'Transferencia bancaria', 'recurrente-elementor-form' ),
					'balance'       => esc_html__( 'Balance Recurrente', 'recurrente-elementor-form' ),
					'stablecoins'   => esc_html__( 'Dólares digitales', 'recurrente-elementor-form' ),
				),
				'description' => esc_html__( 'Vacío = los configurados en tu cuenta de Recurrente.', 'recurrente-elementor-form' ),
				'condition'   => array( 'ref_amount_source!' => 'product' ),
			)
		);

		$requirement_options = array(
			''         => esc_html__( 'Predeterminado', 'recurrente-elementor-form' ),
			'none'     => esc_html__( 'No pedir', 'recurrente-elementor-form' ),
			'optional' => esc_html__( 'Opcional', 'recurrente-elementor-form' ),
			'required' => esc_html__( 'Obligatorio', 'recurrente-elementor-form' ),
		);

		$widget->add_control(
			'ref_billing_info_requirement',
			array(
				'label'     => esc_html__( 'NIT en el checkout', 'recurrente-elementor-form' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => $requirement_options,
				'condition' => array( 'ref_amount_source!' => 'product' ),
			)
		);

		$widget->add_control(
			'ref_phone_requirement',
			array(
				'label'     => esc_html__( 'Teléfono en el checkout', 'recurrente-elementor-form' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => $requirement_options,
				'condition' => array( 'ref_amount_source!' => 'product' ),
			)
		);

		$widget->add_control(
			'ref_address_requirement',
			array(
				'label'     => esc_html__( 'Dirección en el checkout', 'recurrente-elementor-form' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => $requirement_options,
				'condition' => array( 'ref_amount_source!' => 'product' ),
			)
		);

		$widget->add_control(
			'ref_expires_minutes',
			array(
				'label'       => esc_html__( 'El enlace de pago expira en (minutos)', 'recurrente-elementor-form' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'default'     => 1440,
				'description' => esc_html__( '0 = sin expiración.', 'recurrente-elementor-form' ),
			)
		);

		// --- Cliente -------------------------------------------------------
		$widget->add_control(
			'ref_heading_customer',
			array(
				'label'     => esc_html__( 'Datos del cliente (para el registro)', 'recurrente-elementor-form' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$widget->add_control(
			'ref_email_field',
			array(
				'label'       => esc_html__( 'ID del campo de correo', 'recurrente-elementor-form' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => 'email',
				'description' => esc_html__( 'Vacío = el primer campo tipo correo.', 'recurrente-elementor-form' ),
			)
		);

		$widget->add_control(
			'ref_name_field',
			array(
				'label'       => esc_html__( 'ID del campo de nombre', 'recurrente-elementor-form' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => 'name',
			)
		);

		// --- Envío diferido ------------------------------------------------
		$widget->add_control(
			'ref_heading_deferred',
			array(
				'label'     => esc_html__( 'Enviar solo después del pago', 'recurrente-elementor-form' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$widget->add_control(
			'ref_deferred_actions',
			array(
				'label'       => esc_html__( 'Acciones a retrasar hasta que el pago sea exitoso', 'recurrente-elementor-form' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'default'     => array( 'email', 'email2' ),
				'options'     => $this->deferrable_actions(),
				'description' => esc_html__( 'Deben estar también añadidas en «Acciones después de enviar». Las no marcadas (p. ej. «Collect Submissions») se ejecutan al enviar el formulario.', 'recurrente-elementor-form' ),
			)
		);

		// --- Retorno -------------------------------------------------------
		$widget->add_control(
			'ref_heading_return',
			array(
				'label'     => esc_html__( 'Después del checkout', 'recurrente-elementor-form' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$widget->add_control(
			'ref_success_url',
			array(
				'label'       => esc_html__( 'URL de gracias (pago exitoso)', 'recurrente-elementor-form' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'placeholder' => home_url( '/gracias/' ),
				'description' => esc_html__( 'Vacío = la URL de la acción «Redirigir» o, si no hay, la página del formulario con un aviso.', 'recurrente-elementor-form' ),
			)
		);

		$widget->add_control(
			'ref_cancel_url',
			array(
				'label'       => esc_html__( 'URL si el cliente cancela', 'recurrente-elementor-form' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'description' => esc_html__( 'Vacío = la página del formulario con un aviso.', 'recurrente-elementor-form' ),
			)
		);

		$widget->add_control(
			'ref_error_message',
			array(
				'label'       => esc_html__( 'Mensaje si no se puede crear el cobro', 'recurrente-elementor-form' ),
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 2,
				'placeholder' => Settings::get( 'msg_checkout_error' ),
				'description' => esc_html__( 'Se muestra al cliente en el formulario. Vacío = mensaje global.', 'recurrente-elementor-form' ),
			)
		);

		$widget->add_control(
			'ref_tip',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Consejo: en «Mensajes adicionales» cambia el mensaje de éxito por algo como «Redirigiendo al pago…», ya que se muestra un instante antes de abrir el checkout.', 'recurrente-elementor-form' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$widget->end_controls_section();
	}

	/**
	 * @param array $element Elemento.
	 * @return array
	 */
	public function on_export( $element ) {
		return $element;
	}
}
