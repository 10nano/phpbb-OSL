<?php

if (!defined('IN_PHPBB'))
{
    exit;
}

$lang = array_merge($lang ?? [], [
    'ACP_TENNANO_OSL' => 'OSL',
    'ACP_TENNANO_OSL_CLOCK' => 'OSL test clock',
    'ACP_TENNANO_OSL_CLOCK_EXPLAIN' => 'Time simulation is only for testing OSL submission deadlines. It does not change Windows time, phpBB post times or any other extensions. It applies only to localhost requests (127.0.0.1 / ::1).',
    'ACP_TENNANO_OSL_CLOCK_SETTINGS' => 'Simulation settings',
    'ACP_TENNANO_OSL_CLOCK_ENABLE' => 'Enable time simulation',
    'ACP_TENNANO_OSL_CLOCK_ENABLE_EXPLAIN' => 'Turn off to restore real time for OSL immediately.',
    'ACP_TENNANO_OSL_CLOCK_DATE' => 'Test date',
    'ACP_TENNANO_OSL_CLOCK_TIME' => 'Test time (Europe/Warsaw)',
    'ACP_TENNANO_OSL_CLOCK_NOW' => 'Current real time',
    'ACP_TENNANO_OSL_CLOCK_ON' => 'Simulation is enabled',
    'ACP_TENNANO_OSL_CLOCK_ACTIVE' => 'Local OSL submission forms and deadline validation use the simulated time.',
    'ACP_TENNANO_OSL_CLOCK_NOT_LOCAL' => 'Non-local request',
    'ACP_TENNANO_OSL_CLOCK_NOT_LOCAL_EXPLAIN' => 'You can save the settings, but time simulation only applies while using localhost (127.0.0.1 / ::1).',
    'ACP_TENNANO_OSL_CLOCK_INVALID' => 'Enter a valid date and time (Europe/Warsaw).',
    'ACP_TENNANO_OSL_CLOCK_SAVED' => 'Test clock settings have been saved.',
]);
