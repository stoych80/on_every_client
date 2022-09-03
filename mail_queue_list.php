<?php
if (!class_exists( 'WP_List_Table' ) ) {
	require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}
class mail_queue_list extends WP_List_Table {
	private static $extra_where = '';

	/** Class constructor */
	public function __construct() {
		parent::__construct( array(
			'singular' => __( 'Mail queue item', 'sp' ), //singular name of the listed records
			'plural'   => __( 'Mail queue items', 'sp' ), //plural name of the listed records
			'ajax'     => false,
			'screen' => 'mail_queue_list'
		));
		self::$extra_where .= !empty($_REQUEST['dd_on_every_client_filter']['date_created']['operator']) && in_array($_REQUEST['dd_on_every_client_filter']['date_created']['operator'], ['BETWEEN']) && !empty($_REQUEST['dd_on_every_client_filter']['date_created_1']['value']) && !empty($_REQUEST['dd_on_every_client_filter']['date_created_2']['value']) ? " AND date_created BETWEEN '".esc_sql(date('Y-m-d', strtotime(str_replace('/', '-', $_REQUEST['dd_on_every_client_filter']['date_created_1']['value']))))."' AND '".esc_sql(date('Y-m-d', strtotime(str_replace('/', '-', $_REQUEST['dd_on_every_client_filter']['date_created_2']['value']))))." 23:59:59'" : '';
		if (!empty($_REQUEST['dd_on_every_client_filter']['date_sent']['operator']) && $_REQUEST['dd_on_every_client_filter']['date_sent']['operator']=='NOT SENT YET') {
			self::$extra_where .= " AND date_sent IS NULL";
		} else {
			self::$extra_where .= !empty($_REQUEST['dd_on_every_client_filter']['date_sent']['operator']) && in_array($_REQUEST['dd_on_every_client_filter']['date_sent']['operator'], ['BETWEEN']) && !empty($_REQUEST['dd_on_every_client_filter']['date_sent_1']['value']) && !empty($_REQUEST['dd_on_every_client_filter']['date_sent_2']['value']) ? " AND date_sent BETWEEN '".esc_sql(date('Y-m-d', strtotime(str_replace('/', '-', $_REQUEST['dd_on_every_client_filter']['date_sent_1']['value']))))."' AND '".esc_sql(date('Y-m-d', strtotime(str_replace('/', '-', $_REQUEST['dd_on_every_client_filter']['date_sent_2']['value']))))." 23:59:59'" : '';
	}

		self::$extra_where .= !empty($_REQUEST['dd_on_every_client_filter']['subject']['operator']) && in_array($_REQUEST['dd_on_every_client_filter']['subject']['operator'], ['CONTAINS','NOT CONTAINS']) && !empty($_REQUEST['dd_on_every_client_filter']['subject']['value']) ? ' AND subject'.($_REQUEST['dd_on_every_client_filter']['subject']['operator']=='NOT CONTAINS' ? ' NOT' : '')." LIKE '%".esc_sql($_REQUEST['dd_on_every_client_filter']['subject']['value'])."%'" : '';
		self::$extra_where .= !empty($_REQUEST['dd_on_every_client_filter']['message']['operator']) && in_array($_REQUEST['dd_on_every_client_filter']['message']['operator'], ['CONTAINS','NOT CONTAINS']) && !empty($_REQUEST['dd_on_every_client_filter']['message']['value']) ? ' AND message'.($_REQUEST['dd_on_every_client_filter']['message']['operator']=='NOT CONTAINS' ? ' NOT' : '')." LIKE '%".esc_sql($_REQUEST['dd_on_every_client_filter']['message']['value'])."%'" : '';
		self::$extra_where .= !empty($_REQUEST['dd_on_every_client_filter']['to']['operator']) && in_array($_REQUEST['dd_on_every_client_filter']['to']['operator'], ['CONTAINS','NOT CONTAINS']) && !empty($_REQUEST['dd_on_every_client_filter']['to']['value']) ? ' AND `to`'.($_REQUEST['dd_on_every_client_filter']['to']['operator']=='NOT CONTAINS' ? ' NOT' : '')." LIKE '%".esc_sql($_REQUEST['dd_on_every_client_filter']['to']['value'])."%'" : '';
	}

	/**
	 * Retrieve users data from the database
	 *
	 * @param int $per_page
	 * @param int $page_number
	 *
	 * @return mixed
	 */
	public static function get_items( $per_page = 5, $page_number = 1 ) {
		global $wpdb;
		$sql = "SELECT id, date_created, date_sent, content_type, `to`, subject, message, headers, attachments FROM `dd_on_every_client_mail_queue` WHERE 1".self::$extra_where;
		if (!empty($_REQUEST['orderby']) && ($_REQUEST['orderby'] == 'date_created' || $_REQUEST['orderby'] == 'date_sent' || $_REQUEST['orderby'] == 'content_type' || $_REQUEST['orderby'] == 'to' || $_REQUEST['orderby'] == 'subject')) {
			$order = ! empty( $_REQUEST['order'] ) ? $_REQUEST['order'] : 'ASC';
			if ($_REQUEST['orderby'] == 'date_sent' && $order=='desc') {
				$sql .= ' ORDER BY date_sent IS NULL DESC, date_sent DESC'; //the NULL records (the not sent ones) to appear 1st
			} else {
				$sql .= ' ORDER BY `' . $_REQUEST['orderby'].'`';
				$sql .= ' ' . esc_sql($order);
			}
		} else {
			$sql .= ' ORDER BY date_sent IS NULL DESC, date_sent DESC, date_created DESC'; //the NULL records (the not sent ones) to appear 1st
		}
		$sql .= " LIMIT $per_page";
		$sql .= ' OFFSET ' . ( $page_number - 1 ) * $per_page;
		$result = $wpdb->get_results( $sql, 'ARRAY_A');
		return $result;
	}

