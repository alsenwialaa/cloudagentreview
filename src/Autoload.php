<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant;

final class Autoload
{
    private const PREFIX = __NAMESPACE__ . '\\';

    public static function register(): void
    {
        spl_autoload_register(
            static function (string $class): void {
                if (!str_starts_with($class, self::PREFIX)) {
                    return;
                }

                $relative = substr($class, strlen(self::PREFIX));
                if ($relative === false || $relative === '') {
                    return;
                }

                $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
                if (is_file($file)) {
                    require_once $file;
                }
            }
        );
    }
}
