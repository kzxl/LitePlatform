<?php

declare(strict_types=1);

namespace Lite\Framework\Core;

use Lite\Framework\Core\Events\EventBusInterface;
use Lite\Framework\Core\Events\InMemoryEventBus;
use Lite\Framework\Core\Http\SlimHttpAdapter;
use Lite\Framework\Core\Plugin\ModuleManager;
use Lite\Framework\Core\Plugin\ModuleManagerInterface;
use Lite\Framework\Core\Routing\RouteRegistry;
use Lite\Framework\Core\Routing\RouteRegistryInterface;
use Lite\Framework\Core\Tenancy\TenantContext;
use Lite\Framework\Core\Tenancy\TenantContextInterface;
use DI\Container;
use DI\ContainerBuilder;
use Slim\App;

/**
 * Sovereign Protocol-Agnostic Application Kernel.
 * Dispatches pure application services and modules independently of HTTP or Slim 4.
 */
class Kernel implements KernelInterface
{
    private static ?self $instance = null;

    private readonly Container $container;
    private readonly EventBusInterface $eventBus;
    private readonly TenantContextInterface $tenantContext;
    private readonly RouteRegistryInterface $routeRegistry;
    private readonly ModuleManagerInterface $moduleManager;

    /**
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * @var list<ServiceProviderInterface>
     */
    private array $providers = [];

    private bool $isBooted = false;

    public function __construct(
        private readonly string $basePath
    ) {
        // 1. Load environment variables
        self::loadEnv($this->basePath . '/.env');

        // 2. Load configurations
        $appConfig = file_exists($this->basePath . '/config/app.php') ? require $this->basePath . '/config/app.php' : [];
        $dbConfig = file_exists($this->basePath . '/config/database.php') ? require $this->basePath . '/config/database.php' : [];
        $this->config = array_merge($appConfig, ['database' => $dbConfig, 'base_path' => $this->basePath]);

        // 3. Spacetime services: EventBus, TenantContext, RouteRegistry, ModuleManager
        $this->eventBus = new InMemoryEventBus();
        $this->tenantContext = new TenantContext();
        $this->routeRegistry = new RouteRegistry();
        $this->moduleManager = new ModuleManager();

        // 4. Initialize PSR-11 PHP-DI Container
        $builder = new ContainerBuilder();
        $this->container = $builder->build();

        $this->container->set('config', $this->config);
        $this->container->set(KernelInterface::class, $this);
        $this->container->set(self::class, $this);
        $this->container->set(EventBusInterface::class, $this->eventBus);
        $this->container->set('event_bus', $this->eventBus);
        $this->container->set(TenantContextInterface::class, $this->tenantContext);
        $this->container->set('tenant.context', $this->tenantContext);
        $this->container->set(RouteRegistryInterface::class, $this->routeRegistry);
        $this->container->set('routes.registry', $this->routeRegistry);
        $this->container->set(ModuleManagerInterface::class, $this->moduleManager);
        $this->container->set('module_manager', $this->moduleManager);

        self::$instance = $this;
    }

    public static function create(string $basePath): self
    {
        if (self::$instance !== null && self::$instance->getBasePath() === $basePath) {
            return self::$instance;
        }

        return new self($basePath);
    }

    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function getTenantContext(): TenantContextInterface
    {
        return $this->tenantContext;
    }

    public function getRouteRegistry(): RouteRegistryInterface
    {
        return $this->routeRegistry;
    }

    public function getModuleManager(): ModuleManagerInterface
    {
        return $this->moduleManager;
    }

    public function getEventBus(): EventBusInterface
    {
        return $this->eventBus;
    }

    public function isBooted(): bool
    {
        return $this->isBooted;
    }

    public function boot(): self
    {
        if ($this->isBooted) {
            return $this;
        }

        // 1. Register & instantiate Service Providers
        foreach ($this->config['providers'] ?? [] as $providerClass) {
            if (class_exists($providerClass) && is_subclass_of($providerClass, ServiceProviderInterface::class)) {
                /** @var ServiceProviderInterface $provider */
                $provider = new $providerClass();
                $provider->register($this->container, $this->config);
                $this->providers[] = $provider;
            }
        }

        // 2. Discover and register configured modules
        foreach ($this->config['modules'] ?? [] as $moduleClass) {
            if (class_exists($moduleClass) && is_subclass_of($moduleClass, \Lite\Framework\Core\Plugin\IPluginModule::class)) {
                $this->moduleManager->registerModule(new $moduleClass());
            }
        }

        // 3. Boot dynamic modules
        $this->moduleManager->bootModules($this);

        // 4. Mark as booted
        $this->isBooted = true;
        return $this;
    }

    /**
     * Create and configure the Slim 4 HTTP pipeline via SlimHttpAdapter.
     */
    public function createHttpApp(): App
    {
        $this->boot();

        $adapter = new SlimHttpAdapter();
        return $adapter->buildApp($this, $this->providers);
    }

    /**
     * Minimalist zero-dependency .env parser.
     */
    public static function loadEnv(string $envPath): void
    {
        if (!file_exists($envPath)) {
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                if (!array_key_exists($key, $_ENV)) {
                    $_ENV[$key] = $value;
                    putenv("{$key}={$value}");
                }
            }
        }
    }
}
