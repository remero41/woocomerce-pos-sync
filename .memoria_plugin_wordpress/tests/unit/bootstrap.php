<?php
declare(strict_types=1);
/**
 * Bootstrap para tests PHPUnit unitarios.
 *
 * Usa Brain\Monkey (via composer dev-deps) para mockear funciones WP.
 * Si no está instalado, cae back al stub mínimo de wp-stubs.php para que
 * los tests unitarios puros (sin WP) funcionen.
 */

define('WP_DEBUG', true);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wp/');
}

$autoload = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    // Fallback: stubs mínimos de wp-stubs.php
    require_once __DIR__ . '/../wp-stubs.php';
}

// Cargar Brain\Monkey si está disponible
if (class_exists(\Brain\Monkey::class)) {
    \Brain\Monkey\setUp();
    register_shutdown_function(function () {
        \Brain\Monkey\tearDown();
    });
}
