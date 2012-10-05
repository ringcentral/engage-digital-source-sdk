<?php
/*
Plugin Name: Dimelo SMCC SDK
Plugin URI: https://github.com/dimelo/sdk
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
 *     http://www.apache.org/licenses/LICENSE-2.0
 * 
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

define('SMCC_SDK_ACCESS_TOKEN', 'EilW295myMpycBTQkaxeyEMhccBGs9yII4MgV4kwb6CTgcCUpOdOsUj5m1wiWZ7');

// Handle the request if it is SDK related, do nothing otherwise.
add_action( 'init', array(new SmccSdkApi, 'run') );

class SmccSdkApi { // {{{

    protected $request_body = null;
    protected $decoded_request_body = null;

    protected $implementation = array(
        'objects' => array(
            'messages' => array('create', 'list', 'show', 'delete', 'publish', 'unpublish'),
            'threads' => array('create', 'list', 'show', 'delete', 'publish', 'unpublish')
        ),
        'options' => array('messages.no_title')
    );

    public function __construct() {
        $this->db = new SmccSdkDb();
        $this->format = new SmccSdkFormat();
    }

    public function run() {
        if (!$this->is_sdk_request()) {
            return;
        }

        date_default_timezone_set(get_option('timezone_string'));

        $this->validate_request();

        $action = $this->get_action();
        list($object, $verb) = explode('.', $action);
        if ($action == 'implementation.info' ||
            (array_key_exists($object, $this->implementation['objects']) &&
                in_array($verb, $this->implementation['objects'][$object]))) {
            $method = str_replace('.', '_', $action);
            $this->$method();
        } else {
            $this->respond_error('Invalid action');
        }
    }

    public function get_action() {
        $body = $this->get_body();
        return $body['action'];
    }

    public function get_params() {
        $body = $this->get_body();
        return $body['params'];
    }

    public function get_body() {
        if ($this->decoded_request_body === null) {
            $this->decoded_request_body = json_decode($this->get_raw_body(), true);
        }
        return $this->decoded_request_body;
    }

    public function get_raw_body() {
        if ($this->request_body === null) {
            $this->request_body = file_get_contents('php://input');
        }
        return $this->request_body;
    }

    public function is_sdk_request() {
        return !empty($_GET['smcc_sdk']);
    }

    public function validate_request() {
        $signature = @$_SERVER['HTTP_X_SMCC_SIGNATURE'];
        if ($this->signature($this->get_raw_body()) != $signature) {
            $this->respond_error('Invalid signature');
        }
    }

    public function signature($text) {
        $signature = hash_hmac('sha512', $text, SMCC_SDK_ACCESS_TOKEN, $raw = true);
        return str_replace("\n", '', base64_encode($signature));
    }

    public function respond($object) {
        $body = json_encode($object);
        header('Content-type: application/json');
        header('X-SMCC-SIGNATURE: ' . $this->signature($body));
        echo $body;
        exit;
    }

    public function respond_error($message) {
        header('Status: 400 Bad Request');
        $this->respond($message);
    }

    public function implementation_info() {
        $this->respond($this->implementation);
    }

    public function messages_create() {
        $params = $this->get_params();

        $data = array(
            'comment_post_ID' => $params['thread_id'],
            'comment_content' => $params['body'],
            'comment_parent' => $params['in_reply_to_id'],
            'comment_date' => SmccSdkFormat::parse_time($params['created_at']),
            'comment_approved' => 1
        );

        $user = $this->db->get_user($params['author_id']);
        if (empty($user)) {
            $user = $this->db->get_comment_user($params['author_id']);
            if (empty($user)) {
                $this->respond_error('Could not create the comment');
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
            $this->respond_error('Could not create the comment');
        } else {
            $this->respond($this->format->message($comment, $formatted_user));
        }
    }

    public function messages_delete() {
        $params = $this->get_params();

        $comment = $this->db->get_comment($params['id']);
        $response = $this->db->delete_comment($params['id']);
        if ($response) {
            $this->respond($this->format->message(comment));
        } else {
            $this->respond_error('Could not delete content');
        }
    }

    public function messages_list() {
        $params = $this->get_params();

        $messages = array();
        $last_id = $params['since_id'];
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
        $this->respond($messages);
    }

    public function messages_show() {
        $params = $this->get_params();

        $comment = $this->db->get_comment($params['id']);
        if (!empty($comment['user_id'])) {
            $user = $this->format->user($comment);
        } else {
            $user = $this->format->comment_user($comment);
        }
        if ($user) {
            $this->respond($this->format->message($comment, $user));
        } else {
            $this->respond_error('Invalid user');
        }
    }

    public function messages_publish() {
        $params = $this->get_params();

        $res = wp_set_comment_status($params['id'], '1');

        if ($res) {
            $this->messages_show();
        } else {
            $this->respond_error('Could not publish message');
        }
    }

    public function messages_unpublish() {
        $params = $this->get_params();

        $res = wp_set_comment_status($params['id'], '0');

        if ($res) {
            $this->messages_show();
        } else {
            $this->respond_error('Could not unpublish message');
        }
    }

    public function threads_create() {
        $params = $this->get_params();
        $params['created_at'] = SmccSdkFormat::parse_time($params['created_at']);
        $post = $this->db->posts_create($params);
        $user = $this->db->get_user($post['post_author']);

        if ($post) {
            $this->respond($this->format->thread($post, $this->format->user($user)));
        } else {
            $this->respond_error('Could not create post');
        }
    }

    public function threads_list() {
        $params = $this->get_params();

        $posts = array();
        $last_id = $params['since_id'];
        foreach ($this->db->get_posts_since($last_id) as $post) {
            $user = $this->db->get_user($post['post_author']);
            $posts[] = $this->format->thread($post, $this->format->user($user));
        }
        $this->respond($posts);
    }


    public function threads_delete() {
        $params = $this->get_params();

        $response = $this->db->posts_delete($params['id']);
        $this->respond($response ? true : false);
    }

    public function threads_show() {
        $params = $this->get_params();

        $post = $this->db->get_post($params['id']);
        $user = $this->db->get_user($post['post_author']);

        $this->respond($this->format->thread($post, $this->format->user($user)));
    }

    public function threads_publish() {
        $params = $this->get_params();

        $res = wp_update_post(array('ID' => $params['id'], 'post_status' => 'publish'));

        if ($res) {
            $this->threads_show();
        } else {
            $thir->respond_error('Could not publish thread');
        }
    }

    public function threads_unpublish() {
        $params = $this->get_params();

        $res = wp_update_post(array('ID' => $params['id'], 'post_status' => 'pending'));

        if ($res) {
            $this->threads_show();
        } else {
            $this->respond_error('Could not publish thread');
        }
    }

}
// }}}

/**
 * Converts from Wordpress data to SMCC SDK data structures.
 */
