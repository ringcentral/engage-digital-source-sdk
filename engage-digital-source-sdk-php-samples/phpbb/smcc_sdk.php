<?php

// Change this to a random string (between 32-64 characters)
define('SMCC_SDK_ACCESS_TOKEN', 'MtbekP4DseWrXX0xtaOcrEhrU2tjTV6F84O8nw0GqC79SIFESjjZ6rqsOec24dw');

// Don't change anything else below
///////////////////////////////////////////////////////////////////////

// Needed so the other includes work
define('IN_PHPBB', true);
$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : './';
$phpEx = substr(strrchr(__FILE__, '.'), 1);
include($phpbb_root_path . 'common.' . $phpEx);
include($phpbb_root_path . 'includes/functions_posting.' . $phpEx);
include($phpbb_root_path . 'includes/functions_admin.' . $phpEx);
include($phpbb_root_path . 'includes/functions_privmsgs.' . $phpEx);

$auth = new smcc_sdk_auth;
$user->data = array(
    'is_registered' => true,
    'user_lastmark' => 0
);
$user->ip = '';

$sdk = new smcc_sdk;
$sdk->respond();

// Request handling {{{
class smcc_sdk
{
    protected $api = null;

    public function __construct()
    {
        $this->api = new smcc_sdk_api;
    }

    public function respond()
    {
        if (!$this->is_sdk_request())
        {
            exit;
        }
        $params = $this->get_params();
        $method = str_replace('.', '_', $params['action']);
        $this->api->$method($params, $this->get_body());
    }

    public function is_sdk_request()
    {
        $params = $this->get_params();

        $action = $params['action'];
        $token  = $params['access_token'];

        return !empty($token) && $token === SMCC_SDK_ACCESS_TOKEN && !empty($action) && in_array($action, $this->api->implemented_actions);
    }

    public function get_params()
    {
        $params = array();
        foreach ($_GET as $k => $v)
        {
            if (strpos($k, 'smcc_sdk_') === 0)
            {
                $key = str_replace('smcc_sdk_', '', $k);
                $params[$key] = $v;
            }
        }
        return $params;
    }

    public function get_body()
    {
        $body = file_get_contents('php://input');
        return empty($body) ? '' : json_decode($body, true);
    }
}
// }}}

// API actions implementations {{{
class smcc_sdk_api
{
    public $implemented_actions = array(
        'implementation.info',
        'messages.create', 'messages.destroy', 'messages.list', 'messages.show',
        'private_messages.create', 'private_messages.list', 'private_messages.show',
        'threads.destroy', 'threads.show',
        'users.show',
        'messages_warn' // custom
    );

    public $implemented_options = array('threads.no_content');

    protected $db = null;

    public function __construct()
    {
        $this->db = new smcc_sdk_db;
    }

    // Implementation {{{
    
    public function implementation_info()
    {
        $this->response_success(array(
            'actions' => $this->implemented_actions,
            'options' => $this->implemented_options
        ));
    }

    // }}}

    // Messages {{{

    // functions_posting.php #1974
    public function messages_create($params, $body)
    {
        global $user;

        $user->data['user_id'] = $body['author_id'];
        $poll = array();
        $topic = $this->db->get_topic($body['thread_id']);
        $subject = $body['title'];
        $data = array(
            'topic_title' => '',
            'poster_id' => $body['author_id'],
            'topic_id' => $body['thread_id'],
            'forum_id' => $topic['forum_id'],
            'icon_id' => 0,
            'enable_bbcode' => 1,
            'enable_smilies' => 1,
            'enable_urls' => 1,
            'enable_sig' => 1,
            'message' => $body['body'],
            'message_md5' => md5($body['body']),
            'attachment_data' => null,
            'bbcode_bitfield' => '',
            'bbcode_uid' => '',
            'post_edit_locked' => true,
            'force_approved_state' => true
        );

        submit_post(
            'reply', // mode
            $subject, // subject
            '', // username
            POST_NORMAL,
            $poll, // poll
            $data
        );

        $this->messages_show(array('id' => $data['post_id']), '');
    }

    public function messages_destroy($params, $body)
    {
        delete_posts('post_id', $params['id']);
        $this->response_success(true);
    }

    public function messages_list($params, $body)
    {
        $rows = array();
        foreach ($this->db->get_messages($params['since_id']) as $message)
        {
            $rows[] = $this->format_post($message);
        }
        $this->response_success($rows);
    }

    public function messages_show($params, $body)
    {
        $post = $this->db->find_post($params['id']);
        $this->response_success($this->format_post($post));
    }

    public function messages_warn($params, $body)
    {
        $this->db->add_message_warning($params['id'], $params['user_id']);
        $this->response_success($this->format_post($this->db->find_post($params['id'])));
    }

    // }}}

    // Private messages {{{

