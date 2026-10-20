<?php
/** Boots the WordPress test suite (provided by wp-env's tests-cli container). */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $root . '/vendor/yoast/phpunit-polyfills');
$tests_dir = getenv('WP_TESTS_DIR') ?: '/wordpress-phpunit';

require $tests_dir . '/includes/functions.php';
tests_add_filter('muplugins_loaded', static function () use ($root): void {
    require $root . '/rest-api-engineering-kit.php';
});
require $tests_dir . '/includes/bootstrap.php';
