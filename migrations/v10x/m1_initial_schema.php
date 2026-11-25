<?php

namespace tennano\osl\migrations\v10x;

/**
 * Complete schema for a fresh OSL installation.
 *
 * Consolidated development migrations; this is not an upgrade path
 * for databases from previous OSL versions.
 */
class m1_initial_schema extends \phpbb\db\migration\migration
{
    public static function depends_on()
    {
        return ['\\phpbb\\db\\migration\\data\\v330\\v330'];
    }

    public function update_schema()
    {
        return [
            'add_tables' => [
                $this->table_prefix . 'osl_leagues' => [
                    'COLUMNS' => [
                        'league_id' => ['UINT', null, 'auto_increment'],
                        'topic_id' => ['UINT', 0],
                        'league_author_id' => ['UINT', 0],
                        'title' => ['VCHAR:255', ''],
                        'short_name' => ['VCHAR:50', ''],
                        'start_date' => ['TIMESTAMP', 0],
                        'duration_months' => ['USINT', 12],
                        'best_rounds_count' => ['USINT', 12],
                        'extension_days' => ['USINT', 0],
                        'groups_enabled' => ['TEXT', ''],
                        'reply_instructions' => ['TEXT', ''],
                        'reply_instructions_enabled' => ['USINT', 2],
                    ],
                    'PRIMARY_KEY' => 'league_id',
                    'KEYS' => ['topic_id' => ['UNIQUE', 'topic_id']],
                ],
                $this->table_prefix . 'osl_competitions' => [
                    'COLUMNS' => [
                        'competition_id' => ['UINT', null, 'auto_increment'],
                        'league_id' => ['UINT', 0],
                        'title' => ['VCHAR:255', ''],
                        'sort_order' => ['USINT', 0],
                        'fields_schema' => ['TEXT', ''],
                    ],
                    'PRIMARY_KEY' => 'competition_id',
                    'KEYS' => ['league_id' => ['INDEX', 'league_id']],
                ],
                $this->table_prefix . 'osl_results' => [
                    'COLUMNS' => [
                        'result_id' => ['UINT', null, 'auto_increment'],
                        'league_id' => ['UINT', 0],
                        'competition_id' => ['UINT', 0],
                        'post_id' => ['UINT', 0],
                        'user_id' => ['UINT', 0],
                        'shooter_group' => ['VCHAR:100', ''],
                        'round_date' => ['TIMESTAMP', 0],
                        'round_index' => ['USINT', 0],
                        'scores_data' => ['TEXT', ''],
                        'x_scores_data' => ['TEXT', ''],
                        'total_score' => ['DECIMAL:10,2', 0],
                        'total_x' => ['UINT', 0],
                    ],
                    'PRIMARY_KEY' => 'result_id',
                    'KEYS' => [
                        'post_comp' => ['UNIQUE', ['post_id', 'competition_id']],
                        'user_period' => ['UNIQUE', ['league_id', 'competition_id', 'user_id', 'round_index']],
                        'league_id' => ['INDEX', 'league_id'],
                        'competition_id' => ['INDEX', 'competition_id'],
                    ],
                ],
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

    public function revert_schema()
    {
        return [
            'drop_tables' => [
                $this->table_prefix . 'osl_forum_creators',
                $this->table_prefix . 'osl_results',
                $this->table_prefix . 'osl_competitions',
                $this->table_prefix . 'osl_leagues',
            ],
        ];
    }
}
