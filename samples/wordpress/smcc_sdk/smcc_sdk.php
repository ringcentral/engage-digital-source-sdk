<?php
/*
Plugin Name: Dimelo SMCC SDK
Plugin URI: https://github.com/dimelo/sdk/tree/master/samples/wordpress
Description: The simplest way to integrate your Wordpress blog with Dimelo's SMCC.
Version: 1.0
Author: Dimelo, SA
Author URI: http://www.dimelo.com/
License: Apache License, Version 2.0
*/

/**
 * Copyright 2012 Dimelo, SA
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *	 http://www.apache.org/licenses/LICENSE-2.0
 * 
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

define( 'SMCC_SDK_PATH', realpath( dirname( __FILE__ ) ) );

require SMCC_SDK_PATH . '/sdk.php';
require SMCC_SDK_PATH . '/options.php';

// Handle the request if it is SDK related, do nothing otherwise.
add_action( 'init', array( new SmccSdkImplementation, 'run' ) );

/**
 * Processes all requests that have an `dimelo_smcc_sdk` query parameter set.
 */
class SmccSdkImplementation { // {{{

	protected $implementation = array(
		'objects' => array(
			'messages' => array( 'create', 'delete', 'list', 'publish', 'show', 'spam', 'unspam', 'unpublish' ),
			'threads'  => array( 'create', 'delete', 'list', 'publish', 'show', 'unpublish' )
		),
		'options' => array( 'messages.no_title' ),
		'locales' => array(
			'default' => array( 'spam' => 'Mark as spam', 'unspam' => 'Mark as valid' ),
			'fr' => array( 'spam' => 'Marquer comme spam', 'unspam' => 'Marquer comme valide' )
		)
	);

	public function __construct() {
		$options = get_option( 'smcc_sdk_options' );

		$this->db	 = new SmccSdkDb();
		$this->format = new SmccSdkFormat();
		$this->sdk	= new SmccSdk( $options['secret_key'] );
	}

	public function is_sdk_request() {
		return array_key_exists( 'dimelo_smcc_sdk', $_GET );
	}

	public function is_action_implemented( $object, $operation ) {
		return 'implementation.info' == "${object}.${operation}" ||
			( array_key_exists( $object, $this->implementation['objects'] ) &&
			in_array( $operation, $this->implementation['objects'][$object] ) );
	}

	public function run() {
		if ( ! $this->is_sdk_request() ) {
			return;
		}

		date_default_timezone_set( get_option( 'timezone_string' ) );

		$sdk = $this->sdk;

		if ( ! $sdk->is_valid_request() ) {
			$sdk->error( 'Invalid signature' );
		}

		list( $object, $operation ) = explode( '.', $sdk->get_action() );
		if ( ! $this->is_action_implemented( $object, $operation ) ) {
			$sdk->error( 'Invalid action' );
		}

		$this->dispatch( $object, $operation );
	}

	public function dispatch( $object, $operation ) {
		$params = $this->sdk->get_params();

		$args = array( $this->sdk );
		switch ( $operation ) {
			case 'info':
				break;
			case 'create':
				$args[] = $params;
				break;
			case 'list':
				$args[] = $params['since_id'];
				break;
			case 'delete':
			case 'publish':
			case 'show':
			case 'unpublish':
				$args[] = $params['id'];
			default:
				$args[] = $params['id'];
				$args[] = $params['user_id'];
		}

		call_user_func_array( array( $this, $object . '_' . $operation ), $args );
	}

	public function implementation_info( $sdk ) {
		$sdk->respond( $this->implementation );
	}

