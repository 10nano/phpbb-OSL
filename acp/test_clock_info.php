<?php

namespace tennano\osl\acp;

class test_clock_info
{
    public function module()
    {
        return [
            'filename' => '\tennano\osl\acp\test_clock_module',
            'title' => 'ACP_TENNANO_OSL',
            'modes' => [
                'settings' => [
                    'title' => 'ACP_TENNANO_OSL_CLOCK',
                    'auth' => 'ext_tennano/osl && acl_a_board',
                    'cat' => ['ACP_TENNANO_OSL'],
                ],
            ],
        ];
    }
}
