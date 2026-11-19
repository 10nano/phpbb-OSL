<?php

namespace tennano\osl\service;

/**
 * A local-only test clock. Does not change PHP, phpBB or system time.
 *
 * This deliberately has no service registration: it uses the request object
 * already injected into the existing listener, avoiding container changes.
 */
final class test_clock
{
    public const CONFIG_ENABLED = 'tennano_osl_test_clock_enabled';
    public const CONFIG_DATETIME = 'tennano_osl_test_clock_datetime';

    public static function parse_local_datetime(string $value): ?\DateTimeImmutable
    {
        $timezone = new \DateTimeZone('Europe/Warsaw');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, $timezone);

        if (!$date || $date->format('Y-m-d H:i') !== $value)
        {
            return null;
        }

        return $date;
    }

    public static function is_local_request(\phpbb\request\request $request): bool
    {
        // phpBB requires the request class instead of direct $_SERVER access.
        return in_array((string) $request->server('REMOTE_ADDR', ''), ['127.0.0.1', '::1'], true);
    }

    public static function simulated_time(\phpbb\request\request $request): ?int
    {
        // phpBB's global config is available when the forum processes events.
        // Keeping it here avoids changing the working listener constructor.
        global $config;

        if (!isset($config) || empty($config[self::CONFIG_ENABLED]) || !self::is_local_request($request))
        {
            return null;
        }

        $date = self::parse_local_datetime((string) ($config[self::CONFIG_DATETIME] ?? ''));
        return $date ? $date->getTimestamp() : null;
    }

    public static function now(\phpbb\request\request $request): int
    {
        $simulated = self::simulated_time($request);
        return $simulated !== null ? $simulated : time();
    }
}
