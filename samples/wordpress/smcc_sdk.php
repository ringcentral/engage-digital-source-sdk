<?php
/**
 * @package SMCC_SDK
 * @version 1.0
 */
/*
Plugin Name: SMCC SDK
Plugin URI: http://wordpress.org/extend/plugins/smcc-sdk/
Description: Example implementation of the Dimelo SDK.
Author: Dimelo
Version: 1.0
Author URI: http://www.dimelo.com/
License: GPL2
*/

define('SMCC_SDK_ACCESS_TOKEN', 'EilW295myMpycBTQkaxeyEMhccBGs9yII4MgV4kwb6CTgcCUpOdOsUj5m1wiWZ7');

// Handle the request if it is SDK related, do nothing otherwise.
add_action( 'init', array(new SmccSdkApi, 'respond') );

class SmccSdkApi { // {{{

    protected $implemented_actions = array('messages.create', 'messages.list', 'messages.show', 'messages.destroy', 'messages.publish', 'messages.unpublish', 'threads.create', 'threads.destroy', 'threads.show');
    protected $implemented_options = array('messages.no_title');

    public function __construct() {
        $this->db = new SmccSdkDb();
        $this->format = new SmccSdkFormat();
    }

    public function respond() {
        if (!$this->is_sdk_request()) {
            return;
        }

        $this->validate_token();

        $action = $this->get_action();
        if ($action == 'implementation.info' || in_array($action, $this->implemented_actions)) {
            $method = str_replace('.', '_', $action);
            $this->$method();
        } else {
            $this->error_response('Invalid action');
        }
    }

    public function get_action() {
        return @$_GET['smcc_sdk_action'];
    }

    public function get_body() {
        return json_decode(file_get_contents('php://input'), true);
    }

    public function get_id() {
        return @$_GET['smcc_sdk_id'];
    }

    public function get_since_id() {
        return @$_GET['smcc_sdk_since_id'];
    }

    public function is_sdk_request() {
        $action = $this->get_action();
        return !empty($action);
    }

    public function validate_token() {
        $token = @$_GET['smcc_sdk_access_token'];
        if ($token != SMCC_SDK_ACCESS_TOKEN) {
            $this->error_response('Invalid access token');
        }
    }

    public function success_response($payload) {
        $this->output(array('success' => true, 'payload' => $payload));
    }

    public function error_response($message) {
        $this->output(array('success' => false, 'errorMessage' => $message));
    }

    public function output($array) {
        header('Content-type: application/json');
        echo json_encode($array);
        exit;
    }

    public function implementation_info() {
        $this->success_response(array(
            'actions' => $this->implemented_actions,
            'options' => $this->implemented_options
        ));
    }

    public function messages_create() {
        $message = $this->get_body();

        $data = array(
            'comment_post_ID' => $message['thread_id'],
            'comment_content' => $message['body'],
            'comment_parent' => $message['in_reply_to_id'],
            'comment_date' => $message['created_at'],
            'comment_approved' => 1
        );

        $user = $this->db->get_user($message['author_id']);
        if (empty($user)) {
            $user = $this->db->get_comment_user($message['author_id']);
            if (empty($user)) {
                $this->error_response('Could not create the comment');
            }
            $data['comment_author'] = $user['comment_author'];
            $data['comment_author_email'] = $user['comment_author_email'];
            $data['comment_author_url'] = $user['comment_author_url'];

            $formatted_user = $this->format->comment_user($user);
        } else {
            $data['user_id'] = $user['ID'];
            $data['comment_author'] = $user['user_nicename'];
            $data['comment_author_email'] = $user['user_email'];
            $data['comment_author_url'] = $user['user_url'];

            $formatted_user = $this->format->user($user);
        }

        $comment = $this->db->comments_create($data);
        if (!$comment) {
            $this->error_response('Could not create the comment');
        } else {
            $this->success_response($this->format->message($comment, $formatted_user));
        }
    }

    public function messages_destroy() {
        $response = $this->db->delete_comment($this->get_id());
        $this->success_response($response ? true : false);
    }

    public function messages_list() {
        $messages = array();
        $last_id = $this->get_since_id();
        foreach ($this->db->get_comments_since($last_id) as $comment) {
            if (!empty($comment['user_id'])) {
                $user = $this->format->user($comment);
            } else {
                $user = $this->format->comment_user($comment);
            }
            if ($user) {
                $messages[] = $this->format->message($comment, $user);
            }
        }
        $this->success_response($messages);
    }

    public function messages_show() {
        $comment = $this->db->get_comment($this->get_id());
        if (!empty($comment['user_id'])) {
            $user = $this->format->user($comment);
        } else {
            $user = $this->format->comment_user($comment);
        }
        if ($user) {
            $this->success_response($this->format->message($comment, $user));
        } else {
            $this->error_response('Invalid user');
        }
    }