	public function messages_create( $sdk, $params ) {
		$this->ensure_exists( array(
			'user' => $params['author_id'],
			'post' => $params['thread_id']
		) );

		$db = $this->db;

		$user = $db->get_user( $params['author_id'] );

		$data = array(
			'comment_post_ID'	  => $params['thread_id'],
			'comment_content'	  => $params['body'],
			'comment_parent'	   => $params['in_reply_to_id'],
			'comment_date'		 => SmccSdk::from_sdk_time( $params['created_at'] ),
			'comment_approved'	 => 1,
			'user_id'			  => $user['ID'],
			'comment_author'	   => $user['user_nicename'],
			'comment_author_email' => $user['user_email'],
			'comment_author_url'   => $user['user_url']
		);

		$id = $db->comments_create( $data );
		if ( empty( $id ) ) {
			$sdk->error( 'Could not create the comment' );
		} else {
			$sdk->respond( $this->render_message( $id ) );
		}
	}

	public function messages_delete( $sdk, $id ) {
		$this->ensure_exists( array(
			'comment' => $id
		) );

		$comment = $this->render_message( $id );

		if ( $this->db->delete_comment( $id ) ) {
			$sdk->respond( $comment );
		} else {
			$sdk->error( 'Could not delete content' );
		}
	}

	public function messages_list( $sdk, $since_id ) {
		$messages = array();
		foreach ( $this->db->get_comments_since( $since_id ) as $comment ) {
			$messages[] = $this->render_message( $comment['comment_id'] );
		}
		$sdk->respond( $messages );
	}

	public function messages_show( $sdk, $id ) {
		$this->ensure_exists( array(
			'comment' => $id
		) );

		$sdk->respond( $this->render_message( $id ) );
	}

	public function messages_spam( $sdk, $id, $user_id ) {
		$this->ensure_exists( array(
			'comment' => $id,
			'user'	=> $user_id
		) );

		if ( wp_set_comment_status( $id, 'spam' ) ) {
			$sdk->respond( $this->render_message( $id ) );
		} else {
			$sdk->error( 'Could not mark message as spam' );
		}
	}

	public function messages_unspam( $sdk, $id, $user_id ) {
		$this->ensure_exists( array(
			'comment' => $id,
			'user'	=> $user_id
		) );

		if ( wp_set_comment_status( $id, 'approve' ) ) {
			$sdk->respond( $this->render_message( $id ) );
		} else {
			$sdk->error( 'Could not unmark message as spam' );
		}
	}

	public function messages_publish( $sdk, $id ) {
		$this->ensure_exists( array(
			'comment' => $id
		) );

		if ( wp_set_comment_status( $id, 'approve' ) ) {
			$sdk->respond( $this->render_message( $id ) );
		} else {
			$sdk->error( 'Could not publish message' );
		}
	}

	public function messages_unpublish( $sdk, $id ) {
		$this->ensure_exists( array(
			'comment' => $id
		) );

		if ( wp_set_comment_status( $id, 'hold' ) ) {
			$sdk->respond( $this->render_message( $id ) );
		} else {
			$sdk->error( 'Could not unpublish message' );
		}
	}

	public function threads_create( $sdk, $attrs ) {
		$this->ensure_exists( array(
			'user' => $attrs['author_id']
		) );

		$id = $this->db->posts_create( $attrs );
		if ( $id ) {
			$sdk->respond( $this->render_thread( $id ) );
		} else {
			$sdk->error('Could not create thread');
		}
	}

	public function threads_list( $sdk, $since_id ) {
		$posts = array();
		foreach ( $this->db->get_posts_since( $since_id ) as $post ) {
			$posts[] = $this->render_thread( $post['ID'] );
		}
		$sdk->respond( $posts );
	}

	public function threads_delete( $sdk, $id ) {
		$this->ensure_exists( array(
			'post' => $id
		) );

		$thread = $this->render_thread( $id );

		if ( $this->db->posts_delete( $id ) ) {
			$sdk->respond( $thread );
		} else {
			$sdk->error( 'Could not delete thread' );
		}
	}

	public function threads_show( $sdk, $id ) {
		$this->ensure_exists( array(
			'post' => $id
		) );

		$sdk->respond( $this->render_thread( $id ) );
	}

