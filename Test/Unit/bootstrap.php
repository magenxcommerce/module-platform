<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 *
 * Composer's autoloader when there is one, and a hand-rolled map for this
 * module's own namespace either way.
 *
 * The second half is what lets the "standalone" suite run in a bare checkout:
 * magento/framework lives on repo.magento.com behind credentials, so
 * `composer install` is not always available, and the classes that suite covers
 * construct with no framework types at all.
 */

declare(strict_types=1);

$autoload = __DIR__ . '/../../vendor/autoload.php';

if (is_file($autoload)) {
    require_once $autoload;
}

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'Magenx\\Platform\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $path = dirname(__DIR__, 2) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($path)) {
            require_once $path;
        }
    }
);
