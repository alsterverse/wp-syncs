<?php

namespace Isotop\Tests\Syncs;

use Isotop\Syncs\Syncs;

class Syncs_Test extends \WP_UnitTestCase {

	/**
	 * @var Syncs
	 */
	private $syncs;

	public function setUp() {
		parent::setUp();

		$this->syncs = Syncs::instance();
	}

	public static function wpSetUpBeforeClass( $factory ) {
		$factory->blog->create( [
			'domain' => 'example.org',
			'path'   => '/foo/',
		] );

		// Sorry
		add_filter( 'syncs_post_types',
			function () {
				return [ 'attachment' , 'post'];
			} );
	}

	/**
	 *
	 */
	public static function wpTearDownAfterClass() {
		wpmu_delete_blog( 2, true );
		wp_update_network_site_counts();
	}

	/**
	 *
	 */
	public function tearDown() {
		parent::tearDown();

		unset( $this->syncs );
	}


	public function test_change_upload_dir() {
		foreach ( [1, 2] as $id ) {
			switch_to_blog( $id );
			$dir = wp_upload_dir();
			$dir = $this->syncs->change_upload_dir( $dir );
			$this->assertSame( 'http://example.org/wp-content/uploads', $dir['baseurl'] );
			restore_current_blog();
		}
	}


	public function test_post() {
		$this->assertFalse( $this->syncs->save_post( 0, null ) );

		$post_id = $this->factory->post->create();
		$post    = get_post( $post_id );

		$post_invalid_post_type = $post;
		$post_invalid_post_type->post_type = "test";
		// Not a valid post type.
		$this->assertFalse( $this->syncs->save_post( $post_id, $post_invalid_post_type ) );


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

		// Turn off wp switch, just for testing.
		$GLOBALS['_wp_switched_stack'] = null;

		// Check sync id.
		$this->assertSame( $this->syncs->get_sync_id( $posts[0]->ID, 'post' ), get_post_meta( $posts[0]->ID, 'sync_id', true ) );

		// Turn on wp switch, just for testing.
		$GLOBALS['_wp_switched_stack'] = [1];

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

	/**
	 * Test attachment async
	 */
	public function test_attachment() {


		restore_current_blog();


		$upload_file = DIR_TESTDATA . '/images/2004-07-22-DSC_0007.jpg';

		$attachment_id = $this->factory->attachment->create_upload_object( $upload_file, 0 );


		restore_current_blog();

		$post = get_post( $attachment_id );

		$file = get_attached_file( $attachment_id );

		$this->assertTrue( is_string( $file ) );
		$this->assertFileExists( $file );


		$this->assertFileEquals( $upload_file, $file );


		$GLOBALS['_wp_switched_stack'] = null;
		$this->assertTrue( $this->syncs->save_post( $attachment_id, $post ) );

		$sync_id = $this->syncs->get_sync_id( $attachment_id, 'post', 1 );

		$attachment_id_site2 = $this->syncs->get_object_id( 'post' , $sync_id , 2 );


		switch_to_blog( 2 );

		$post_blog2 = get_post( $attachment_id_site2 );

		$this->assertSame( $post_blog2->post_title, $post->post_title );


		switch_to_blog(1);

		$deleted = wp_delete_post( $attachment_id , true );


		$this->assertFileNotExists( $file );


		switch_to_blog( 2 );

		$post_deleted_blog2 = get_post( $attachment_id_site2 );

		restore_current_blog();





	}

	public function test_term() {
		$this->assertFalse( $this->syncs->save_term( 0, 0, 'category' ) );

		$term_id = $this->factory->category->create();
		$term    = get_category( $term_id );

		// Not a valid taxonomy.
		$this->assertFalse( $this->syncs->save_term( $term_id, 0, 'category' ) );

		add_filter( 'syncs_taxonomies',
			function () {
			return [ 'category' ];
		} );

		$this->assertTrue( $this->syncs->save_term( $term_id, 0, 'category' ) );

		switch_to_blog( 2 );

		$terms = get_categories( [ 'hide_empty' => false ] );

		$this->assertSame( 2, get_current_blog_id() );
		$this->assertSame( 2, count( $terms ) );

		// But category name should match since it's the same category.
		$this->assertSame( $terms[0]->name, $term->name );

		// Check sync id.
		$this->assertSame( $this->syncs->get_sync_id( $terms[0]->term_id, 'term' ), (int) get_term_meta( $terms[0]->term_id, 'sync_id', true ) );

		restore_current_blog();

		$this->assertTrue( $this->syncs->save_term( $term_id, 0, 'category' ) );

		switch_to_blog( 2 );

		$terms = get_categories( [ 'hide_empty' => false ] );

		$this->assertSame( 2, get_current_blog_id() );
		$this->assertSame( 2, count( $terms ) );

		// But category name should match since it's the same category.
		$this->assertSame( $terms[0]->name, $term->name );

		restore_current_blog();

		$this->assertTrue( $this->syncs->delete_term( $term_id, 0, 'category' ) );

		switch_to_blog( 2 );

		$terms = get_categories( [ 'hide_empty' => false ] );

		$this->assertSame( 2, get_current_blog_id() );
		$this->assertSame( 1, count( $terms ) );

		// Category name should not match the default name.
		$this->assertNotSame( $terms[0]->name, $term->name );

		restore_current_blog();
	}

}
