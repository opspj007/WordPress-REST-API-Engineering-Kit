<?php
/** Unit tests run without WordPress: only pure classes (Jwt, Signer) are exercised. */

declare(strict_types=1);

require dirname(__DIR__) . '/includes/Autoloader.php';
RAEK\Autoloader::register(dirname(__DIR__) . '/includes/');

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_readable($autoload)) {
    require $autoload;
}
