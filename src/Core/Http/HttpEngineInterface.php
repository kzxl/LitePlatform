<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\KernelInterface;
use App\Core\ServiceProviderInterface;
use Slim\App;

/**
 * Interface for pluggable HTTP Dispatcher Engines.
 */
interface HttpEngineInterface
{
    /**
     * Build and configure the HTTP application pipeline from the kernel.
     *
     * @param KernelInterface $kernel
     * @param list<ServiceProviderInterface> $providers
     * @return App
     */
    public function buildApp(KernelInterface $kernel, array $providers): App;
}
