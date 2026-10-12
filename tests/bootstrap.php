<?php

declare(strict_types=1);

if (is_file($autoload = dirname(__DIR__).'/vendor/autoload.php')) {
    require $autoload;
} else {
    $loader = require dirname(__DIR__, 3).'/vendor/autoload.php';
    $loader->addPsr4('Survos\\HarvestBundle\\', dirname(__DIR__).'/src');
    $loader->addPsr4('Survos\\HarvestBundle\\Tests\\', __DIR__);
}
