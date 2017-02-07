<?php

namespace Isotop\Tests\Syncs;

use Isotop\Syncs\Syncs;

class Syncs_Test extends \WP_UnitTestCase {

	public function setUp() {
		parent::setUp();

		$this->syncs = Syncs::instance();
	}

	public static function wpSetUpBeforeClass( $factory ) {
		$factory->blog->create( [
			'domain' => 'example.org',
			'path'   => '/foo/'
		] );
	}

	public static function wpTearDownAfterClass() {
		wpmu_delete_blog( 2, true );
		wp_update_network_site_counts();
	}

	public function tearDown() {
		parent::tearDown();

		unset( $this->syncs );
	}

	public function test_post() {
		$this->assertFalse( $this->syncs->save_post( 0, null ) );

		$post_id = $this->factory->post->create();
		$post    = get_post( $post_id );

		// Not a valid post type.
		$this->assertFalse( $this->syncs->save_post( $post_id, $post ) );

		add_filter( 'syncs_post_types', function () {
			return ['post'];
		} );

		$this->assertTrue( $this->syncs->save_post( $post_id, get_post( $post_id ) ) );

		switch_to_blog( 2 );

		$posts = get_posts();

		$this->assertSame( 2, get_current_blog_id() );
		$this->assertSame( 2, count( $posts ) );

		// But post title should match since it's the same post.
		$this->assertSame( $posts[0]->post_title, $post->post_title );

		restore_current_blog();

		$this->assertTrue( $this->syncs->save_post( $post_id, get_post( $post_id ) ) );

		switch_to_blog( 2 );

		$posts = get_posts();

		$this->assertSame( 2, get_current_blog_id() );
		$this->assertSame( 2, count( $posts ) );

		// But post title should match since it's the same post.
		$this->assertSame( $posts[0]->post_title, $post->post_title );

		restore_current_blog();

		$this->assertTrue( $this->syncs->delete_post( $post_id ) );

		switch_to_blog( 2 );

		$posts = get_posts();

		$this->assertSame( 2, get_current_blog_id() );
		$this->assertSame( 1, count( $posts ) );

		// Post title shouldn't match the default post since we deleted our post.
		$this->assertNotSame( $posts[0]->post_title, $post->post_title );

		restore_current_blog();

		$this->assertTrue( $this->syncs->delete_post( $post_id ) );

		switch_to_blog( 2 );

		$posts = get_posts();

		$this->assertSame( 2, get_current_blog_id() );
		$this->assertSame( 1, count( $posts ) );

		// Post title shouldn't match the default post since we deleted our post.
		$this->assertNotSame( $posts[0]->post_title, $post->post_title );

		restore_current_blog();
	}

	public function test_term() {
		$this->assertFalse( $this->syncs->save_term( 0, 0, 'category' ) );

		$term_id = $this->factory->category->create();
		$term    = get_category( $term_id );

		// Not a valid taxonomy.
		$this->assertFalse( $this->syncs->save_term( $term_id, 0, 'category' ) );

		add_filter( 'syncs_taxonomies', function () {
			return ['category'];
		} );

		$this->assertTrue( $this->syncs->save_term( $term_id, 0, 'category' ) );

		switch_to_blog( 2 );

		$terms = get_categories( ['hide_empty' => false] );

		$this->assertSame( 2, get_current_blog_id() );
		$this->assertSame( 2, count( $terms ) );

		// But category name should match since it's the same category.
		$this->assertSame( $terms[0]->name, $term->name );

		restore_current_blog();

		$this->assertTrue( $this->syncs->save_term( $term_id, 0, 'category' ) );

		switch_to_blog( 2 );

		$terms = get_categories( ['hide_empty' => false] );

		$this->assertSame( 2, get_current_blog_id() );
		$this->assertSame( 2, count( $terms ) );

		// But category name should match since it's the same category.
		$this->assertSame( $terms[0]->name, $term->name );

		restore_current_blog();

		$this->assertTrue( $this->syncs->delete_term( $term_id, 0, 'category' ) );

		switch_to_blog( 2 );

		$terms = get_categories( ['hide_empty' => false] );

		$this->assertSame( 2, get_current_blog_id() );
		$this->assertSame( 1, count( $terms ) );

		// Category name should not match the default name.
		$this->assertNotSame( $terms[0]->name, $term->name );

		restore_current_blog();
	}
}
