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
	 * Upload paths cache, only calculate paths once.
	 *
	 * @var array
	 */
	protected $upload_paths_cache = [];

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

		// Setup filter for sync id meta value for posts.
		add_action( 'get_post_metadata', [$this, 'get_metadata'], 10, 4 );

		// Setup filter for sync id meta value for terms.
		add_action( 'get_term_metadata', [$this, 'get_metadata'], 10, 4 );

		// Setup filter for upload directory.
		add_filter( 'upload_dir', [ $this, 'change_upload_dir' ] );
	}

	/**
	 * Change upload path to use same for all sites
	 *
	 * @param  array $upload_paths
	 *
	 * @return array
	 */
	public function change_upload_dir( $upload_paths ) {
		// Bail if upload paths error.
		if ( ! empty( $upload_paths['error'] ) ) {
			return $upload_paths;
		}

		// Create pload paths cache value if not set.
		if ( ! isset( $this->upload_paths_cache[ $upload_paths['subdir'] ] ) ) {
			$upload_folder     = defined( 'UPLOADS' ) ? ABSPATH . UPLOADS : WP_CONTENT_DIR . '/uploads';
			$wp_content_url    = defined( 'CONTENT_DIR' ) ? CONTENT_DIR : '/wp-content';
			$upload_folder_url = defined( 'UPLOADS' ) ? UPLOADS : $wp_content_url . '/uploads';

			$upload_paths['basedir'] = $upload_folder;
			$upload_paths['path']    = $upload_folder . $upload_paths['subdir'];
			$upload_paths['baseurl'] = home_url( $upload_folder_url );
			$upload_paths['url']     = home_url( $upload_folder_url . $upload_paths['subdir'] );

			$this->upload_paths_cache[$upload_paths['subdir']] = $upload_paths;
		}

		return $this->upload_paths_cache[$upload_paths['subdir']];
	}

	/**
	 * Create sync id when no sync id exists for terms.
	 *
	 * @param  mixed   $value
	 * @param  integer $object_id
	 * @param  string  $meta_key
	 * @param  bool    $single
	 *
	 * @return mixed
	 */
	public function get_metadata( $value, $object_id, $meta_key, $single ) {
		if ( is_multisite() && ms_is_switched() ) {
			return $value;
		}

		if ( $meta_key !== 'sync_id' ) {
			return $value;
		}

		// Get object type from current filter.
		$object_type = str_replace( '_metadata', '', str_replace( 'get_', '', current_filter() ) );
		$object_type = empty( $object_type ) ? 'post' : $object_type;

		// Check so we use a post type or taxonomy that are configured with syncs.
		switch ( $object_type ) {
			case 'post':
				if ( ! in_array( get_post_type( $object_id ), $this->get_post_types(), true ) ) {
					return $value;
				}

				break;
			case 'term':
				$term = get_term( $object_id );

				if ( empty( $term ) ) {
					return $value;
				}

				if ( ! in_array( $term->taxonomy, $this->get_taxonomies(), true ) ) {
					return $value;
				}

				break;
		}

		// Get sync id from database.
		$sync_id = $this->get_sync_id( $object_id, $object_type );

		// If sync id is empty we can create a new one.
		if ( empty( $sync_id ) ) {
			$sync_id = $this->create_sync_id( $object_id, $object_type );
		}

		// Bail if empty sync id and return input value.
		if ( empty( $sync_id ) ) {
			return $value;
		}

		// Handle single bool.
		if ( $single ) {
			return $sync_id;
		}

		return [$sync_id];
	}

	/**
	 * Delete post action callback.
	 *
	 * @param  integer $post_id
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

		if ( ! in_array( $post->post_type, $this->get_post_types(), true ) ) {
			return false;
		}

		return $this->sync( $post_id, 'post', 'delete' );
	}

	/**
	 * Delete term action callback.
	 *
	 * @param  integer $term_id
	 * @param  integer $tt_id
	 * @param  string  $taxonomy
	 */
	public function delete_term( int $term_id, int $tt_id, string $taxonomy ) {
		if ( is_multisite() && ms_is_switched() ) {
			return false;
		}

		if ( empty( $term_id ) ) {
			return false;
		}

		if ( ! in_array( $taxonomy, $this->get_taxonomies(), true ) ) {
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
	 * @param  integer  $post_id
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

		if ( ! in_array( $post->post_type, $this->get_post_types(), true ) ) {
			return false;
		}

		return $this->sync( $post_id, 'post' );
	}

	/**
	 * Save term action callback.
	 *
	 * @param  integer $term_id
	 * @param  integer $tt_id
	 * @param  string  $taxonomy
	 */
	public function save_term( int $term_id, int $tt_id, string $taxonomy ) {
		if ( is_multisite() && ms_is_switched() ) {
			return false;
		}

		if ( empty( $term_id ) ) {
			return false;
		}

		if ( ! in_array( $taxonomy, $this->get_taxonomies(), true ) ) {
			return false;
		}

		$this->taxonomy = $taxonomy;

		return $this->sync( $term_id, 'term' );
	}

	/**
	 * Sync images to other sites on update attachment metadata.
	 *
	 * @param  array   $data
	 * @param  integer $post_id
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

		// Get all sites that we should sync.
		$sites = get_sites( ['network' => 1, 'limit' => 1000] );

		foreach ( $sites as $site ) {
			// Don't sync post on the current site.
			if ( intval( $site->blog_id ) === $this->current_blog_id ) {
				continue;
			}

			switch_to_blog( $site->blog_id );

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
	 * @param  integer $object_id
	 * @param  string  $object_type
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
	 * @param  array   $object
	 * @param  integer $sync_id
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

				$taxonomies = isset( $post['taxonomies'] ) ? $post['taxonomies'] : [];

				// Insert all taxonomies and ids from the original post.
				foreach ( $taxonomies as $taxonomy => $sync_ids ) {
					$term_ids = [];

					foreach ( $sync_ids as $sync_id ) {
						if ( $term_id = $this->database->get_object_id( 'term', $sync_id ) ) {
							$term_ids[] = $term_id;
						}
					}

					wp_set_object_terms( $object_id, array_unique( $term_ids ), $taxonomy );
				}

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
		update_metadata( $object['type'], $object_id, 'sync_id', $sync_id, true );

		return $object_id;
	}

	/**
	 * Get object that should be synced.
	 *
	 * @param  integer $object_id
	 * @param  string  $object_type
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

				if ( ! isset( $object['data']['post_type'] ) ) {
					break;
				}

				$object['data']['taxonomies'] = [];

				$taxonomies = get_object_taxonomies( $object['data']['post_type'] );

				foreach ( $taxonomies as $taxonomy ) {
					$terms = wp_get_post_terms( $object['data']['ID'], $taxonomy, ['fields' => 'all'] );
					$terms = is_array( $terms ) ? $terms : [];

					foreach ( $terms as $term ) {
						$sync_id = $this->get_sync_id( $term->term_id, 'term' );

						if ( ! isset( $object['data']['taxonomies'][$taxonomy] ) ) {
							$object['data']['taxonomies'][$taxonomy] = [];
						}

						$object['data']['taxonomies'][$taxonomy][] = $sync_id;
					}
				}
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
	 * @param  integer $object_id
	 * @param  string  $object_type
	 * @param  integer $site_id
	 *
	 * @return integer
	 */
	public function get_sync_id( int $object_id, string $object_type, int $site_id = 0 ) {
		$sync_id = $this->database->get( $object_id, $object_type, 'sync_id', $site_id );

		// Add our own sync id as a meta key, nothing we use but can be useful for others.
		if ( ! empty( $sync_id ) ) {
			update_metadata( $object_type, $object_id, 'sync_id', $sync_id, true );
		}

		return $sync_id;
	}

	/**
	 * Create sync id.
	 *
	 * @param  integer $object_id
	 * @param  string  $object_type
	 *
	 * @return integer
	 */
	public function create_sync_id( int $object_id, string $object_type ) {
		$sync_id = $this->database->create( $object_id, $object_type );

		// Add our own sync id as a meta key, nothing we use but can be useful for others.
		if ( ! empty( $sync_id ) ) {
			update_metadata( $object_type, $object_id, 'sync_id', $sync_id, true );
		}

		return $sync_id;
	}

	/**
	 * Delete sync id.
	 *
	 * @param  integer $object_id
	 * @param  string  $object_type
	 */
	public function delete_sync_id( int $object_id, string $object_type ) {
		delete_metadata( $object_type, $object_id, 'sync_id' );

		return $this->database->delete( $object_id, $object_type, $this->current_blog_id );
	}

	/**
	 * Sync object from one site to other sites in the network.
	 *
	 * @param  integer $object_id
	 * @param  string  $object_type
	 * @param  string  $action
	 *
	 * @return bool
	 */
	public function sync( int $object_id, string $object_type, string $action = 'create' ) {
		// Get sync id if any exists.
		$sync_id = $this->database->get( $object_id, $object_type );

		// Create a sync id if empty.
		if ( empty( $sync_id ) ) {
			$sync_id = $this->create_sync_id( $object_id, $object_type );
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
			$this->delete_sync_id( $object_id, $object_type );
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
