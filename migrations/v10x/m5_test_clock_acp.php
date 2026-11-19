<?php

namespace tennano\osl\migrations\v10x;

/** Register the optional test-clock ACP page without changing league data. */
class m5_test_clock_acp extends \phpbb\db\migration\migration
{
    public static function depends_on()
    {
        return ['\tennano\osl\migrations\v10x\m4_extension_days'];
    }

    public function update_data()
    {
        return [
            ['config.add', ['tennano_osl_test_clock_enabled', 0]],
            ['config.add', ['tennano_osl_test_clock_datetime', '2026-12-05 12:00']],
            ['module.add', ['acp', 'ACP_CAT_DOT_MODS', 'ACP_TENNANO_OSL']],
            ['module.add', [
                'acp',
                'ACP_TENNANO_OSL',
                [
                    'module_basename' => '\tennano\osl\acp\test_clock_module',
                    'modes' => ['settings'],
                ],
            ]],
        ];
    }
}
