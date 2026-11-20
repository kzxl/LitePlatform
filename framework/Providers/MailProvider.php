<?php

declare(strict_types=1);

namespace Lite\Framework\Providers;

use DI\Container;
use Lite\Framework\Core\ServiceProviderInterface;
use Lite\Framework\Mail\Mailer;
use Lite\Framework\Mail\TelegramNotifier;
use Psr\Log\LoggerInterface;
use Slim\App;

/**
 * Service provider for Mailer (SMTP / Resend / Brevo / Log) and TelegramNotifier.
 */
final class MailProvider implements ServiceProviderInterface
{
    public function register(Container $container, array $config): void
    {
        $mailConfig = (array)($config['mail'] ?? []);
        $logger = $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null;

        $mailer = new Mailer($mailConfig, $logger);
        $container->set(Mailer::class, $mailer);
        $container->set('mailer', $mailer);

        $tgConfig = (array)($config['telegram'] ?? []);
        $telegram = new TelegramNotifier(
            botToken: (string)($tgConfig['bot_token'] ?? ''),
            chatId: (string)($tgConfig['chat_id'] ?? '')
        );
        $container->set(TelegramNotifier::class, $telegram);
        $container->set('telegram', $telegram);
    }

    public function boot(App $app, Container $container, array $config): void
    {
        // Services ready for controller/job consumption
    }
}
