<?php
/**
 * Plugin Name:       REST API Engineering Kit
 * Plugin URI:        https://github.com/your-handle/WordPress-REST-API-Engineering-Kit
 * Description:       Reference implementation of a production-grade WordPress REST API: versioned namespaces, JWT + Application Password auth, rate limiting, RFC 9457 errors, RFC 8288 pagination, signed webhooks and generated OpenAPI docs.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Your Name
 * License:           GPL-2.0-or-later
 * Text Domain:       rest-api-engineering-kit
 */

declare(strict_types=1);

namespace RAEK;

defined('ABSPATH') || exit;

const VERSION = '1.0.0';
const FILE = __FILE__;

require_once __DIR__ . '/includes/Autoloader.php';
Autoloader::register(__DIR__ . '/includes/');

register_activation_hook(__FILE__, [Installer::class, 'activate']);
add_action('plugins_loaded', static fn() => Plugin::instance()->boot());
