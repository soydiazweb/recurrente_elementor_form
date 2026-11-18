<?php
/**
 * Listado de registros (WP_List_Table nativo).
 *
 * @package Recurrente_Elementor_Form
 */

namespace REF\Admin;

use REF\Api;
use REF\Logger;
use REF\Settings;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Logs_Table extends \WP_List_Table {

	const PER_PAGE = 20;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'ref_log',
				'plural'   => 'ref_logs',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'reference'  => __( 'Referencia', 'recurrente-elementor-form' ),
			'created_at' => __( 'Fecha', 'recurrente-elementor-form' ),
			'status'     => __( 'Estado', 'recurrente-elementor-form' ),
			'form_name'  => __( 'Formulario', 'recurrente-elementor-form' ),
			'customer'   => __( 'Cliente', 'recurrente-elementor-form' ),
			'amount'     => __( 'Monto', 'recurrente-elementor-form' ),
			'payment'    => __( 'Recurrente', 'recurrente-elementor-form' ),
			'mode'       => __( 'Ambiente', 'recurrente-elementor-form' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'reference'  => array( 'id', true ),
			'created_at' => array( 'created_at', false ),
			'status'     => array( 'status', false ),
			'form_name'  => array( 'form_name', false ),
			'amount'     => array( 'amount_in_cents', false ),
			'mode'       => array( 'mode', false ),
		);
	}

	protected function get_primary_column_name() {
		return 'reference';
	}

	protected function get_bulk_actions() {
		return array( 'delete' => __( 'Eliminar', 'recurrente-elementor-form' ) );
	}

	/**
	 * URL base del listado conservando el filtro de formulario y ambiente.
	 *
	 * @return string
	 */
	protected function base_url() {
		$f = Admin::current_filters();
		return add_query_arg( array_filter( array( 'page' => 'ref-logs', 'form' => $f['form'], 'mode' => $f['mode'] ) ), admin_url( 'admin.php' ) );
	}

	protected function get_views() {
		$counts  = Logger::counts( Admin::current_filters()['form'] );
		$current = Admin::current_filters()['status'];
		$base    = $this->base_url();
		$views   = array();

		$views['all'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( $base ),
			'' === $current ? ' class="current" aria-current="page"' : '',
			esc_html__( 'Todos', 'recurrente-elementor-form' ),
			array_sum( $counts )
		);

		foreach ( Logger::statuses() as $status => $label ) {
			if ( empty( $counts[ $status ] ) ) {
				continue;
			}
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'status', $status, $base ) ),
				$current === $status ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				(int) $counts[ $status ]
			);
		}

		return $views;
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$f     = Admin::current_filters();
		$forms = Logger::forms();
		?>
		<div class="alignleft actions">
			<?php if ( '' !== $f['status'] ) : ?>
				<input type="hidden" name="status" value="<?php echo esc_attr( $f['status'] ); ?>" />
			<?php endif; ?>
			<label class="screen-reader-text" for="ref-filter-form"><?php esc_html_e( 'Filtrar por formulario', 'recurrente-elementor-form' ); ?></label>
			<select name="form" id="ref-filter-form">
				<option value=""><?php esc_html_e( 'Todos los formularios', 'recurrente-elementor-form' ); ?></option>
				<?php foreach ( $forms as $form_id => $label ) : ?>
					<option value="<?php echo esc_attr( $form_id ); ?>" <?php selected( $f['form'], (string) $form_id ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="ref-filter-mode"><?php esc_html_e( 'Filtrar por ambiente', 'recurrente-elementor-form' ); ?></label>
			<select name="mode" id="ref-filter-mode">
				<option value=""><?php esc_html_e( 'Todos los ambientes', 'recurrente-elementor-form' ); ?></option>
				<option value="dev" <?php selected( $f['mode'], 'dev' ); ?>><?php echo esc_html( Settings::mode_label( 'dev' ) ); ?></option>
				<option value="pro" <?php selected( $f['mode'], 'pro' ); ?>><?php echo esc_html( Settings::mode_label( 'pro' ) ); ?></option>
			</select>
			<input type="date" name="date_from" value="<?php echo esc_attr( $f['date_from'] ); ?>" aria-label="<?php esc_attr_e( 'Desde', 'recurrente-elementor-form' ); ?>" />
			<input type="date" name="date_to" value="<?php echo esc_attr( $f['date_to'] ); ?>" aria-label="<?php esc_attr_e( 'Hasta', 'recurrente-elementor-form' ); ?>" />
			<?php submit_button( __( 'Filtrar', 'recurrente-elementor-form' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	public function prepare_items() {
		$this->process_bulk_action();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$args = array_merge(
			Admin::current_filters(),
			array(
				'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id',
				'order'    => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc',
				'per_page' => self::PER_PAGE,
				'paged'    => $this->get_pagenum(),
			)
		);
		// phpcs:enable

		$data = Logger::query( $args );

		$this->items           = $data['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), $this->get_primary_column_name() );

		$this->set_pagination_args(
			array(
				'total_items' => $data['total'],
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $data['total'] / self::PER_PAGE ),
			)
		);
	}

	protected function process_bulk_action() {
		if ( 'delete' !== $this->current_action() ) {
			return;
		}
		check_admin_referer( 'bulk-' . $this->_args['plural'] );
		if ( ! current_user_can( Admin::CAP ) ) {
			return;
		}
		$ids = isset( $_GET['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_GET['ids'] ) ) : array();
		$n   = Logger::delete( $ids );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( /* translators: %d: número */ _n( '%d registro eliminado.', '%d registros eliminados.', $n, 'recurrente-elementor-form' ), $n ) ) . '</p></div>';
	}

	protected function column_cb( $item ) {
		return '<input type="checkbox" name="ids[]" value="' . (int) $item['id'] . '" />';
	}

	protected function column_reference( $item ) {
		$url     = admin_url( 'admin.php?page=ref-logs&view=' . (int) $item['id'] );
		$label   = '' !== $item['reference'] ? $item['reference'] : '#' . $item['id'];
		$actions = array(
			'view' => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Ver detalle', 'recurrente-elementor-form' ) . '</a>',
		);
		if ( '' !== $item['checkout_id'] && in_array( $item['status'], Logger::open_statuses(), true ) ) {
			$actions['verify'] = '<a href="' . esc_url( Admin::action_url( 'verify', $item['id'] ) ) . '">' . esc_html__( 'Verificar pago', 'recurrente-elementor-form' ) . '</a>';
		}
		if ( Logger::STATUS_ACTIONS_ERROR === $item['status'] ) {
			$actions['resend'] = '<a href="' . esc_url( Admin::action_url( 'resend', $item['id'] ) ) . '">' . esc_html__( 'Reenviar', 'recurrente-elementor-form' ) . '</a>';
		}
		return '<strong><a class="row-title" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></strong>' . $this->row_actions( $actions );
	}

	protected function column_created_at( $item ) {
		$ts = strtotime( $item['created_at'] );
		return $ts ? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) ) : '';
	}

	protected function column_status( $item ) {
		$out = Admin::status_badge( $item['status'] );
		if ( in_array( $item['status'], array( Logger::STATUS_ERROR, Logger::STATUS_ACTIONS_ERROR, Logger::STATUS_REVIEW, Logger::STATUS_FAILED ), true ) && '' !== (string) $item['message'] ) {
			$out .= '<br /><span class="description">' . esc_html( wp_html_excerpt( (string) $item['message'], 120, '…' ) ) . '</span>';
		}
		return $out;
	}

	protected function column_form_name( $item ) {
		$f = Admin::current_filters();
		if ( '' !== $f['form'] ) {
			return esc_html( $item['form_name'] );
		}
		$url = add_query_arg( array( 'page' => 'ref-logs', 'form' => $item['form_id'] ), admin_url( 'admin.php' ) );
		return '<a href="' . esc_url( $url ) . '">' . esc_html( '' !== $item['form_name'] ? $item['form_name'] : $item['form_id'] ) . '</a>';
	}

	protected function column_customer( $item ) {
		$name  = esc_html( $item['customer_name'] );
		$email = esc_html( $item['customer_email'] );
		return trim( $name . ( $email ? '<br /><span class="description">' . $email . '</span>' : '' ) );
	}

	protected function column_amount( $item ) {
		return (int) $item['amount_in_cents'] > 0 ? esc_html( Api::format_amount( $item['amount_in_cents'], $item['currency'] ) ) : '—';
	}

	protected function column_payment( $item ) {
		$parts = array();
		if ( '' !== $item['checkout_id'] ) {
			$parts[] = '<code>' . esc_html( $item['checkout_id'] ) . '</code>';
		}
		if ( '' !== $item['intent_id'] ) {
			$parts[] = '<code>' . esc_html( $item['intent_id'] ) . '</code>';
		}
		return $parts ? implode( '<br />', $parts ) : '—';
	}

	protected function column_mode( $item ) {
		return esc_html( 'pro' === $item['mode'] ? __( 'Producción', 'recurrente-elementor-form' ) : __( 'Sandbox', 'recurrente-elementor-form' ) );
	}

	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}

	public function no_items() {
		esc_html_e( 'No hay registros con estos filtros.', 'recurrente-elementor-form' );
	}
}
