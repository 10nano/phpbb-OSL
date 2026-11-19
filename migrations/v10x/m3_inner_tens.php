<?php

namespace tennano\osl\migrations\v10x;

/** Add optional per-series inner tens to existing results without changing their scores. */
class m3_inner_tens extends \phpbb\db\migration\migration
{
    public static function depends_on()
    {
        return ['\\tennano\\osl\\migrations\\v10x\\m2_forum_creators'];
    }

    public function update_schema()
    {
        return [
            'add_columns' => [
                $this->table_prefix . 'osl_results' => [
                    'x_scores_data' => ['TEXT', ''],
                    'total_x' => ['UINT', 0],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_columns' => [
                $this->table_prefix . 'osl_results' => ['x_scores_data', 'total_x'],
            ],
        ];
    }
}
