<?php

namespace tennano\osl\controller;

/** Manage per-forum league creators without needing ACP access. */
class creator_controller
{
    protected $permissions;
    protected $helper;
    protected $template;
    protected $request;
    protected $user;
    protected $language;
    protected $db;
    protected $phpbb_root_path;
    protected $php_ext;

    public function __construct(
        \tennano\osl\service\creator_permissions $permissions,
        \phpbb\controller\helper $helper,
        \phpbb\template\template $template,
        \phpbb\request\request $request,
        \phpbb\user $user,
        \phpbb\language\language $language,
        \phpbb\db\driver\driver_interface $db,
        string $phpbb_root_path,
        string $php_ext
    ) {
        $this->permissions = $permissions;
        $this->helper = $helper;
        $this->template = $template;
        $this->request = $request;
        $this->user = $user;
        $this->language = $language;
        $this->db = $db;
        $this->phpbb_root_path = $phpbb_root_path;
        $this->php_ext = $php_ext;
    }

    protected function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function handle($forum_id)
    {
        $forum_id = (int) $forum_id;
        $result = $this->db->sql_query('SELECT forum_id, forum_name, forum_type FROM ' . FORUMS_TABLE . ' WHERE forum_id = ' . $forum_id);
        $forum = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        if (!$forum || (int) $forum['forum_type'] !== FORUM_POST)
        {
            trigger_error('NO_FORUM');
        }
        if (!$this->permissions->can_manage($forum_id))
        {
            trigger_error('NOT_AUTHORISED');
        }

        $errors = [];
        $success = '';
        $username = $this->request->variable('osl_username', '', true);
        $url = $this->helper->route('tennano_osl_manage_creators', ['forum_id' => $forum_id]);

        add_form_key('tennano_osl_creators');
        if ($this->request->is_set_post('osl_action'))
        {
            if (!check_form_key('tennano_osl_creators'))
            {
                $errors[] = $this->language->lang('FORM_INVALID');
            }
            else
            {
                $action = $this->request->variable('osl_action', '');
                if ($action === 'grant')
                {
                    $name = trim($username);
                    if ($name === '')
                    {
                        $errors[] = $this->language->lang('OSL_CREATORS_NO_USERNAME');
                    }
                    else
                    {
                        $sql = 'SELECT user_id, user_type FROM ' . USERS_TABLE .
                            " WHERE username_clean = '" . $this->db->sql_escape(utf8_clean_string($name)) . "'";
                        $result = $this->db->sql_query($sql);
                        $user = $this->db->sql_fetchrow($result);
                        $this->db->sql_freeresult($result);
                        if (!$user || !in_array((int) $user['user_type'], [USER_NORMAL, USER_FOUNDER], true))
                        {
                            $errors[] = $this->language->lang('OSL_CREATORS_UNKNOWN_USER');
                        }
                        else
                        {
                            $this->permissions->grant($forum_id, (int) $user['user_id']);
                            $success = $this->language->lang('OSL_CREATORS_GRANTED');
                            $username = '';
                        }
                    }
                }
                elseif ($action === 'revoke')
                {
                    $user_id = (int) $this->request->variable('osl_user_id', 0);
                    if ($user_id > ANONYMOUS && $this->permissions->has_assignment($forum_id, $user_id))
                    {
                        $this->permissions->revoke($forum_id, $user_id);
                        $success = $this->language->lang('OSL_CREATORS_REVOKED');
                    }
                    else
                    {
                        $errors[] = $this->language->lang('OSL_CREATORS_NOT_ASSIGNED');
                    }
                }
            }
        }

        foreach ($this->permissions->get_assigned_users($forum_id) as $row)
        {
            $this->template->assign_block_vars('osl_creators', [
                'USER_ID' => (int) $row['user_id'],
                'USERNAME' => get_username_string('full', (int) $row['user_id'], $row['username'], $row['user_colour']),
            ]);
        }

        foreach ($errors as $error)
        {
            $this->template->assign_block_vars('osl_errors', ['TEXT' => $this->e($error)]);
        }

        $this->template->assign_vars([
            'OSL_CREATORS_FORUM' => $this->e((string) $forum['forum_name']),
            'U_OSL_CREATORS_FORM' => $url,
            'U_OSL_CREATORS_BACK' => append_sid($this->phpbb_root_path . 'viewforum.' . $this->php_ext, 'f=' . $forum_id),
            'OSL_CREATORS_USERNAME' => $this->e($username),
            'OSL_CREATORS_SUCCESS' => $this->e($success),
        ]);

        return $this->helper->render('@tennano_osl/osl_manage_creators.html', $this->language->lang('OSL_CREATORS_TITLE'));
    }
}
