<?php

namespace Isotop\Syncs;

class Syncs {

	/**
	 * Current blog id that is the master.
	 *
	 * @var int
	 */
	protected $current_blog_id;

	/**
	 * The database instance.
	 *
	 * @var \Isotop\Syncs\Database
	 */
	protected $database;

	/**
	 * Class instance.
	 *
	 * @var \Isotop\Syncs\Syncs
	 */
	protected static $instance;

	/**
	 * Syncs post types.
	 *
	 * @var array.
	 */
	protected $post_types;

	/**
	 * Current taxonomy.
	 *
	 * @var string
	 */
	protected $taxonomy;

	/**
	 * Syncs taxonomies.
	 *
	 * @var array
	 */
	protected $taxonomies;

	/**
	 * Get class instance.
	 *
	 * @return \Isotop\Syncs\Syncs
	 */
	public static function instance() {
		if ( ! isset( static::$instance ) ) {
			static::$instance = new static;
		}

		return static::$instance;
	}

	/**
	 * Syncs construct.
	 */
	protected function __construct() {
		$this->database = new Database;
		$this->current_blog_id = get_current_blog_id();

		// Setup action for save attachment.
		add_action( 'wp_update_attachment_metadata', [$this, 'update_attachment_metadata'], 999, 2 );

		// Setup action for delete attachment.
		add_action( 'delete_attachment', [$this, 'delete_post'], 999 );

		// Setup action for save post.
		add_action( 'save_post', [$this, 'save_post'], 999, 2 );

		// Setup action for before delete post.
		add_action( 'before_delete_post', [$this, 'delete_post'], 999 );

		// Setup actions for save term.
		add_action( 'created_term', [$this, 'save_term'], 999, 3 );
		add_action( 'edited_term', [$this, 'save_term'], 999, 3 );

		// Setup actons for delete term.
		add_action( 'delete_term', [$this, 'delete_term'], 999, 3 );
	}

	/**
	 * Delete post action callback.
	 *
	 * @param  int $post_id
	 *
	 * @return bool
	 */
	public function delete_post( $post_id ) {
		if ( is_multisite() && ms_is_switched() ) {
			return false;
		}

		if ( empty( $post_id ) ) {
			return false;
		}

		if ( ! ( $post = get_post( $post_id ) ) ) {
			return false;
		}

		if ( ! in_array( $post->post_type, $this->get_post_types() ) ) {
			return false;
		}

		return $this->sync( $post_id, 'post', 'delete' );
	}

	/**
	 * Delete term action callback.
	 *
	 * @param  int    $term_id
	 * @param  int    $tt_id
	 * @param  string $taxonomy
	 */
	public function delete_term( int $term_id, int $tt_id, string $taxonomy ) {
		if ( is_multisite() && ms_is_switched() ) {
			return false;
		}

		if ( empty( $term_id ) ) {
			return false;
		}

		if ( ! in_array( $taxonomy, $this->get_taxonomies() ) ) {
			return false;
		}

		$this->taxonomy = $taxonomy;

		return $this->sync( $term_id, 'term', 'delete' );
	}

	/**
	 * Get post types that should be allowed to sync.
	 *
	 * @return array
	 */
	protected function get_post_types() {
		if ( empty( $this->post_types ) ) {
			$post_types = get_post_types( ['syncs' => true] );
			$post_types = array_values( $post_types );
			$post_types = array_unique( $post_types );

			/**
			 * Let developers modify post types array.
			 *
			 * @param array $post_types
			 */
			$post_types = apply_filters( 'syncs_post_types', $post_types );
			$post_types = is_array( $post_types ) ? $post_types : [];

			$this->post_types = $post_types;
		}

		return $this->post_types;
	}

	/**
	 * Get taxonomies that should be allowed to sync.
	 *
	 * @return array
	 */
	protected function get_taxonomies() {
		if ( empty( $this->taxonomies ) ) {
			$taxonomies = get_taxonomies( ['syncs' => true] );
			$taxonomies = array_values( $taxonomies );
			$taxonomies = array_unique( $taxonomies );

			/**
			 * Let developers modify taxonomies array.
			 *
			 * @param array $taxonomies
			 */
			$taxonomies = apply_filters( 'syncs_taxonomies', $taxonomies );
			$taxonomies = is_array( $taxonomies ) ? $taxonomies : [];

			$this->taxonomies = $taxonomies;
		}

		return $this->taxonomies;
	}

