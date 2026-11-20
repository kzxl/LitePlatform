<?php

declare(strict_types=1);

namespace Lite\Framework\Providers;

use Lite\Framework\Core\ServiceProviderInterface;
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
        $env = (string)($config['env'] ?? 'development');
        $jwtSecret = (string)($apiConfig['jwt_secret'] ?? ($_ENV['JWT_SECRET'] ?? 'default-jwt-secret-32-chars-long!'));

        if ($env === 'production' && (strlen($jwtSecret) < 32 || str_starts_with($jwtSecret, 'default-'))) {
            throw new \RuntimeException('Insecure JWT_SECRET in production. Configure a >= 32-char secret in .env.');
        }

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
