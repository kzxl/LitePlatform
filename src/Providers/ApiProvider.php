<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\ServiceProviderInterface;
use DI\Container;
use Slim\App;

/**
 * Service provider for REST API tools, CORS, and HS256 JWT Authentication.
 */
class ApiProvider implements ServiceProviderInterface
{
    public function register(Container $container, array $config): void
    {
        $apiConfig = $config['api'] ?? [];
        $jwtSecret = $apiConfig['jwt_secret'] ?? ($_ENV['JWT_SECRET'] ?? 'default-secret-key-32-chars-min!!');
        $container->set('jwt.secret', $jwtSecret);
    }

    public function boot(App $app, Container $container, array $config): void
    {
        // 1. Register CORS Middleware if LiteApi is available
        if (class_exists(\LiteApi\Middleware\CorsMiddleware::class)) {
            $corsConfig = $config['api']['cors'] ?? [];
            $origins = $corsConfig['allowed_origins'] ?? ['*'];
            $app->add(new \LiteApi\Middleware\CorsMiddleware(
                origins: $origins,
                responseFactory: $app->getResponseFactory()
            ));
        }
    }
}
