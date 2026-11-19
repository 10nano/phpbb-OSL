<?php

namespace tennano\osl\migrations\v10x;

/** Add an optional grace period for submitting results from the previous round. */
class m4_extension_days extends \phpbb\db\migration\migration
{
    public static function depends_on()
    {
        return ['\\tennano\\osl\\migrations\\v10x\\m3_inner_tens'];
    }

    public function update_schema()
    {
        return [
            'add_columns' => [
                $this->table_prefix . 'osl_leagues' => [
                    'extension_days' => ['USINT', 0],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_columns' => [
                $this->table_prefix . 'osl_leagues' => ['extension_days'],
            ],
        ];
    }
}
