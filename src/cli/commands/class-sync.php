<?php

namespace Isotop\Syncs\CLI\Commands;

use Isotop\Syncs\CLI\Command;
use WP_Query;

class Sync extends Command {

	/**
	 * Synchronizes all posts.
	 *
	 * [--action=<value>]
	 * : The action to use. Default is 'create' and can be change to 'delete'.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function __invoke( $args, $assoc_args ) {
		$action = $assoc_args['action'] ?? 'create';
		$posts  = $this->posts();

		foreach ( $posts as $post ) {
			if ( syncs()->sync( $post->ID, 'post', $action ) ) {
				\WP_CLI::log( sprintf( 'Post %s is synchronized', $post->ID ) );
			} else {
				\WP_CLI::log( sprintf( 'Post %s failed to be synchronized ', $post->ID ) );
			}
		}
	}

	/**
	 * Get all posts that should be synced.
	 *
	 * @return array
	 */
	protected function posts() {
		return (array) ( new WP_Query( [
			'posts_per_page'         => - 1,
			'post_type'              => syncs()->get_post_types(),
			'update_post_meta_cache' => false,
			'update_term_meta_cache' => false
		] ) )->posts;
	}
}