class SmccSdkFormat { // {{{

    public function thread($post, $user) {
        return array(
            'actions' => array('delete', 'publish', 'unpublish'),
            'author' => $user,
            'body' => $post['post_content'],
            'categories' => array(),
            'created_at' => self::output_time($post['post_date']),
            'custom_actions' => array(),
            'custom_fields' => array(),
            'display_url' => get_permalink($post['ID']),
            'published' => $post['post_status'] == 'publish',
            'title' => $post['post_title'],
            'id' => $post['ID'],
            'updated_at' => self::output_time($post['post_modified'])
        );
    }

    public function message($comment, $user) {
        $attributes = array(
            'actions' => array('delete', 'publish', 'unpublish'),
            'author' => $user,
            'body' => $comment['comment_content'],
            'categories' => array(),
            'created_at' => self::output_time($comment['comment_date']),
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
            'created_at' => self::output_time($user['user_registered']),
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
            'created_at' => self::output_time($comment['comment_date']),
            'email' => $comment['comment_author_email'],
            'id' => $comment['comment_author_email'],
            'ip' => $comment['comment_author_IP'],
            'puppetizable' => false,
            'screenname' => $comment['comment_author'],
            'url' => $comment['comment_author_url']
        );
    }

    public static function output_time($timestr) {
        $time = strtotime($timestr);
        return date(DATE_ISO8601, $time);
    }

    public static function parse_time($timestr) {
        $time = strtotime($timestr);
        return date('Y-m-d H:i:s', $time);
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

    public function get_posts_since($last_post_id = null) {
        $last_post_id = empty($last_post_id) ? 0 : $last_post_id;
        $sql = "SELECT * FROM {$this->table_posts()} as p WHERE (p.post_status='publish' or p.post_status='pending') AND p.ID > %d ORDER BY p.ID DESC";
        return $this->find($sql, $last_post_id);
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