    // table privmsgs
    // functions_privmsgs #1300
    public function private_messages_create($params, $body)
    {
        global $user;

        $user->data['user_id'] = $body['author_id'];

        if (empty($body['in_reply_to_id']))
        {
            $initial_msg = array();
        }
        else
        {
            $initial_msg = $this->db->find_privmsg($body['in_reply_to_id']);
        }

        $subject = $body['title'];
        $data = array(
            'address_list' => array('u' => array($body['recipient_id'] => 'to')),
            'reply_from_root_level' => ($initial_msg && $initial_msg['root_level'] === 0),
            'reply_from_msg_id' => $body['in_reply_to_id'],
            'from_user_id' => $body['author_id'],
            'from_user_ip' => '',
            'enable_bbcode' => 1,
            'enable_smilies' => 1,
            'enable_urls' => 1,
            'enable_sig' => 1,
            'message' => $body['body'],
            'icon_id' => 0,
            'attachment_data' => null,
            'bbcode_bitfield' => '',
            'bbcode_uid' => ''
        );
        $put_in_outbox = true;

        submit_pm(
            $data['reply_from_msg_id'] ? 'reply' : 'post',
            $subject,
            $data, 
            $put_in_outbox
        );
        $this->private_messages_show(array('id' => $data['msg_id']), array());
    }

    public function private_messages_list($params, $body)
    {
        $rows = array();
        if (!empty($params['user_ids']) && is_array($params['user_ids']))
        {
            $ids = array();
            foreach ($params['user_ids'] as $id)
            {
                $ids[] = (int)$id;
            }

            foreach ($this->db->get_private_messages((int)$params['since_id'], $ids) as $message)
            {
                $rows[] = $this->format_privmsg($message);
            }
        }

        $this->response_success($rows);
    }

    public function private_messages_show($params, $body)
    {
        $msg = $this->db->find_privmsg($params['id']);
        $this->response_success($this->format_privmsg($msg));
    }

    // }}}

    // Threads {{{

    // functions_posting #1390
    public function threads_destroy($params, $body)
    {
        delete_topics(
            'range', // where_type
            array($params['id']) // where_ids
        );
        $this->response_success(true);
    }

    public function threads_show($params, $body)
    {
        $topic = $this->db->get_topic($params['id']);
        $thread = topic_to_sdk($topic);
        $thread['author'] = user_to_sdk($topic);

        $this->response_success($thread);
    }

    // }}}

    // Helpers {{{

    protected function response_success($payload)
    {
        exit(json_encode(array('success' => true, 'payload' => $payload)));
    }

    protected function response_error($message)
    {
        exit(json_encode(array('success' => false, 'errorMessage' => $message)));
    }

    protected function format_post($post)
    {
        $record = post_to_sdk($post);
        $record['author'] = user_to_sdk($post);

        return $record;
    }

    protected function format_privmsg($msg)
    {
        $to_parts = explode(':', $msg['to_address']);
        $recipient_id = str_replace('u_', '', $to_parts[0]); // I only use the first user

        $record = privmsg_to_sdk($msg);
        $record['author'] = user_to_sdk($msg);
        $record['recipient'] = user_to_sdk($this->db->find_user($recipient_id));

        return $record;
    }

    // }}}
}
// }}}

// DB helpers {{{
class smcc_sdk_db
{
    public function __construct()
    {
        $sql = 'SELECT p.*, u.*, t.*, f.* FROM ' . POSTS_TABLE . ' p ';
        $sql .= 'LEFT JOIN ' . USERS_TABLE . ' u ON p.poster_id=u.user_id ';
        $sql .= 'LEFT JOIN ' . TOPICS_TABLE . ' t ON p.topic_id = t.topic_id ';
        $sql .= 'LEFT JOIN ' . FORUMS_TABLE . ' f on t.forum_id = f.forum_id ';
        $this->post_sql = $sql;

        $sql = 'SELECT pm.*, u.* FROM ' . PRIVMSGS_TABLE . ' pm ';
        $sql .= 'LEFT JOIN ' . USERS_TABLE . ' u ON pm.author_id=u.user_id ';
        $this->privmsg_sql = $sql;
    }

    public function add_message_warning($post_id, $user_id)
    {
        $post = $this->find_post($post_id);

        $sql = 'SELECT warning_id FROM ' . WARNINGS_TABLE . ' w ';
        $sql .= 'WHERE w.user_id=' . $post['poster_id'] . ' AND w.post_id=' . $post_id;
        $warn = $this->find_row($sql);
        if (empty($warn))
        {
            $sql = 'INSERT INTO ' . WARNINGS_TABLE . ' (user_id, post_id, log_id) VALUES ';
            $sql .= '(' . $post['poster_id'] . ', ' . $post_id . ', 0)';
            $this->execute($sql);
        }
    }

    public function get_messages($since_id)
    {
        $sql = $this->post_sql;
        $sql .= 'WHERE p.post_id > ' . (int)$since_id . ' ';
        $sql .= 'ORDER BY p.post_id DESC';

        return $this->get_rows($sql);
    }

