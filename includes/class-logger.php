<?php
/**
 * Registro (log) de cada envío con cobro y su historial de eventos.
 *
 * @package Recurrente_Elementor_Form
 */

namespace REF;

defined( 'ABSPATH' ) || exit;

class Logger {

	const DB_VERSION = '1.2.0';
	const DB_OPTION  = 'ref_db_version';

	const STATUS_ERROR         = 'error';              // No se pudo crear el cobro (no se envió nada).
	const STATUS_PENDING       = 'pending_payment';    // Checkout creado, esperando pago.
	const STATUS_IN_PROGRESS   = 'payment_in_progress'; // Pago iniciado/pendiente (transferencia, etc.).
	const STATUS_FAILED        = 'payment_failed';     // Pago rechazado.
	const STATUS_CANCELLED     = 'cancelled';          // El cliente abandonó/canceló.
	const STATUS_EXPIRED       = 'expired';            // El checkout expiró sin pago.
	const STATUS_PROCESSING    = 'processing';         // Pagado: ejecutando acciones diferidas.
	const STATUS_COMPLETED     = 'completed';          // Pagado y acciones enviadas.
	const STATUS_ACTIONS_ERROR = 'actions_error';      // Pagado pero falló el envío (correo, webhook…).
	const STATUS_REVIEW        = 'needs_review';       // Pago con inconsistencias (monto/ambiente): no se envía.

	/**
	 * Estados que todavía pueden pasar a «pagado».
	 *
	 * @return string[]
	 */
	public static function open_statuses() {
		return array( self::STATUS_PENDING, self::STATUS_IN_PROGRESS, self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_EXPIRED );
	}

	/**
	 * @return array status => etiqueta
	 */
	public static function statuses() {
		return array(
			self::STATUS_PENDING       => __( 'Esperando pago', 'recurrente-elementor-form' ),
			self::STATUS_IN_PROGRESS   => __( 'Pago en proceso', 'recurrente-elementor-form' ),
			self::STATUS_PROCESSING    => __( 'Pagado · enviando', 'recurrente-elementor-form' ),
			self::STATUS_COMPLETED     => __( 'Pagado y enviado', 'recurrente-elementor-form' ),
			self::STATUS_ACTIONS_ERROR => __( 'Pagado · fallo de envío', 'recurrente-elementor-form' ),
			self::STATUS_REVIEW        => __( 'Requiere revisión', 'recurrente-elementor-form' ),
			self::STATUS_FAILED        => __( 'Pago rechazado', 'recurrente-elementor-form' ),
			self::STATUS_CANCELLED     => __( 'Cancelado', 'recurrente-elementor-form' ),
			self::STATUS_EXPIRED       => __( 'Expirado', 'recurrente-elementor-form' ),
			self::STATUS_ERROR         => __( 'Error al crear cobro', 'recurrente-elementor-form' ),
		);
	}

	/**
	 * @param string $status Estado.
	 * @return string
	 */
	public static function status_label( $status ) {
		$all = self::statuses();
		return isset( $all[ $status ] ) ? $all[ $status ] : $status;
	}

