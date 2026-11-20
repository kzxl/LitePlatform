<?php

declare(strict_types=1);

namespace Lite\Framework\Mail;

/**
 * Ultra-lightweight Telegram Bot API alert sender for instant lead, order, or critical error notifications.
 */
final class TelegramNotifier
{
    /** @var null|callable(string, array<string, mixed>): bool */
    private $transport = null;

    public function __construct(
        private readonly string $botToken = '',
        private readonly string $chatId = ''
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->botToken !== '' && $this->chatId !== '';
    }

    /**
     * @param callable(string, array<string, mixed>): bool $transport
     */
    public function setTransport(callable $transport): self
    {
        $this->transport = $transport;
        return $this;
    }

    public function send(string $text, string $parseMode = 'HTML'): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
        $payload = [
            'chat_id'                  => $this->chatId,
            'text'                     => $text,
            'parse_mode'               => $parseMode,
            'disable_web_page_preview' => true,
        ];

        if ($this->transport !== null) {
            return ($this->transport)($url, $payload);
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\n",
                'content'       => (string)json_encode($payload, JSON_UNESCAPED_UNICODE),
                'timeout'       => 8,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if (!is_string($raw)) {
            return false;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) && (bool)($decoded['ok'] ?? false);
    }
}
