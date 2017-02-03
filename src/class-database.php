<?php

namespace Isotop\Syncs;

class Database {

	/**
	 * Bootstrap database.
	 */
	public function __construct() {
		$this->create_table();
	}

	/**
	 * Delete item from database.
	 *
	 * @param  int    $object_id
	 * @param  string $object_type
	 *
	 * @return bool
	 */
	public function delete( int $object_id, string $object_type ) {
		global $wpdb;

		return (bool) $wpdb->delete( $this->get_table(), [
			'object_id'   => $object_id,
			'object_type' => $object_type,
			'site_id'     => get_current_blog_id()
		], ['%d', '%s', '%d'] );
	}

	/**
	 * Create sync id by saving post id to database.
	 *
	 * @param  int    $object_id
	 * @param  string $object_type
	 * @param  int    $sync_id
	 *
	 * @return bool|int
	 */
	public function create( $object_id, $object_type, $sync_id = 0 ) {
		global $wpdb;

		if ( empty( $sync_id ) ) {
			$sync_id = $this->get_last_sync_id() + 1;
		}

		return $wpdb->insert( $this->get_table(), [
			'sync_id'     => $sync_id,
			'object_id'   => $object_id,
			'object_type' => $object_type,
			'site_id'     => get_current_blog_id(),
			'created_at'  => current_time( 'mysql' )
		], ['%d', '%d', '%s', '%d', '%s'] );
	}

	/**
	 * Get value from database based on key.
	 *
	 * @param  int    $object_id
	 * @param  string $object_type
	 * @param  string $key
	 * @param  int    $site_id
	 *
	 * @return int
	 */
	public function get( int $object_id, string $object_type, string $key = 'sync_id', int $site_id = 0 ) {
		global $wpdb;

		$value = $wpdb->get_results( $wpdb->prepare( // wpcs: unprepared SQL
			"SELECT {$key} FROM `{$this->get_table()}` WHERE object_id = %d AND object_type = '%s' AND site_id = %d", // wpcs: unprepared SQL
			$object_id,
			$object_type,
			$site_id === 0 ? get_current_blog_id() : $site_id
		) );

		if ( empty( $value ) ) {
			return 0;
		}

		return intval( $value[0]->$key );
	}

	/**
	 * Get object id by object type and sync id.
	 *
	 * @param  string $object_type
	 * @param  int    $sync_id
	 *
	 * @return int
	 */
	public function get_object_id( string $object_type, int $sync_id ) {
		global $wpdb;

		$value = $wpdb->get_results( $wpdb->prepare( // wpcs: unprepared SQL
			"SELECT object_id FROM `{$this->get_table()}` WHERE object_type = '%s' AND sync_id = %d AND site_id = %d", // wpcs: unprepared SQL
			$object_type,
			$sync_id,
			get_current_blog_id()
		) );

		if ( empty( $value ) ) {
			return 0;
		}

		return intval( $value[0]->object_id );
	}

	/**
	 * Get last sync id from database.
	 *
	 * @return int
	 */
	public function get_last_sync_id() {
		global $wpdb;

		$value = $wpdb->get_results( "SELECT sync_id FROM `{$this->get_table()}` ORDER BY id DESC LIMIT 1" ); // wpcs: unprepared SQL

		if ( empty( $value ) ) {
			return 0;
		}

		return intval( $value[0]->sync_id );
	}

	/**
	 * Get table name.
	 *
	 * @return mixed
	 */
	protected function get_table() {
		global $wpdb;

		return sprintf( '%ssyncs', str_replace( '_' . get_current_blog_id(), '', $wpdb->prefix ) );
	}

	/**
	 * Create table if missing or not same version.
	 */
	protected function create_table() {
		if ( ! function_exists( 'get_site_option' ) ) {
			return;
		}

		$table_version     = 3;
		$installed_version = intval( get_site_option( '_syncs_table_version', 0 ) );

		if ( $installed_version !== $table_version ) {
			global $wpdb;

			$wpdb->query( "DROP TABLE IF EXISTS `{$this->get_table()}`" ); // wpcs: unprepared SQL

			$sql = sprintf(
				'CREATE TABLE %1$s (
					id int(11) unsigned NOT NULL AUTO_INCREMENT,
					sync_id INT(11) unsigned NOT NULL,
					object_id int(11) unsigned NOT NULL,
					object_type varchar(4) NOT NULL,
					site_id int(11) unsigned NOT NULL,
					created_at DATETIME NOT NULL,
					PRIMARY KEY  (id)
				) %2$s;',
				$this->get_table(),
				$GLOBALS['wpdb']->get_charset_collate()
			);

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );

			update_site_option( '_syncs_table_version', $table_version );
		}
	}
}