	/**
	 * Referencia legible que identifica el envío en la web y en Recurrente (WEB-000123).
	 *
	 * @param int $id ID del registro.
	 * @return string
	 */
	public static function make_reference( $id ) {
		$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) Settings::get( 'reference_prefix', 'WEB-' ) ) );
		return $prefix . str_pad( (string) absint( $id ), 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Campos del formulario guardados en el registro: id => [title, value].
	 *
	 * @param string $record_data JSON guardado.
	 * @return array
	 */
	public static function form_fields( $record_data ) {
		$data   = json_decode( (string) $record_data, true );
		$fields = isset( $data['record']['fields'] ) && is_array( $data['record']['fields'] ) ? $data['record']['fields'] : array();
		$out    = array();
		foreach ( $fields as $id => $field ) {
			if ( in_array( isset( $field['type'] ) ? $field['type'] : '', array( 'honeypot', 'recaptcha', 'recaptcha_v3', 'step', 'html', 'password' ), true ) ) {
				continue;
			}
			$value       = isset( $field['value'] ) ? $field['value'] : '';
			$out[ $id ] = array(
				'title' => ! empty( $field['title'] ) ? (string) $field['title'] : (string) $id,
				'type'  => isset( $field['type'] ) ? (string) $field['type'] : '',
				'value' => is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ),
			);
		}
		return $out;
	}

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ref_logs';
	}

	public static function create_table() {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'pending_payment',
			mode VARCHAR(10) NOT NULL DEFAULT 'dev',
			token VARCHAR(64) NOT NULL DEFAULT '',
			reference VARCHAR(40) NOT NULL DEFAULT '',
			form_name VARCHAR(255) NOT NULL DEFAULT '',
			form_id VARCHAR(100) NOT NULL DEFAULT '',
			post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			submission_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			page_url VARCHAR(500) NOT NULL DEFAULT '',
			customer_email VARCHAR(190) NOT NULL DEFAULT '',
			customer_name VARCHAR(190) NOT NULL DEFAULT '',
			item_name VARCHAR(255) NOT NULL DEFAULT '',
			amount_in_cents BIGINT(20) NOT NULL DEFAULT 0,
			currency VARCHAR(5) NOT NULL DEFAULT 'GTQ',
			checkout_id VARCHAR(100) NOT NULL DEFAULT '',
			checkout_url VARCHAR(500) NOT NULL DEFAULT '',
			intent_id VARCHAR(100) NOT NULL DEFAULT '',
			deferred_actions TEXT NULL,
			success_url VARCHAR(500) NOT NULL DEFAULT '',
			cancel_url VARCHAR(500) NOT NULL DEFAULT '',
			record_data LONGTEXT NULL,
			request LONGTEXT NULL,
			response LONGTEXT NULL,
			response_code SMALLINT(6) NOT NULL DEFAULT 0,
			actions_result LONGTEXT NULL,
			message TEXT NULL,
			events LONGTEXT NULL,
			ip VARCHAR(100) NOT NULL DEFAULT '',
			paid_at DATETIME NULL,
			completed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY mode (mode),
			KEY form_id (form_id),
			KEY token (token),
			KEY reference (reference),
			KEY checkout_id (checkout_id),
			KEY created_at (created_at),
			KEY customer_email (customer_email)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	public static function maybe_upgrade_table() {
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			self::create_table();
		}
	}

	/**
	 * @param array $data Columnas.
	 * @return int
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		$row = array_merge(
			array(
				'created_at' => $now,
				'updated_at' => $now,
				'status'     => self::STATUS_PENDING,
				'events'     => wp_json_encode( array() ),
			),
			$data
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( self::table(), $row );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param int   $id   ID.
	 * @param array $data Columnas.
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = false !== $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );

		if ( $ok && ( isset( $data['status'] ) || isset( $data['intent_id'] ) ) ) {
			/**
			 * Cambió el estado o el pago de un registro.
			 *
			 * @param int   $id   ID.
			 * @param array $data Columnas cambiadas.
			 */
			do_action( 'ref_log_updated', (int) $id, $data );
		}

		return $ok;
	}

	/**
	 * Cambia el estado solo si el actual está entre $from (bloqueo atómico
	 * para que webhook, retorno y cron no ejecuten las acciones dos veces).
	 *
	 * @param int      $id   ID.
	 * @param string[] $from Estados de origen permitidos.
	 * @param string   $to   Estado destino.
	 * @return bool true si este proceso ganó el cambio.
	 */
	public static function claim( $id, array $from, $to ) {
		global $wpdb;

		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $from ), '%s' ) );
		$params       = array_merge( array( $to, current_time( 'mysql' ), (int) $id ), $from );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, updated_at = %s WHERE id = %d AND status IN ({$placeholders})", $params ) );

		if ( 1 === (int) $rows ) {
			do_action( 'ref_log_updated', (int) $id, array( 'status' => $to ) );
			return true;
		}
		return false;
	}

	/**
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
	}

	/**
	 * @param string $column token|checkout_id.
	 * @param string $value  Valor.
	 * @return array|null
	 */
	public static function get_by( $column, $value ) {
		global $wpdb;

		if ( ! in_array( $column, array( 'token', 'checkout_id', 'reference' ), true ) || '' === (string) $value ) {
			return null;
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = %s ORDER BY id DESC LIMIT 1", (string) $value ), ARRAY_A );
	}

	/**
	 * Añade un evento al historial del registro.
	 *
	 * @param int    $id      ID.
	 * @param string $type    Tipo corto (checkout_created, webhook, return…).
	 * @param string $message Texto.
	 * @param array  $data    Datos extra.
	 */
	public static function add_event( $id, $type, $message, array $data = array() ) {
		$row = self::get( $id );
		if ( ! $row ) {
			return;
		}

		$events   = json_decode( (string) $row['events'], true );
		$events   = is_array( $events ) ? $events : array();
		$events[] = array(
			'at'      => current_time( 'mysql' ),
			'type'    => $type,
			'message' => $message,
			'data'    => $data,
		);

		// Evita registros gigantes si un webhook se repite muchas veces.
		if ( count( $events ) > 100 ) {
			$events = array_slice( $events, -100 );
		}

		self::update( $id, array( 'events' => wp_json_encode( $events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
	}

	/**
	 * ¿Ya se procesó este evento (svix-id)?
	 *
	 * @param array  $row      Registro.
	 * @param string $event_id ID del mensaje.
	 * @return bool
	 */
	public static function has_event_id( array $row, $event_id ) {
		if ( '' === (string) $event_id ) {
			return false;
		}
		$events = json_decode( (string) $row['events'], true );
		if ( ! is_array( $events ) ) {
			return false;
		}
		foreach ( $events as $event ) {
			if ( isset( $event['data']['event_id'] ) && $event['data']['event_id'] === $event_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array $ids IDs.
	 * @return int
	 */
	public static function delete( array $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return 0;
		}

		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );
	}

	/**
	 * Borra registros más antiguos que N días.
	 *
	 * @param int $days Días.
	 * @return int
	 */
	public static function purge_older_than( $days ) {
		global $wpdb;

		$days = absint( $days );
		if ( $days < 1 ) {
			return 0;
		}

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	/**
	 * Registros abiertos (sin pagar) creados en las últimas N horas, para conciliar.
	 *
	 * @param int $hours Horas.
	 * @param int $limit Máximo.
	 * @return array
	 */
	public static function open_for_reconcile( $hours, $limit = 25 ) {
		global $wpdb;

		$table        = self::table();
		$cutoff       = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - absint( $hours ) * HOUR_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$statuses     = array( self::STATUS_PENDING, self::STATUS_IN_PROGRESS, self::STATUS_FAILED, self::STATUS_CANCELLED );
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$params       = array_merge( $statuses, array( $cutoff, absint( $limit ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status IN ({$placeholders}) AND checkout_id <> '' AND created_at >= %s ORDER BY updated_at ASC LIMIT %d", $params ), ARRAY_A );
	}

	/**
	 * Consulta para la pantalla de registros.
	 *
	 * @param array $args status, mode, search, date_from, date_to, orderby, order, per_page, paged.
	 * @return array {items:array, total:int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'    => '',
				'mode'      => '',
				'form'      => '',
				'search'    => '',
				'date_from' => '',
				'date_to'   => '',
				'orderby'   => 'id',
				'order'     => 'DESC',
				'per_page'  => 20,
				'paged'     => 1,
				'with_data' => false,
			)
		);

		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( '' !== (string) $args['form'] ) {
			$where[]  = 'form_id = %s';
			$params[] = (string) $args['form'];
		}
		if ( '' !== $args['mode'] ) {
			$where[]  = 'mode = %s';
			$params[] = $args['mode'];
		}
		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(reference LIKE %s OR form_name LIKE %s OR customer_email LIKE %s OR customer_name LIKE %s OR checkout_id LIKE %s OR intent_id LIKE %s OR message LIKE %s)';
			$params   = array_merge( $params, array( $like, $like, $like, $like, $like, $like, $like ) );
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $args['date_from'] . ' 00:00:00';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $args['date_to'] . ' 23:59:59';
		}

		$allowed_orderby = array( 'id', 'created_at', 'status', 'form_name', 'amount_in_cents', 'customer_email', 'mode' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$per_page        = max( 1, (int) $args['per_page'] );
		$offset          = max( 0, ( (int) $args['paged'] - 1 ) * $per_page );
		$where_sql       = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$extra     = ! empty( $args['with_data'] ) ? ', record_data' : '';
		$items_sql = "SELECT id, reference, created_at, updated_at, status, mode, form_name, form_id, post_id, page_url, customer_email, customer_name, item_name, amount_in_cents, currency, checkout_id, intent_id, message, paid_at, completed_at{$extra} FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$total = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : (int) $wpdb->get_var( $count_sql );
		$items = $wpdb->get_results( $wpdb->prepare( $items_sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		return array(
			'items' => (array) $items,
			'total' => $total,
		);
	}

	/**
	 * Formularios que tienen registros: form_id => nombre.
	 *
	 * @return array
	 */
	public static function forms() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT form_id, MAX(form_name) AS form_name, MAX(post_id) AS post_id, COUNT(*) AS n FROM {$table} WHERE form_id <> '' GROUP BY form_id ORDER BY form_name ASC", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$title = $row['post_id'] ? get_the_title( (int) $row['post_id'] ) : '';
			$out[ $row['form_id'] ] = trim( ( '' !== $row['form_name'] ? $row['form_name'] : $row['form_id'] ) . ( $title ? ' — ' . $title : '' ) ) . ' (' . (int) $row['n'] . ')';
		}
		return $out;
	}

	/**
	 * Conteo por estado (para las pestañas del listado).
	 *
	 * @return array status => n
	 */
	public static function counts( $form_id = '' ) {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = '' !== (string) $form_id
			? $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$table} WHERE form_id = %s GROUP BY status", (string) $form_id ), ARRAY_A )
			: $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		// phpcs:enable
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}
}