    // table phpbb_privmsgs
    public function get_private_messages($since_id, $user_ids)
    {
        $sql = 'SELECT pm.* FROM ' . PRIVMSGS_TO_TABLE . ' pm WHERE pm.msg_id > ' . $since_id . ' AND pm.user_id in (' . implode(',', $user_ids) . ')';
        $rows = $this->get_rows($sql);
        if (empty($rows))
        {
            return array();
        }

        $ids = array();
        foreach ($rows as $row)
        {
            $ids[] = $row['msg_id'];
        }

        $sql = $this->privmsg_sql;
        $sql .= 'WHERE pm.msg_id in (' . implode(',', array_unique($ids)) . ') ';
        $sql .= 'ORDER BY pm.msg_id DESC';

        return $this->get_rows($sql);
    }

    public function find_post($id)
    {
        $sql = $this->post_sql;
		$sql .= 'WHERE p.post_id=' . $id;
        return $this->find_row($sql);
    }

    public function find_privmsg($id)
    {
        $sql = $this->privmsg_sql;
        $sql .= 'WHERE pm.msg_id = ' . $id;
        return $this->find_row($sql);
    }

    public function find_user($id)
    {
		$sql = 'SELECT u.* FROM ' . USERS_TABLE . ' u WHERE user_id=' . $id;
        return $this->find_row($sql);
    }

    public function find_row($sql)
    {
        $sql .= ' LIMIT 1';
        $row = $this->get_rows($sql);
        return @$row[0];
    }

    public function get_topic($id)
    {
        $sql = 'SELECT t.*, f.*, u.* FROM ';
        $sql .= TOPICS_TABLE . ' t ';
        $sql .= 'LEFT JOIN ' . USERS_TABLE . ' u ON t.topic_poster = u.user_id ';
        $sql .= 'LEFT JOIN ' . FORUMS_TABLE . ' f ON t.forum_id = f.forum_id ';
        $sql .= 'WHERE t.topic_id = ' . (int)$id;

        return $this->find_row($sql);
    }

    public function get_rows($sql)
    {
        global $db;
		$result = $db->sql_query($sql);
        $rows = array();
		while ($row = $db->sql_fetchrow($result))
        {
            $rows[] = $row;
        }
        $db->sql_freeresult($result);
        return $rows;
    }

    public function execute($sql)
    {
        global $db;
        $db->sql_query($sql);
    }
}
// }}}

// Mappers {{{

// Message -> Post (has to be have been joined with forums (to get the forum as category))
// !!! will not have user filled in
function post_to_sdk($data) {
    return array(
        'body' => $data['post_text'],
        'categories' => array($data['forum_name']),
        'created_at' => ftime($data['post_time']),
        'custom_actions' => array(
            'messages_warn' => array('name' => 'Warn', 'params' => array())
        ),
        'custom_fields' => array(
            'post_checksum' => $data['post_checksum']
        ),
        'display_url' => '',
        'id' => $data['post_id'],
        'in_reply_to_id' => null,
        'ip' => $data['poster_ip'],
        'published' => true,
        'title' => $data['post_subject'],
        'thread_id' => $data['topic_id'],
        'updated_at' => empty($data['post_edit_time']) ? ftime($data['post_time']) : ftime($data['post_edit_time'])
    );
}

// Has to be joined with the first post of the thread (to have a body), and with forums (to get the forum_name for categories)
// !!! will not have user filled in
function topic_to_sdk($data) {
    return array(
        'categories' => array($data['forum_name']),
        'created_at' => ftime($data['topic_time']),
        'custom_actions' => array(),
        'custom_fields' => array(),
        'display_url' => '',
        'id' => $data['topic_id'],
        'ip' => null,
        'title' => $data['topic_title'],
        'updated_at' => ftime($data['topic_last_post_time'])
    );
}

// !!! will not have "author" and "recipient" filled in
function privmsg_to_sdk($data) {
    return array(
        'body' => $data['message_text'],
        'created_at' => ftime($data['message_time']),
        'custom_actions' => array(),
        'custom_fields' => array(),
        'id' => $data['msg_id'],
        'in_reply_to_id' => $data['root_level'],
        'ip' => $data['author_ip'],
        'title' => $data['message_subject'],
        'updated_at' => empty($data['message_edit_time']) ? ftime($data['message_time']) : ftime($data['message_edit_time'])
    );
}

function user_to_sdk($data) {
    return array(
        'avatar_url' => $data['user_avatar'],
        'created_at' => ftime($data['user_regdate']),
        'custom_fields' => array('signature' => $data['user_sig']),
        'email' => $data['user_email'],
        'firstname' => null,
        'home_phone' => null,
        'id' => $data['user_id'],
        'ip' => $data['user_ip'],
        'lastname' => null,
        'mobile_phone' => null,
        'notes' => null,
        'puppetizable' => true,
        'screenname' => $data['username'],
        'updated_at' => null
    );
}

function ftime($timestamp)
{
    return strftime('%Y-%m-%d %H:%M:%S', $timestamp);
}

// }}}

// Stubs {{{
/**
 * This is just a stub so that I can use functions like submit_post
 * without having to authenticate the user.
 */
class smcc_sdk_auth
{
    public function acl_get()
    {
        return true;
    }

    public function acl()
    {
    }
}
// }}}

