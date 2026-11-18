<?php
/**
 * Sincroniza los datos de Recurrente con «Envíos» (Submissions) de Elementor,
 * para que aparezcan en el detalle del envío y en su exportación CSV:
 * referencia web, estado del pago, monto, checkout e ID de pago.
 *
 * También registra en el «Actions Log» del envío las acciones retenidas
 * (p. ej. el correo) cuando se ejecutan tras el pago.
 *
 * @package Recurrente_Elementor_Form
 */

namespace REF;

defined( 'ABSPATH' ) || exit;

class Submission_Sync {

	const SNAPSHOT_META = '__elementor_forms_snapshot';

	/**
	 * @return array key => etiqueta (orden en que se muestran).
	 */
	public static function fields() {
		return array(
			'recurrente_reference'   => __( 'Referencia web', 'recurrente-elementor-form' ),
			'recurrente_status'      => __( 'Estado del pago', 'recurrente-elementor-form' ),
			'recurrente_amount'      => __( 'Monto cobrado', 'recurrente-elementor-form' ),
			'recurrente_checkout_id' => __( 'Checkout Recurrente', 'recurrente-elementor-form' ),
			'recurrente_payment_id'  => __( 'ID de pago Recurrente', 'recurrente-elementor-form' ),
			'recurrente_mode'        => __( 'Ambiente Recurrente', 'recurrente-elementor-form' ),
		);
	}

