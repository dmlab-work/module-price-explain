<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

$moduleRoot = dirname(__DIR__, 2);

$candidates = [
    getenv('MAGENTO_ROOT') ? rtrim((string)getenv('MAGENTO_ROOT'), '/') . '/vendor/autoload.php' : null,
    '/var/www/html/vendor/autoload.php',
    $moduleRoot . '/vendor/autoload.php',
];

$loaded = false;
foreach ($candidates as $candidate) {
    if ($candidate !== null && is_file($candidate)) {
        require $candidate;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    fwrite(STDERR, "Unable to locate a Composer autoloader (set MAGENTO_ROOT).\n");
    // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
    exit(1);
}

$prefix = 'DmLab\\PriceExplain\\';
spl_autoload_register(static function (string $class) use ($moduleRoot, $prefix): void {
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $moduleRoot . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// phpcs:ignore PSR1.Classes.ClassDeclaration.MissingNamespace
class GeneratedFactoryStub
{
    /**
     * @param array<string, mixed> $data
     * @return mixed
     */
    public function create(array $data = []): mixed
    {
        return null;
    }
}

// Factories are generated at di:compile; alias any missing one (registered last, real classes win).
spl_autoload_register(static function (string $class): void {
    if (str_ends_with($class, 'Factory') && !class_exists($class, false)) {
        class_alias(GeneratedFactoryStub::class, $class);
    }
});

// When composer-installed, Magento's autoloader already ran registration.php; re-requiring throws.
try {
    require $moduleRoot . '/registration.php';
} catch (\LogicException $e) {
    // Already registered.
}
