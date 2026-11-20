<?php

declare(strict_types=1);

namespace App\Controllers;

use DI\Container;
use Lite\Framework\I18n\Translator;
use Lite\Framework\Mail\Mailer;
use Lite\Framework\Mail\TelegramNotifier;
use Lite\Framework\Security\HoneypotGuard;
use Lite\Framework\Seo\SchemaBuilder;
use Lite\Framework\Seo\SeoMeta;
use LiteView\ViewEngine;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Turnkey Contact / Lead Capture Controller demonstrating full end-to-end integration:
 * CSRF + HoneypotGuard (zero-CAPTCHA) + RateLimitMiddleware + PDO persistence + Mailer + Telegram alert.
 */
final class ContactController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function show(Request $request, Response $response): Response
    {
        return $this->renderForm($request, $response);
    }

    public function submit(Request $request, Response $response): Response
    {
        $body = (array)$request->getParsedBody();
        $translator = $this->container->has(Translator::class) ? $this->container->get(Translator::class) : null;
        $t = static fn(string $k, string $fallback): string => $translator ? $translator->get($k) : $fallback;

        // 1. Zero-CAPTCHA bot verification via HoneypotGuard
        if ($this->container->has(HoneypotGuard::class)) {
            /** @var HoneypotGuard $hp */
            $hp = $this->container->get(HoneypotGuard::class);
            if (!$hp->verify($body)) {
                return $this->renderForm(
                    $request,
                    $response->withStatus(422),
                    error: $t('contact.error_spam', 'Submission rejected. Please wait a moment and try again.'),
                    old: $body
                );
            }
        }

        // 2. Validate required fields
        $name = trim((string)($body['name'] ?? ''));
        $email = trim((string)($body['email'] ?? ''));
        $phone = trim((string)($body['phone'] ?? ''));
        $message = trim((string)($body['message'] ?? ''));

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($message) < 5) {
            return $this->renderForm(
                $request,
                $response->withStatus(422),
                error: $t('contact.error_validation', 'Please provide your name, a valid email address, and a message.'),
                old: $body
            );
        }

        $clientIp = (string)($request->getAttribute('client_ip') ?? ($request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1'));

        // 3. Persist lead to database
        if ($this->container->has(PDO::class)) {
            /** @var PDO $pdo */
            $pdo = $this->container->get(PDO::class);
            $stmt = $pdo->prepare(
                'INSERT INTO contact_leads (name, email, phone, message, ip_address, created_at)
                 VALUES (:name, :email, :phone, :message, :ip, :created_at)'
            );
            $stmt->execute([
                ':name'       => mb_substr($name, 0, 120),
                ':email'      => mb_substr($email, 0, 160),
                ':phone'      => $phone !== '' ? mb_substr($phone, 0, 40) : null,
                ':message'    => $message,
                ':ip'         => mb_substr($clientIp, 0, 64),
                ':created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        // 4. Dispatch transactional email notification (Mailer)
        $config = (array)$this->container->get('config');
        $notifyTo = (string)($config['mail']['notify_to'] ?? $config['mail']['from_address'] ?? 'admin@liteplatform.io');
        if ($this->container->has(Mailer::class)) {
            /** @var Mailer $mailer */
            $mailer = $this->container->get(Mailer::class);
            $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = sprintf(
                '<h3>New Contact Lead</h3><p><strong>Name:</strong> %s</p><p><strong>Email:</strong> %s</p><p><strong>Phone:</strong> %s</p><p><strong>Message:</strong><br>%s</p>',
                $e($name),
                $e($email),
                $e($phone !== '' ? $phone : 'N/A'),
                nl2br($e($message))
            );
            try {
                $mailer->send($notifyTo, "New Lead: {$name}", $html, replyTo: $email);
            } catch (\Throwable) {
                // Keep lead saved even if external SMTP is temporarily unreachable
            }
        }

        // 5. Instant Telegram notification if configured
        if ($this->container->has(TelegramNotifier::class)) {
            /** @var TelegramNotifier $tg */
            $tg = $this->container->get(TelegramNotifier::class);
            if ($tg->isConfigured()) {
                $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $tg->send("<b>New Lead</b>\nName: {$e($name)}\nEmail: {$e($email)}\nPhone: {$e($phone)}\nMessage: {$e($message)}");
            }
        }

        return $this->renderForm(
            $request,
            $response,
            success: $t('contact.success', 'Thank you! Your message has been received.')
        );
    }

    /**
     * @param array<string, mixed> $old
     */
    private function renderForm(
        Request $request,
        Response $response,
        ?string $error = null,
        ?string $success = null,
        array $old = []
    ): Response {
        /** @var ViewEngine $view */
        $view = $this->container->get(ViewEngine::class);
        $translator = $this->container->has(Translator::class) ? $this->container->get(Translator::class) : null;
        $title = $translator ? $translator->get('contact.title') : 'Contact';

        $appUrl = rtrim((string)($this->container->get('config')['url'] ?? 'http://localhost:8000'), '/');
        $seo = $this->container->has(SeoMeta::class) ? $this->container->get(SeoMeta::class) : new SeoMeta();
        $seo = $seo
            ->withTitle($title)
            ->withCanonical($appUrl . '/contact')
            ->addSchema(SchemaBuilder::breadcrumb([
                ['name' => 'Home', 'url' => $appUrl . '/'],
                ['name' => $title, 'url' => $appUrl . '/contact'],
            ]));

        return $view->renderResponse($response, 'contact', [
            'seo'     => $seo,
            'error'   => $error,
            'success' => $success,
            'old'     => $old,
        ]);
    }
}