	public function threads_publish( $sdk, $id ) {
		$this->ensure_exists( array(
			'post' => $id
		) );

		if ( wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) ) ) {
			$sdk->respond( $this->render_thread( $id ) );
		} else {
			$sdk->error( 'Could not publish thread' );
		}
	}

	public function threads_unpublish( $sdk, $id ) {
		$this->ensure_exists( array(
			'post' => $id
		) );

		if ( wp_update_post( array( 'ID' => $id, 'post_status' => 'pending' ) ) ) {
			$sdk->respond( $this->render_thread( $id ) );
		} else {
			$sdk->error( 'Could not unpublish thread' );
		}
	}

	/**
	 * Helper function to check whether objects exists in the database.
	 */
	protected function ensure_exists( $objects ) {
		foreach ( $objects as $type => $id ) {
			$method = "get_${type}";
			$obj = $this->db->$method( $id );
			if ( empty( $obj ) ) {
				$this->sdk->error( ucfirst( $type ) . " ${id} does not exist!" );
			}
		}
	}

	/**
	 * Returns a formatted comment together with its author.
	 */
	protected function render_message( $id ) {
		$db = $this->db;
		$format = $this->format;

		$comment = $db->get_comment( $id );
		if ( ! empty( $comment['user_id'] ) ) {
			$user = $format->user( $db->get_user( $comment['user_id'] ) );
		} else {
			$user = $format->comment_user( $comment );
		}

		return $format->message( $comment, $user );
	}

	/**
	 * Returns a formatted post with its author.
	 */
	protected function render_thread( $id ) {
		$post = $this->db->get_post( $id );
		$user = $this->db->get_user( $post['post_author'] );

		return $this->format->thread( $post, $this->format->user( $user ) );
	}

} // }}}

/**
 * Converts from Wordpress data to SMCC SDK data structures.
 */
class SmccSdkFormat { // {{{

	public function thread( $post, $user ) {
		$actions = array('delete');
		$actions[] = $post['post_status'] == 'publish' ? 'unpublish' : 'publish';

		return array(
			'actions' => $actions,
			'author' => $user,
			'body' => $post['post_content'],
			'categories' => array(),
			'created_at' => SmccSdk::to_sdk_time( $post['post_date'] ),
			'custom_actions' => array(),
			'custom_fields' => array(),
			'display_url' => get_permalink( $post['ID'] ),
			'published' => $post['post_status'] == 'publish',
			'title' => $post['post_title'],
			'id' => $post['ID'],
			'updated_at' => SmccSdk::to_sdk_time( $post['post_modified'] )
		);
	}

	public function message( $comment, $user ) {
		$actions = array( 'delete' );

		if ( $comment['comment_approved'] == '1' ) {
			$actions[] = 'unpublish';
			$actions[] = 'spam';
		} else if ( $comment['comment_approved'] == '0' ) {
			$actions[] = 'publish';
			$actions[] = 'spam';
		} else if ( $comment['comment_approved'] == 'spam' ) {
			$actions[] = 'unspam';
		}

		// spam actions are not valid for comments written by a real user
		if ( is_numeric( $user['id'] ) ) {
			$actions = array_intersect( $actions, array( 'delete', 'publish', 'unpublish' ) );
		}

		$attributes = array(
			'actions' => $actions,
			'author' => $user,
			'body' => $comment['comment_content'],
			'categories' => array(),
			'created_at' => SmccSdk::to_sdk_time( $comment['comment_date'] ),
			'custom_actions' => array(),
			'custom_fields' => array(),
			'latitude' => null,
			'longitude' => null,
			'id' => $comment['comment_ID'],
			'in_reply_to_id' => $comment['comment_parent'],
			'ip' => $comment['comment_author_IP'],
			/*
			 * comment approved - 1
			 * comment pending - 0
			 * comment spam - spam
			 * comment trash - trash
			 */
			'published' => $comment['comment_approved'] == '1',
			'thread_id' => $comment['comment_post_ID'],
			'updated_at' => null
		);
		return $attributes;
	}

