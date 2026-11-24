<?php

namespace tennano\osl\migrations\v10x;

/**
 * Keep reply instructions even when their display is disabled.
 * Existing nonempty instructions remain enabled after the upgrade.
 */
class m7_reply_instructions_enabled extends \phpbb\db\migration\migration
{
    public static function depends_on()
    {
        return ['\\tennano\\osl\\migrations\\v10x\\m6_reply_instructions'];
    }

    public function update_schema()
    {
        return [
            'add_columns' => [
                $this->table_prefix . 'osl_leagues' => [
                    // 2 = legacy league: infer visibility from its text.
                    // New/edited leagues save an explicit 0 or 1.
                    'reply_instructions_enabled' => ['USINT', 2],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_columns' => [
                $this->table_prefix . 'osl_leagues' => ['reply_instructions_enabled'],
            ],
        ];
    }
}
