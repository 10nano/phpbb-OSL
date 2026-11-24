<?php

namespace tennano\osl\migrations\v10x;

/** Store optional, per-league instructions for the result reply editor. */
class m6_reply_instructions extends \phpbb\db\migration\migration
{
    public static function depends_on()
    {
        return ['\\tennano\\osl\\migrations\\v10x\\m5_test_clock_acp'];
    }

    public function update_schema()
    {
        return [
            'add_columns' => [
                $this->table_prefix . 'osl_leagues' => [
                    'reply_instructions' => ['TEXT', ''],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_columns' => [
                $this->table_prefix . 'osl_leagues' => ['reply_instructions'],
            ],
        ];
    }
}
