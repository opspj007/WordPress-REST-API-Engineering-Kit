<?php

declare(strict_types=1);

namespace RAEK;

/**
 * Minimal PSR-4 autoloader so the plugin runs without `composer install`.
 */
final class Autoloader
{
    public static function register(string $base_dir): void
    {
        spl_autoload_register(static function (string $class) use ($base_dir): void {
            $prefix = 'RAEK\\';
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }
            $file = $base_dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_readable($file)) {
                require $file;
            }
        });
    }
}