	/** Text displayed when no user data is available */
	public function no_items() {
		_e( 'No Mail queue items.', 'sp');
	}

	/**
	 * Render a column when no column specific method exist.
	 *
	 * @param array $item
	 * @param string $column_name
	 *
	 * @return mixed
	 */
	public function column_default( $item, $column_name ) {
		/*switch ( $column_name ) {
			case 'address':
			case 'city':
				return $item[ $column_name ];
			default:
				return print_r( $item, true ); //Show the whole array for troubleshooting purposes
		}*/
	}

	/**
	 * Render the bulk edit checkbox
	 *
	 * @param array $item
	 *
	 * @return string
	 */
	/*function column_cb( $item ) {
		return sprintf('<input type="checkbox" name="bulk-ids[]" value="%d" />', $item['dd_notification_templates_group_id']);
	}*/

	/**
	 * Method for name column
	 *
	 * @param array $item an array of DB data
	 *
	 * @return string
	 */
	function column_date_created( $item ) {
		return date('d/m/Y H:i:s',strtotime($item['date_created'])).'<br>id: '.$item['id'];
	}
	function column_date_sent($item) {
		return $item['date_sent'] ? date('d/m/Y H:i:s',strtotime($item['date_sent'])) : '<b>Not sent yet</b>';
	}
	function column_content_type( $item ) {
		return $item['content_type'];
	}
	function column_to( $item ) {
		$to = maybe_unserialize($item['to']);
		return is_array($to) && $to ? '<ul><li>'.implode('</li><li>', $to).'</li></ul>' : (is_string($to) ? $to : '');
	}
	function column_subject( $item ) {
		return $item['subject'];
	}
	function column_message( $item ) {
		return '<input type="button" value="View Message" class="dd_mail_queue_item_button_message" forid="'.$item['id'].'" forto="'.esc_attr($item['to']).'" forsubject="'.esc_attr($item['subject']).'" />';
	}
	function column_headers( $item ) {
		$headers = maybe_unserialize($item['headers']);
		return is_array($headers) && $headers ? '<ul><li>'.implode('</li><li>', $headers).'</li></ul>' : (is_string($headers) ? $headers : '');
	}
	function column_attachments( $item ) {
		$attachments = maybe_unserialize($item['attachments']);
		return is_array($attachments) && $attachments ? '<ul><li>'.implode('</li><li>', $attachments).'</li></ul>' : (is_string($attachments) ? $attachments : '');
	}

	/**
	 *  Associative array of columns
	 *
	 * @return array
	 */
	function get_columns() {
		$columns = array(
//			'cb'      => '<input type="checkbox" />',
			'date_created' => 'Date created',
			'date_sent' => 'Date sent',
			'content_type' => 'Content type',
			'to'    => 'To',
			'subject'    => 'Subject',
			'message'    => 'Message',
			'headers'    => 'Headers',
			'attachments'    => 'Attachments',
		);
		return $columns;
	}


	/**
	 * Columns to make sortable.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		$sortable_columns = array(
			'date_created' => array('date_created', true),
			'date_sent' => array('date_sent', true),
			'content_type' => array('content_type', true),
			'to' => array('to', true),
			'subject' => array('subject', true)
		);
		return $sortable_columns;
	}

	/**
	 * Returns an associative array containing the bulk action
	 *
	 * @return array
	 */
	/*
	public function get_bulk_actions() {
		$actions = array(
			'bulk-delete' => 'Delete',
		);
		return $actions;
	}*/

	/**
	 * Handles data query and filter, sorting, and pagination.
	 */
	public function prepare_items() {

		$this->_column_headers = $this->get_column_info();

		/** Process bulk action */
//		$this->process_bulk_action();

		$per_page     = $this->get_items_per_page( 'users_per_page', 20 );
		$current_page = $this->get_pagenum();
		$total_items  = self::record_count();

		$this->set_pagination_args(array(
			'total_items' => $total_items, //WE have to calculate the total number of items
			'per_page'    => $per_page //WE have to determine how many items to show on a page
		));

		$this->items = self::get_items( $per_page, $current_page );
	}
	public static function record_count() {
		global $wpdb;
		return $wpdb->get_var("SELECT COUNT(id) FROM dd_on_every_client_mail_queue WHERE 1".self::$extra_where);
	}
}