	/**
	 * Save post action callback.
	 *
	 * @param  int      $post_id
	 * @param  \WP_Post $post
	 *
	 * @return bool
	 */
	public function save_post( int $post_id, $post = null ) {
		if ( is_multisite() && ms_is_switched() ) {
			return false;
		}

		if ( empty( $post ) ) {
			$post = get_post( $post_id );
		}

		if ( empty( $post_id ) ) {
			return false;
		}

		if ( ! in_array( $post->post_type, $this->get_post_types() ) ) {
			return false;
		}

		return $this->sync( $post_id, 'post' );
	}

	/**
	 * Save term action callback.
	 *
	 * @param  int    $term_id
	 * @param  int    $tt_id
	 * @param  string $taxonomy
	 */
	public function save_term( int $term_id, int $tt_id, string $taxonomy ) {
		if ( is_multisite() && ms_is_switched() ) {
			return false;
		}

		if ( empty( $term_id ) ) {
			return false;
		}

		if ( ! in_array( $taxonomy, $this->get_taxonomies() ) ) {
			return false;
		}

		$this->taxonomy = $taxonomy;

		return $this->sync( $term_id, 'term' );
	}

	/**
	 * Sync images to other sites on update attachment metadata.
	 *
	 * @param  array $data
	 * @param  int   $post_id
	 *
	 * @return bool
	 */
	public function update_attachment_metadata( $data, $post_id ) {
		// Be sure to sync the post first.
		if ( ! $this->save_post( $post_id ) ) {
			return false;
		}

		// Bail if it's a large network.
		if ( wp_is_large_network() ) {
			return false;
		}

		$dir = wp_upload_dir();

		// Bail if upload directory is empty.
		if ( empty( $dir ) ) {
			return false;
		}

		// Get all sites that we should sync.
		$sites = get_sites( ['network' => 1, 'limit' => 1000] );

		foreach ( $sites as $site ) {
			// Don't sync post on the current site.
			if ( intval( $site->blog_id ) === $this->current_blog_id ) {
				continue;
			}

			switch_to_blog( $site->blog_id );

			// Add default size as a size.
			$data['sizes'][] = [
				'file' => basename( $data['file'] ),
			];

			// Copy all sizes between sites.
			foreach ( array_values( $data['sizes'] ) as $size ) {
				$from = sprintf( '%s/%s', $dir['path'], $size['file'] );
				$to = sprintf( '%s/sites/%s%s/%s', $dir['basedir'], $site->blog_id, $dir['subdir'], $size['file'] );

				if ( ! file_exists( dirname( $to ) ) && ! is_dir( dirname( $to ) ) ) {
					wp_mkdir_p( dirname( $to ) );
				}

				copy( $from, $to );
			}

			// Update attachment metadata between sites.
			if ( $sync_id = $this->database->get( $post_id, 'post', 'sync_id', $this->current_blog_id ) ) {
				if ( $object_id = $this->database->get_object_id( 'post', $sync_id ) ) {
					update_post_meta( $object_id, '_wp_attachment_metadata', $data );
				}
			}

			restore_current_blog();
		}

		return $data;
	}

	/**
	 * Delete object from site.
	 *
	 * @param  int    $object_id
	 * @param  string $object_type
	 *
	 * @return bool
	 */
	protected function delete( int $object_id, string $object_type ) {
		switch ( $object_type ) {
			case 'post':
				return wp_delete_post( $object_id, true ) !== false;
			case 'term':
				return wp_delete_term( $object_id, $this->taxonomy, true ) === true;
			default:
				return true;
		}
	}

