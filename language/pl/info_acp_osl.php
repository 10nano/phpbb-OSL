<?php

if (!defined('IN_PHPBB'))
{
    exit;
}

$lang = array_merge($lang ?? [], [
    'ACP_TENNANO_OSL' => 'OSL',
    'ACP_TENNANO_OSL_CLOCK' => 'Zegar testowy OSL',
    'ACP_TENNANO_OSL_CLOCK_EXPLAIN' => 'Symulacja czasu służy wyłącznie do sprawdzania terminów zgłoszeń OSL. Nie zmienia zegara Windows, dat postów ani działania innych rozszerzeń phpBB. Dostępna jest tylko dla żądań z localhost (127.0.0.1 / ::1).',
    'ACP_TENNANO_OSL_CLOCK_SETTINGS' => 'Ustawienia Debug',
    'ACP_TENNANO_OSL_CLOCK_ENABLE' => 'Włącz Debug',
    'ACP_TENNANO_OSL_CLOCK_ENABLE_EXPLAIN' => 'Debug włącza lokalną symulację czasu OSL. Po wyłączeniu używany jest rzeczywisty czas, a ostatnia data testowa pozostaje zapisana.',
    'ACP_TENNANO_OSL_CLOCK_DATE' => 'Data testowa',
    'ACP_TENNANO_OSL_CLOCK_TIME' => 'Godzina testowa (Europe/Warsaw)',
    'ACP_TENNANO_OSL_CLOCK_NOW' => 'Aktualny rzeczywisty czas',
    'ACP_TENNANO_OSL_CLOCK_ON' => 'Symulacja jest włączona',
    'ACP_TENNANO_OSL_CLOCK_ACTIVE' => 'Lokalne formularze i walidacja terminów zgłoszeń OSL korzystają z testowej daty.',
    'ACP_TENNANO_OSL_CLOCK_NOT_LOCAL' => 'Żądanie spoza localhost',
    'ACP_TENNANO_OSL_CLOCK_NOT_LOCAL_EXPLAIN' => 'Możesz zapisać ustawienia, ale symulowany czas nie będzie używany, dopóki nie wejdziesz na forum z localhost (127.0.0.1 / ::1).',
    'ACP_TENNANO_OSL_CLOCK_INVALID' => 'Wprowadź prawidłową datę i godzinę (czas polski).',
    'ACP_TENNANO_OSL_CLOCK_SAVED' => 'Ustawienia zegara testowego zostały zapisane.',
]);
