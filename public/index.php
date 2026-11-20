<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Lite\Framework\Core\AppBootstrap;

// Bootstrap application kernel with configured Providers and Routes
$app = AppBootstrap::boot(dirname(__DIR__));

// Run the Slim 4 application pipeline
$app->run();
