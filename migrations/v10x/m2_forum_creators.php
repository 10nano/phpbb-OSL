<?php

namespace tennano\osl\migrations\v10x;

/** Incremental upgrade; does not alter existing leagues, competitions or results. */
class m2_forum_creators extends \phpbb\db\migration\migration
{
    public static function depends_on()
    {
        return ['\tennano\osl\migrations\v10x\m1_initial_schema'];
    }

    public function update_schema()
    {
        return [
            'add_tables' => [
                $this->table_prefix . 'osl_forum_creators' => [
                    'COLUMNS' => [
                        'assignment_id' => ['UINT', null, 'auto_increment'],
                        'forum_id' => ['UINT', 0],
                        'user_id' => ['UINT', 0],
                        'granted_by' => ['UINT', 0],
                        'granted_at' => ['TIMESTAMP', 0],
                    ],
                    'PRIMARY_KEY' => 'assignment_id',
                    'KEYS' => [
                        'forum_user' => ['UNIQUE', ['forum_id', 'user_id']],
                    ],
                ],
            ],
        ];
    }

    public function update_data()
    {
        // The previous OSL release granted a global permission to admins
        // and global moderators. Remove that obsolete option and its grants.
        // The new per-forum assignments table starts completely empty.
        return [
            ['permission.remove', ['u_create_shooting_league']],
        ];
    }

    public function revert_schema()
    {
        return ['drop_tables' => [$this->table_prefix . 'osl_forum_creators']];
    }
}
