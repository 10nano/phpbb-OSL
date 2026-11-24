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
                    // Default on preserves the visibility of existing instructions.
                    'reply_instructions_enabled' => ['USINT', 1],
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
