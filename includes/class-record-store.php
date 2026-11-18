<?php
/**
 * Guarda y reconstruye el Form_Record de Elementor para ejecutar
 * las acciones diferidas (correo, webhook…) cuando llega el pago.
 *
 * Se guarda como JSON (no serialize) para no reconstruir objetos arbitrarios.
 *
 * @package Recurrente_Elementor_Form
 */

namespace REF;

defined( 'ABSPATH' ) || exit;

class Record_Store {

	const RECORD_CLASS = '\\ElementorPro\\Modules\\Forms\\Classes\\Form_Record';

	/**
	 * Propiedades del registro que necesitan las acciones.
	 *
	 * @var string[]
	 */
	protected static $props = array( 'sent_data', 'fields', 'form_type', 'form_settings', 'files', 'meta' );

	/**
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record   Registro.
	 * @param array                                           $form     Datos del widget (id, settings…).
	 * @return string JSON.
	 */
	public static function export( $record, array $form = array() ) {
		$data = array(
			'v'    => 1,
			'form' => array(
				'id'         => isset( $form['id'] ) ? $form['id'] : '',
				'elType'     => 'widget',
				'widgetType' => isset( $form['widgetType'] ) ? $form['widgetType'] : 'form',
			),
		);

		foreach ( self::$props as $prop ) {
			$data['record'][ $prop ] = $record->get( $prop );
		}

		return (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
	}

	/**
	 * @param string $json JSON guardado.
	 * @return array|null {record: Form_Record, form: array}
	 */
	public static function import( $json ) {
		$class = self::RECORD_CLASS;

		if ( ! class_exists( $class ) ) {
			return null;
		}

		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) || empty( $data['record'] ) || ! is_array( $data['record'] ) ) {
			return null;
		}

		try {
			$ref    = new \ReflectionClass( $class );
			$record = $ref->newInstanceWithoutConstructor();

			foreach ( self::$props as $prop ) {
				$value = array_key_exists( $prop, $data['record'] ) ? $data['record'][ $prop ] : null;
				if ( null === $value && in_array( $prop, array( 'files', 'meta', 'fields', 'sent_data' ), true ) ) {
					$value = array();
				}

				if ( method_exists( $record, 'set' ) ) {
					$record->set( $prop, $value );
				} elseif ( $ref->hasProperty( $prop ) ) {
					$property = $ref->getProperty( $prop );
					$property->setAccessible( true );
					$property->setValue( $record, $value );
				}
			}
		} catch ( \Throwable $e ) {
			return null;
		}

		$form             = isset( $data['form'] ) && is_array( $data['form'] ) ? $data['form'] : array();
		$form['settings'] = $record->get( 'form_settings' );

		return array(
			'record' => $record,
			'form'   => $form,
		);
	}

	/**
	 * Agrega campos virtuales con los datos del pago para usarlos en el correo:
	 * [field id="recurrente_amount"], [field id="recurrente_payment_id"], etc.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record Registro.
	 * @param array                                           $log    Fila del log.
	 */
	public static function add_payment_fields( $record, array $log ) {
		$fields = $record->get( 'fields' );
		$fields = is_array( $fields ) ? $fields : array();

		$extra = array(
			'recurrente_reference'   => array( __( 'Referencia web', 'recurrente-elementor-form' ), isset( $log['reference'] ) ? $log['reference'] : '' ),
			'recurrente_status'    => array( __( 'Estado del pago', 'recurrente-elementor-form' ), __( 'Pagado', 'recurrente-elementor-form' ) ),
			'recurrente_amount'      => array( __( 'Monto pagado', 'recurrente-elementor-form' ), Api::format_amount( $log['amount_in_cents'], $log['currency'] ) ),
			'recurrente_payment_id'  => array( __( 'ID de pago Recurrente', 'recurrente-elementor-form' ), '' !== $log['intent_id'] ? $log['intent_id'] : $log['checkout_id'] ),
			'recurrente_checkout_id' => array( __( 'Checkout Recurrente', 'recurrente-elementor-form' ), $log['checkout_id'] ),
		);

		foreach ( $extra as $id => $pair ) {
			$fields[ $id ] = array(
				'id'        => $id,
				'type'      => 'hidden',
				'title'     => $pair[0],
				'value'     => (string) $pair[1],
				'raw_value' => (string) $pair[1],
				'required'  => false,
			);
		}

		$record->set( 'fields', $fields );
	}
}
