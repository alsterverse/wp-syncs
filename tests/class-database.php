<?php

namespace Isotop\Tests\Syncs;

use Isotop\Syncs\Database;

class Database_Test extends \PHPUnit_Framework_TestCase {

	public function test_delete() {
		$db = new Database;
		$sync_id = $db->create( 1, 'post' );
		$post_id = $db->get_object_id( 'post', $sync_id );
		$this->assertSame( 1, $post_id );
		$db->delete( $post_id, 'post' );
	}

	public function test_create() {
		$db = new Database;
		$sync_id = $db->create( 1, 'post' );
		$post_id = $db->get_object_id( 'post', $sync_id );
		$this->assertSame( 1, $post_id );
		$db->delete( $post_id, 'post' );
	}

	public function test_get() {
		$db = new Database;
		$post_id = 1;
		$sync_id = $db->create( $post_id, 'post' );
		$this->assertSame( $sync_id, $db->get( $post_id, 'post' ) );
		$db->delete( $post_id, 'post' );
	}

	public function test_get_object_id() {
		$db = new Database;
		$sync_id = $db->create( 1, 'post' );
		$post_id = $db->get_object_id( 'post', $sync_id );
		$this->assertSame( 1, $post_id );
		$db->delete( $post_id, 'post' );
		$this->assertEmpty( $db->get_object_id( 'post', $sync_id ) );
	}

	public function test_get_object_id_is_emtpy() {
		$db = new Database;
		$sync_id = 1;
		$expected_object_id = 0;
		$post_id = $db->get_object_id( 'post', $sync_id );
		$this->assertSame( $expected_object_id, $post_id );
		$this->assertSame( $expected_object_id, $db->get_last_sync_id() );
	}

	public function test_get_last_sync_id() {
		$db = new Database;
		$sync_id = $db->create( 1, 'post' );
		$post_id = $db->get_object_id( 'post', $sync_id );
		$this->assertSame( 1, $post_id );
		$this->assertSame( $sync_id, $db->get_last_sync_id() );
		$db->delete( $post_id, 'post' );
	}

	public function test_get_last_sync_id_is_emtpy() {
		$db = new Database;
		$sync_id = 1;
		$expected_sync_id = 0;
		$post_id = $db->get_object_id( 'post', $sync_id );
		$this->assertSame( $expected_sync_id, $post_id );
		$this->assertSame( $expected_sync_id, $db->get_last_sync_id() );
	}
}