	public static function init() {
		// Tras todas las acciones: el envío ya existe en la base de datos.
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'after_submit' ), 5, 2 );
		add_action( 'ref_log_updated', array( __CLASS__, 'sync' ), 10, 1 );
	}

	/**
	 * Valores de los campos Recurrente para un registro.
	 *
	 * @param array $log Registro.
	 * @return array key => valor
	 */
	public static function values( array $log ) {
		$paid = in_array( $log['status'], array( Logger::STATUS_COMPLETED, Logger::STATUS_ACTIONS_ERROR, Logger::STATUS_PROCESSING ), true );

		return array(
			'recurrente_reference'   => (string) $log['reference'],
			'recurrente_status'      => $paid ? __( 'Pagado', 'recurrente-elementor-form' ) : Logger::status_label( $log['status'] ),
			'recurrente_amount'      => (int) $log['amount_in_cents'] > 0 ? Api::format_amount( $log['amount_in_cents'], $log['currency'] ) : '',
			'recurrente_checkout_id' => (string) $log['checkout_id'],
			'recurrente_payment_id'  => (string) $log['intent_id'],
			'recurrente_mode'        => Settings::mode_label( $log['mode'] ),
		);
	}

	/**
	 * Añade los campos al Form_Record antes de que «Collect Submissions» lo guarde.
	 *
	 * @param object $record Form_Record.
	 * @param array  $log    Registro.
	 */
	public static function add_to_record( $record, array $log ) {
		$fields = (array) $record->get( 'fields' );
		$labels = self::fields();

		foreach ( self::values( $log ) as $key => $value ) {
			$fields[ $key ] = array(
				'id'        => $key,
				'type'      => 'hidden',
				'title'     => $labels[ $key ],
				'value'     => $value,
				'raw_value' => $value,
				'required'  => false,
			);
		}

		$record->set( 'fields', $fields );
	}

	/**
	 * @param object $record       Form_Record.
	 * @param object $ajax_handler Handler.
	 */
	public static function after_submit( $record, $ajax_handler ) {
		$state = Form_Handler::current_state();
		if ( empty( $state['log_id'] ) ) {
			return;
		}

		$log = Logger::get( $state['log_id'] );
		if ( ! $log ) {
			return;
		}

		$submission_id = self::find_submission_id( $log['reference'] );
		if ( $submission_id ) {
			Logger::update( (int) $log['id'], array( 'submission_id' => $submission_id ) );
		}

		$settings = $record->get( 'form_settings' );
		$post_id  = isset( $settings['form_post_id'] ) ? (int) $settings['form_post_id'] : (int) $log['post_id'];
		self::patch_snapshot( $post_id, (string) $log['form_id'] );
	}

	/**
	 * Actualiza los valores del envío cuando cambia el registro.
	 *
	 * @param int $log_id ID.
	 */
	public static function sync( $log_id ) {
		$log = Logger::get( $log_id );
		if ( ! $log || '' === (string) $log['reference'] ) {
			return;
		}

		$submission_id = (int) $log['submission_id'];
		if ( ! $submission_id ) {
			$submission_id = self::find_submission_id( $log['reference'] );
			if ( ! $submission_id ) {
				return; // El formulario no usa «Collect Submissions».
			}
			global $wpdb;
			// Sin pasar por Logger::update para no re-disparar el hook.
			$wpdb->update( Logger::table(), array( 'submission_id' => $submission_id ), array( 'id' => (int) $log_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		foreach ( self::values( $log ) as $key => $value ) {
			self::set_value( $submission_id, $key, $value );
		}
	}

	/**
	 * Registra en el Actions Log del envío el resultado de las acciones diferidas.
	 *
	 * @param int   $log_id  ID.
	 * @param array $results name => {ok, message}.
	 * @param array $labels  name => etiqueta.
	 */
	public static function log_actions( $log_id, array $results, array $labels = array() ) {
		global $wpdb;

		$log = Logger::get( $log_id );
		if ( ! $log || ! (int) $log['submission_id'] || ! self::tables_exist() ) {
			return;
		}

		$sid     = (int) $log['submission_id'];
		$gmt     = current_time( 'mysql', true );
		$local   = get_date_from_gmt( $gmt );
		$ok_n    = 0;
		$total_n = 0;

		foreach ( $results as $name => $res ) {
			if ( '_' === $name ) {
				continue;
			}
			++$total_n;
			if ( ! empty( $res['ok'] ) ) {
				++$ok_n;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$wpdb->prefix . 'e_submissions_actions_log',
				array(
					'submission_id'  => $sid,
					'action_name'    => substr( (string) $name, 0, 60 ),
					'action_label'   => substr( ( isset( $labels[ $name ] ) ? $labels[ $name ] : $name ) . ' · ' . __( 'tras pago', 'recurrente-elementor-form' ), 0, 60 ),
					'status'         => ! empty( $res['ok'] ) ? 'success' : 'failed',
					'log'            => ! empty( $res['ok'] ) ? null : (string) $res['message'],
					'created_at_gmt' => $gmt,
					'updated_at_gmt' => $gmt,
					'created_at'     => $local,
					'updated_at'     => $local,
				)
			);
		}

		if ( $total_n ) {
			$table = $wpdb->prefix . 'e_submissions';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET actions_count = actions_count + %d, actions_succeeded_count = actions_succeeded_count + %d, updated_at_gmt = %s, updated_at = %s WHERE id = %d", $total_n, $ok_n, $gmt, $local, $sid ) );
		}
	}

	/**
	 * @param string $reference Referencia web.
	 * @return int
	 */
	public static function find_submission_id( $reference ) {
		global $wpdb;

		if ( '' === (string) $reference || ! self::tables_exist() ) {
			return 0;
		}

		$table = $wpdb->prefix . 'e_submissions_values';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT submission_id FROM {$table} WHERE `key` = 'recurrente_reference' AND value = %s ORDER BY id DESC LIMIT 1", $reference ) );
	}

	/**
	 * Inserta o actualiza un valor del envío.
	 *
	 * @param int    $submission_id ID del envío.
	 * @param string $key           Clave.
	 * @param string $value         Valor.
	 */
	protected static function set_value( $submission_id, $key, $value ) {
		global $wpdb;

		$table = $wpdb->prefix . 'e_submissions_values';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE submission_id = %d AND `key` = %s LIMIT 1", $submission_id, $key ) );

		if ( $id ) {
			$wpdb->update( $table, array( 'value' => (string) $value ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'submission_id' => (int) $submission_id,
					'key'           => $key,
					'value'         => (string) $value,
				)
			);
		}
	}

	/**
	 * Añade las etiquetas de los campos Recurrente al «snapshot» del formulario,
	 * que Elementor usa como encabezados en el detalle y en el CSV.
	 *
	 * @param int    $post_id    Post del formulario.
	 * @param string $element_id ID del widget.
	 */
	public static function patch_snapshot( $post_id, $element_id ) {
		if ( ! $post_id || '' === $element_id ) {
			return;
		}

		$forms = json_decode( (string) get_post_meta( $post_id, self::SNAPSHOT_META, true ), true );
		if ( ! is_array( $forms ) ) {
			return;
		}

		$changed = false;
		foreach ( $forms as &$form ) {
			if ( ! isset( $form['id'] ) || (string) $form['id'] !== $element_id ) {
				continue;
			}
			$form['fields'] = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array();
			$existing       = wp_list_pluck( $form['fields'], 'id' );
			foreach ( self::fields() as $key => $label ) {
				if ( ! in_array( $key, $existing, true ) ) {
					$form['fields'][] = array(
						'id'    => $key,
						'type'  => 'hidden',
						'label' => $label,
					);
					$changed          = true;
				}
			}
		}
		unset( $form );

		if ( $changed ) {
			update_post_meta( $post_id, self::SNAPSHOT_META, wp_slash( wp_json_encode( $forms ) ) );
			if ( class_exists( '\\ElementorPro\\Modules\\Forms\\Submissions\\Database\\Repositories\\Form_Snapshot_Repository' ) ) {
				\ElementorPro\Modules\Forms\Submissions\Database\Repositories\Form_Snapshot_Repository::instance()->clear_cache();
			}
		}
	}

	/**
	 * @return bool
	 */
	protected static function tables_exist() {
		static $exists = null;
		if ( null === $exists ) {
			global $wpdb;
			$table  = $wpdb->prefix . 'e_submissions_values';
			$exists = ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $exists;
	}
}
