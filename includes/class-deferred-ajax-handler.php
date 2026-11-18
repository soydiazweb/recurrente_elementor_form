<?php
/**
 * Ajax_Handler «silencioso» para ejecutar acciones de Elementor fuera
 * de la petición AJAX original (webhook, retorno del checkout, cron).
 *
 * Solo se carga cuando Elementor Pro está disponible (autoload bajo demanda).
 *
 * @package Recurrente_Elementor_Form
 */

namespace REF;

use ElementorPro\Modules\Forms\Classes\Ajax_Handler;

defined( 'ABSPATH' ) || exit;

class Deferred_Ajax_Handler extends Ajax_Handler {

	/**
	 * @var array
	 */
	protected $ref_form = array();

	/**
	 * No llama al constructor padre para no registrar hooks AJAX.
	 *
	 * @param array $form Datos del formulario (id, settings).
	 */
	public function __construct( array $form = array() ) { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod
		$this->ref_form   = $form;
		$this->is_success = true;
		$this->messages   = array(
			'success'     => array(),
			'error'       => array(),
			'admin_error' => array(),
		);
		$this->data       = array();
		$this->errors     = array();
	}

	/**
	 * @return array
	 */
	public function get_current_form() {
		return $this->ref_form;
	}

	/**
	 * Nunca responde al navegador: aquí no hay petición AJAX.
	 */
	public function send() {}

	/**
	 * Todos los mensajes de error acumulados.
	 *
	 * @return string[]
	 */
	public function ref_errors() {
		$out = array_merge( (array) $this->messages['error'], (array) $this->messages['admin_error'] );
		foreach ( (array) $this->errors as $field => $msg ) {
			$out[] = $field . ': ' . ( is_scalar( $msg ) ? $msg : wp_json_encode( $msg ) );
		}
		return array_values( array_unique( array_map( 'wp_strip_all_tags', $out ) ) );
	}
}
