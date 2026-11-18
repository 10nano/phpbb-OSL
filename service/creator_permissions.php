<?php

namespace tennano\osl\service;

/** Permission decisions for league creation in a specific forum. */
class creator_permissions
{
    protected $db;
    protected $auth;
    protected $user;
    protected $table;

    public function __construct(
        \phpbb\db\driver\driver_interface $db,
        \phpbb\auth\auth $auth,
        \phpbb\user $user,
        string $table
    ) {
        $this->db = $db;
        $this->auth = $auth;
        $this->user = $user;
        $this->table = $table;
    }

    public function can_manage(int $forum_id): bool
    {
        return $forum_id > 0 && (int) $this->user->data['user_id'] > ANONYMOUS
            && ($this->auth->acl_get('a_') || $this->auth->acl_get('m_', $forum_id));
    }

    public function can_create(int $forum_id): bool
    {
        $user_id = (int) $this->user->data['user_id'];
        if ($forum_id <= 0 || $user_id <= ANONYMOUS || !$this->auth->acl_get('f_post', $forum_id))
        {
            return false;
        }

        // Deliberately NO administrator, moderator or legacy global ACL bypass.
        // Every creator, including admins and moderators, needs an assignment
        // in the specific forum. Standard f_post permission is also required.
        return $this->has_assignment($forum_id, $user_id);
    }

    public function has_assignment(int $forum_id, int $user_id): bool
    {
        if ($forum_id <= 0 || $user_id <= ANONYMOUS)
        {
            return false;
        }
        $sql = 'SELECT assignment_id FROM ' . $this->table .
            ' WHERE forum_id = ' . $forum_id . ' AND user_id = ' . $user_id;
        $result = $this->db->sql_query_limit($sql, 1);
        $found = (bool) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        return $found;
    }

    public function grant(int $forum_id, int $user_id): void
    {
        if ($this->has_assignment($forum_id, $user_id))
        {
            return;
        }
        $values = [
            'forum_id' => $forum_id,
            'user_id' => $user_id,
            'granted_by' => (int) $this->user->data['user_id'],
            'granted_at' => time(),
        ];
        $this->db->sql_query('INSERT INTO ' . $this->table . ' ' . $this->db->sql_build_array('INSERT', $values));
    }

    public function revoke(int $forum_id, int $user_id): void
    {
        $this->db->sql_query('DELETE FROM ' . $this->table .
            ' WHERE forum_id = ' . $forum_id . ' AND user_id = ' . $user_id);
    }

    public function get_assigned_users(int $forum_id): array
    {
        $sql = 'SELECT u.user_id, u.username, u.user_colour, p.granted_at
            FROM ' . $this->table . ' p
            JOIN ' . USERS_TABLE . ' u ON u.user_id = p.user_id
            WHERE p.forum_id = ' . $forum_id . '
            ORDER BY u.username_clean ASC';
        $result = $this->db->sql_query($sql);
        $users = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $users[] = $row;
        }
        $this->db->sql_freeresult($result);
        return $users;
    }
}
