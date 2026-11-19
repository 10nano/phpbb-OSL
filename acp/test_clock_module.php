<?php

namespace tennano\osl\acp;

use tennano\osl\service\test_clock;

class test_clock_module
{
    public $u_action;
    public $tpl_name;
    public $page_title;

    public function main($id, $mode)
    {
        global $auth, $config, $language, $request, $template;

        if (!$auth->acl_get('a_board'))
        {
            trigger_error('NOT_AUTHORISED');
        }

        $language->add_lang('info_acp_osl', 'tennano/osl');
        $this->tpl_name = 'acp_osl_test_clock';
        $this->page_title = $language->lang('ACP_TENNANO_OSL_CLOCK');

        add_form_key('tennano_osl_test_clock');

        if ($request->is_set_post('submit'))
        {
            if (!check_form_key('tennano_osl_test_clock'))
            {
                trigger_error('FORM_INVALID');
            }

            $enabled = (int) $request->variable('osl_test_clock_enabled', 0) === 1;
            $date = trim((string) $request->variable('osl_test_clock_date', ''));
            $time = trim((string) $request->variable('osl_test_clock_time', ''));
            $parsed = test_clock::parse_local_datetime($date . ' ' . $time);

            if ($parsed === null)
            {
                trigger_error($language->lang('ACP_TENNANO_OSL_CLOCK_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
            }

            // Save the date first, then switch the clock on/off.
            $config->set(test_clock::CONFIG_DATETIME, $parsed->format('Y-m-d H:i'));
            $config->set(test_clock::CONFIG_ENABLED, $enabled ? 1 : 0);
            trigger_error($language->lang('ACP_TENNANO_OSL_CLOCK_SAVED') . adm_back_link($this->u_action));
        }

        $stored = (string) ($config[test_clock::CONFIG_DATETIME] ?? '2026-12-05 12:00');
        if (test_clock::parse_local_datetime($stored) === null)
        {
            $stored = '2026-12-05 12:00';
        }
        $enabled = !empty($config[test_clock::CONFIG_ENABLED]);
        $template->assign_vars([
            'OSL_TEST_CLOCK_ENABLED' => $enabled,
            'OSL_TEST_CLOCK_DATE' => substr($stored, 0, 10),
            'OSL_TEST_CLOCK_TIME' => substr($stored, 11, 5),
            'OSL_TEST_CLOCK_LOCAL_REQUEST' => test_clock::is_local_request($request),
            'OSL_TEST_CLOCK_NOW' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw')))->format('Y-m-d H:i'),
            'U_ACTION' => $this->u_action,
        ]);
    }
}