    public function messages_publish() {
        $res = wp_set_comment_status($this->get_id(), '1');

        if ($res) {
            $this->messages_show();
        } else {
            $this->error_response('Could not publish message');
        }
    }

    public function messages_unpublish() {
        $res = wp_set_comment_status($this->get_id(), '0');

        if ($res) {
            $this->messages_show();
        } else {
            $this->error_response('Could not unpublish message');
        }
    }

    public function threads_create() {
        $post = $this->db->posts_create($this->get_body());
        $user = $this->db->get_user($post['post_author']);

        if (!$post) {
            $this->error_response('Could not create post');
        } else {
            $this->success_response($this->format->thread($post, $this->format->user($user)));
        }
    }

    public function threads_destroy() {
        $response = $this->db->posts_delete($this->get_id());
        $this->success_response($response ? true : false);
    }

    public function threads_show() {
        $post = $this->db->get_post($this->get_id());
        $user = $this->db->get_user($post['post_author']);

        $this->success_response($this->format->thread($post, $this->format->user($user)));
    }

}
// }}}

/**
 * Converts from Wordpress data to SMCC SDK data structures.
 */
class SmccSdkFormat { // {{{

    public function thread($post, $user) {
        return array(
            'author' => $user,
            'body' => $post['post_content'],
            'categories' => array(),
            'created_at' => $post['post_date'],
            'custom_actions' => array(),
            'custom_fields' => array(),
            'title' => $post['post_title'],
            'id' => $post['ID'],
            'updated_at' => $post['post_modified']
        );
    }

    public function message($comment, $user) {
        $attributes = array(
            'author' => $user,
            'body' => $comment['comment_content'],
            'categories' => array(),
            'created_at' => $comment['comment_date'],
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

    public function user($user) {
        return array(
            'avatar_url' => null,
            'created_at' => $user['user_registered'],
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

    public function comment_user($comment) {
        if (empty($comment['comment_author_email'])) {
            return null;
        }

        return array(
            'created_at' => $comment['comment_date'],
            'email' => $comment['comment_author_email'],
            'id' => $comment['comment_author_email'],
            'ip' => $comment['comment_author_IP'],
            'puppetizable' => false,
            'screenname' => $comment['comment_author'],
            'url' => $comment['comment_author_url']
        );
    }

}
// }}}

class SmccSdkDb { // {{{

    public function __construct() {
    }

    public function get_comments_since($last_comment_id = null) {
        $last_comment_id = empty($last_comment_id) ? 0 : $last_comment_id;
        $sql = "SELECT * FROM {$this->table_comments()} as t LEFT JOIN {$this->table_users()} as u ON t.user_id = u.ID WHERE (t.comment_approved='0' or t.comment_approved='1') AND t.comment_id > %d ORDER BY t.comment_id DESC";
        return $this->find($sql, $last_comment_id);
    }

    public function get_comment($id) {
        $sql = "SELECT * FROM {$this->table_comments()} WHERE comment_id = %d";
        return $this->find_first($sql, $id);
    }

    public function delete_comment($id) {
        $sql = "DELETE FROM {$this->table_comments()} WHERE comment_id = %d";
        return $this->query($sql, $id);
    }

    public function comments_create($data) {
        $id = wp_insert_comment($data);
        return $this->get_comment($id);
    }

    public function get_user($id) {
        $sql = "SELECT * FROM {$this->table_users()} WHERE ID = %d";
        return $this->find_first($sql, $id);
    }

    public function get_comment_user($email) {
        $sql = "SELECT * FROM {$this->table_comments()} WHERE comment_author_email = %s ORDER BY comment_ID DESC";
        return $this->find_first($sql, $email);
    }

    public function get_post($id) {
        $sql = "SELECT * FROM {$this->table_posts()} WHERE id = %d";
        return $this->find_first($sql, $id);
    }

    public function posts_create($message) {
        $data = array(
            'post_author' => $message['author_id'],
            'post_content' => $message['body'],
            'post_date' => $message['created_at'],
            'post_status' => 'publish',
            'post_title' => $message['title'],
            'post_type' => 'post'
        );

        $id = wp_insert_post($data);
        return $this->get_post($id);
    }

    public function posts_delete($id) {
        return wp_delete_post($id, true);
    }

    // Helpers {{{

    protected function find($query, $values = array()) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare($query, $values), ARRAY_A);
    }

    protected function find_first() {
        $args = func_get_args();
        $result = call_user_func_array(array($this, 'find'), $args);
        return empty($result) ? null : $result[0];
    }

    protected function query($query, $values = array()) {
        global $wpdb;
        $result = $wpdb->query($wpdb->prepare($query, $values));
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

    // }}}

}
// }}}

