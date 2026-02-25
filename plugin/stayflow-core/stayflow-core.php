<?php
/**
 * Plugin Name: StayFlow Core
 * Description: Safe SaaS-ready core scaffold (no runtime integration).
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Author: StayFlow
 * Text Domain: stayflow-core
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const STAYFLOW_CORE_VERSION = '0.1.0';
const STAYFLOW_CORE_FILE = __FILE__;
const STAYFLOW_CORE_DIR = __DIR__;

/**
 * RU: Простая PSR-4 автозагрузка для пространства имен StayFlow\Core\
 * EN: Simple PSR-4 autoloader for StayFlow\Core\
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'StayFlow\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = STAYFLOW_CORE_DIR . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

/**
 * RU: Регистрация activation/deactivation.
 * EN: Activation/deactivation registration.
 */
register_activation_hook(
    STAYFLOW_CORE_FILE,
    function (): void {
        require_once STAYFLOW_CORE_DIR . '/src/Core/Activator.php';
        \StayFlow\Core\Activator::activate();
    }
);

register_deactivation_hook(
    STAYFLOW_CORE_FILE,
    function (): void {
        require_once STAYFLOW_CORE_DIR . '/src/Core/Deactivator.php';
        \StayFlow\Core\Deactivator::deactivate();
    }
);

/**
 * RU: Инициализация ядра.
 * EN: Kernel bootstrap.
 */
add_action('plugins_loaded', static function (): void {
    $kernel = new \StayFlow\Core\Kernel();
    $kernel->boot();
});