	/**
	 * Create object on site.
	 *
	 * @param  array $object
	 * @param  int   $sync_id
	 *
	 * @return bool|int
	 */
	protected function create( array $object, int $sync_id ) {
		$object_id = 0;

		// Bail if keys don't exists.
		if ( ! isset( $object['type'], $object['data'] ) ) {
			return false;
		}

		switch ( $object['type'] ) {
			case 'post':
				$post = $object['data'];

				// Empty post id required.
				$post['ID'] = '';

				// Replace parent id with a new parent id if it can be found.
				if ( $post['post_parent'] > 0 ) {
					if ( $sync_id = $this->database->get( $post['post_parent'], 'post', 'sync_id', $this->current_blog_id ) ) {
						if ( $object_id = $this->database->get_object_id( 'post', $sync_id ) ) {
							$post['post_parent'] = $object_id;
						} else {
							$post['post_parent'] = 0;
						}
					} else {
						$post['post_parent'] = 0;
					}
				}

				// Insert new post to the post table.
				$object_id = wp_insert_post( $post );
				break;
			case 'term':
				$term = $object['data'];

				// Delete existing term since a term can't have same name as another term.
				if ( $existing_term = get_term_by( 'name',  $term['name'], $term['taxonomy'] ) ) {
					wp_delete_term( $existing_term->term_id, $term['taxonomy'], ['force_default' => true] );
				}

				// Replace parent id with a new parent id if it can be found.
				if ( $term['parent'] > 0 ) {
					if ( $sync_id = $this->database->get( $term['parent'], 'term', 'sync_id', $this->current_blog_id ) ) {
						if ( $object_id = $this->database->get_object_id( 'term', $sync_id ) ) {
							$term['parent'] = $object_id;
						} else {
							$term['parent'] = 0;
						}
					} else {
						$term['parent'] = 0;
					}
				}

				// Insert new term to the term table.
				$object_id = wp_insert_term( $term['name'], $term['taxonomy'], [
					'description' => $term['description'],
				 	'parent'      => $term['parent'],
					'slug'        => $term['slug']
				] );

				if ( is_array( $object_id ) && isset( $object_id['term_id'] ) ) {
					$object_id = $object_id['term_id'];
				}

				break;
			default:
				break;
		}

		if ( $object_id === 0 || is_wp_error( $object_id ) ) {
			return false;
		}

		if ( isset( $object['meta'] ) ) {
			foreach ( (array) $object['meta'] as $key => $value ) {
				if ( is_array( $value ) && count( $value ) === 1 ) {
					$value = $value[0];
				}

				update_metadata( $object['type'], $object_id, $key, $value );
			}
		}

		// Add our own sync id as a meta key, nothing we use but can be useful for others.
		update_metadata( $object['type'], $object_id, 'sync_id', $sync_id );

		return $object_id;
	}

	/**
	 * Get object that should be synced.
	 *
	 * @param  int    $object_id
	 * @param  string $object_type
	 *
	 * @return array
	 */
	public function get( int $object_id, string $object_type ) {
		$object = [
			'data' => [],
			'meta' => get_metadata( $object_type, $object_id ),
			'type' => $object_type
		];

		switch ( $object_type ) {
			case 'post':
				$object['data'] = get_post( $object_id, ARRAY_A );
				break;
			case 'term':
				$object['data'] = get_term_by( 'term_id', $object_id, $this->taxonomy, ARRAY_A );
				break;
			default:
				break;
		}

		return $object;
	}

	/**
	 * Get sync id for given object and site id.
	 *
	 * @param  int    $object_id
	 * @param  string $object_type
	 * @param  int    $site_id
	 *
	 * @return int
	 */
	public function get_sync_id( int $object_id, string $object_type, int $site_id = 0 ) {
		return $this->database->get( $object_id, $object_type, 'sync_id', $site_id );
	}

	/**
	 * Sync object from one site to other sites in the network.
	 *
	 * @param  int    $object_id
	 * @param  string $object_type
	 * @param  string $action
	 *
	 * @return bool
	 */
	public function sync( int $object_id, string $object_type, string $action = 'create' ) {
		// Get sync id if any exists.
		$sync_id = $this->database->get( $object_id, $object_type );

		// Create a sync id if empty.
		if ( empty( $sync_id ) ) {
			$sync_id = $this->database->create( $object_id, $object_type );
		}

		// Bail if sync id is empty.
		if ( empty( $sync_id ) ) {
			return false;
		}

		// Bail if it's a large network.
		if ( wp_is_large_network() ) {
			return false;
		}

		// Be sure to delete sync id for this object if delete action.
		if ( $action === 'delete' ) {
			$this->database->delete( $object_id, $object_type, $this->current_blog_id );
		}

		// Get object that should be synced to other sites.
		$object = $this->get( $object_id, $object_type );

		// Get all sites that we should sync.
		$sites = get_sites( ['network' => 1, 'limit' => 1000] );

		foreach ( $sites as $site ) {
			// Don't sync post on the current site.
			if ( intval( $site->blog_id ) === $this->current_blog_id ) {
				continue;
			}

			switch_to_blog( $site->blog_id );

			$deleted = false;

			// Delete object if any object id can be found from the object type, sync id and site id.
			// Then delete sync id row for that object id and object type.
			if ( $object_id = $this->database->get_object_id( $object_type, $sync_id ) ) {
				if ( $this->delete( $object_id, $object_type ) ) {
					$deleted = $this->database->delete( $object_id, $object_type );
				}
			} else {
				$deleted = true;
			}

			// Bail if not deleted.
			if ( ! $deleted ) {
				continue;
			}

			// Create object on the current site and if it returns a int id, then it ways a success.
			if ( $action === 'create' ) {
				$object_id = $this->create( $object, $sync_id );

				// Bail if object is empty.
				if ( empty( $object_id ) ) {
					continue;
				}

				// Create a new row with new object id and new sync id.
				$created = $this->database->create( $object_id, $object_type, $sync_id );
			}

			restore_current_blog();
		}

		return true;
	}
}