	public function user( $user ) {
		return array(
			'avatar_url' => null,
			'created_at' => SmccSdk::to_sdk_time( $user['user_registered'] ),
			'email' => $user['user_email'],
			'latitude' => null,
			'longitude' => null,
			'firstname' => null,
			'home_phone' => null,
			'id' => $user['ID'],
			'ip' => null,
			'lastname' => null,
			'mobile_phone' => null,
			'notes' => null,
			'puppetizable' => true,
			'screenname' => $user['display_name'],
			'updated_at' => null,
			'url' => $user['user_url']
		);
	}

	public function comment_user( $comment ) {
		if ( empty( $comment['comment_author_email'] ) ) {
			return null;
		}

		return array(
			'created_at' => SmccSdk::to_sdk_time( $comment['comment_date'] ),
			'email' => $comment['comment_author_email'],
			'id' => $comment['comment_author_email'],
			'ip' => $comment['comment_author_IP'],
			'puppetizable' => false,
			'screenname' => $comment['comment_author'],
			'url' => $comment['comment_author_url']
		);
	}

} // }}}

class SmccSdkDb { // {{{

	public function get_comments_since( $last_comment_id = null ) {
		$last_comment_id = empty($last_comment_id) ? 0 : $last_comment_id;
		$sql = "SELECT comment_id FROM {$this->table_comments()} as t WHERE (t.comment_approved='0' or t.comment_approved='1' or t.comment_approved='spam') AND t.comment_id > %d ORDER BY t.comment_id DESC";
		return $this->find( $sql, $last_comment_id );
	}

	public function get_comment( $id ) {
		$sql = "SELECT * FROM {$this->table_comments()} WHERE comment_id = %d";
		return $this->find_first( $sql, $id );
	}

	public function delete_comment( $id ) {
		$sql = "DELETE FROM {$this->table_comments()} WHERE comment_id = %d";
		return $this->query( $sql, $id );
	}

	public function comments_create($data) {
		$id = wp_insert_comment( $data );
		// seems that calling the above function will change the timezone
		date_default_timezone_set( get_option( 'timezone_string' ) );
		return $id;
	}

	public function get_user($id) {
		$sql = "SELECT * FROM {$this->table_users()} WHERE ID = %d";
		return $this->find_first( $sql, $id );
	}

	public function get_comment_user($email) {
		$sql = "SELECT * FROM {$this->table_comments()} WHERE comment_author_email = %s ORDER BY comment_ID DESC";
		return $this->find_first( $sql, $email );
	}

	public function get_posts_since( $last_post_id = null ) {
		$last_post_id = empty( $last_post_id ) ? 0 : $last_post_id;
		$sql = "SELECT p.ID FROM {$this->table_posts()} as p WHERE (p.post_status='publish' or p.post_status='pending') AND p.ID > %d ORDER BY p.ID DESC";
		return $this->find( $sql, $last_post_id );
	}

	public function get_post( $id ) {
		$sql = "SELECT * FROM {$this->table_posts()} WHERE id = %d";
		return $this->find_first( $sql, $id );
	}

	public function posts_create( $message ) {
		$data = array(
			'post_author' => $message['author_id'],
			'post_content' => $message['body'],
			'post_date' => SmccSdk::from_sdk_time( $message['created_at'] ),
			'post_status' => 'publish',
			'post_title' => $message['title'],
			'post_type' => 'post'
		);

		$id = wp_insert_post( $data );
		date_default_timezone_set( get_option( 'timezone_string' ) );
		return $id;
	}

	public function posts_delete( $id ) {
		return wp_delete_post( $id, true );
	}

	protected function find( $query, $values = array() ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( $query, $values ), ARRAY_A );
	}

	protected function find_first() {
		$args = func_get_args();
		$result = call_user_func_array( array( $this, 'find' ), $args );
		return empty( $result ) ? null : $result[0];
	}

	protected function query( $query, $values = array() ) {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( $query, $values ) );
		return $result !== false && $result > 0;
	}

	protected function table_comments() {
		global $wpdb;
		return $wpdb->comments;
	}

	protected function table_posts() {
		global $wpdb;
		return $wpdb->posts;
	}

	protected function table_users() {
		global $wpdb;
		return $wpdb->users;
	}

} // }}}

