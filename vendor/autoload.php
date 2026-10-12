<?php
/**
 * Autoloader PSR-4 mínimo. Si ejecutas `composer install --no-dev -o`
 * en la carpeta del módulo, Composer lo reemplaza por el suyo.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'PrestaShop\\Module\\RecepcionProveedor\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
