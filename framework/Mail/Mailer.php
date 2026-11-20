<?php

declare(strict_types=1);

namespace Lite\Framework\Mail;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Zero-dependency lightweight Mailer for Shared Hosting.
 * Drivers:
 * - 'smtp'  : Pure PHP socket SMTP with STARTTLS (587) / SSL (465) & AUTH LOGIN
 * - 'http'  : Transactional API (Resend or Brevo) when hosting blocks outbound SMTP ports
 * - 'log'   : Logs emails to PSR-3 FileLogger (safe local dev default)
 * - 'array' : Captures sent messages in memory (for unit tests)
 */
final class Mailer
{
    /** @var list<array{to: string, subject: string, html: string, text: string, from: string, reply_to: ?string}> */
    private array $sent = [];

    /** @var null|callable(string, array<string, string>, string): array{status: int, body: string} */
    private $httpTransport = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config = [],
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Inject custom HTTP transport callable for deterministic unit testing.
     *
     * @param callable(string, array<string, string>, string): array{status: int, body: string} $transport
     */
    public function setHttpTransport(callable $transport): self
    {
        $this->httpTransport = $transport;
        return $this;
    }

    /**
     * @return list<array{to: string, subject: string, html: string, text: string, from: string, reply_to: ?string}>
     */
    public function getSentMessages(): array
    {
        return $this->sent;
    }

    public function send(
        string $to,
        string $subject,
        string $htmlBody,
        ?string $textBody = null,
        ?string $replyTo = null
    ): bool {
        $cleanTo = $this->sanitizeAddress($to);
        $cleanSubject = $this->sanitizeHeader($subject);
        $cleanReplyTo = $replyTo !== null && trim($replyTo) !== '' ? $this->sanitizeAddress($replyTo) : null;
        $fromAddress = $this->sanitizeAddress((string)($this->config['from_address'] ?? 'noreply@localhost.test'));
        $fromName = $this->sanitizeHeader((string)($this->config['from_name'] ?? 'LitePlatform'));
        $plain = $textBody ?? trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody)));

        $message = [
            'to'        => $cleanTo,
            'subject'   => $cleanSubject,
            'html'      => $htmlBody,
            'text'      => $plain,
            'from'      => $fromAddress,
            'from_name' => $fromName,
            'reply_to'  => $cleanReplyTo,
        ];

        $driver = strtolower((string)($this->config['driver'] ?? 'log'));

        return match ($driver) {
            'array' => $this->sendArray($message),
            'log'   => $this->sendLog($message),
            'http'  => $this->sendHttpApi($message),
            'smtp'  => $this->sendSmtp($message),
            default => throw new InvalidArgumentException("Unsupported mail driver: {$driver}"),
        };
    }

    /**
     * @param array<string, ?string> $message
     */
    private function sendArray(array $message): bool
    {
        $this->sent[] = [
            'to'       => (string)$message['to'],
            'subject'  => (string)$message['subject'],
            'html'     => (string)$message['html'],
            'text'     => (string)$message['text'],
            'from'     => (string)$message['from'],
            'reply_to' => $message['reply_to'],
        ];

        return true;
    }

    /**
     * @param array<string, ?string> $message
     */
    private function sendLog(array $message): bool
    {
        $this->sendArray($message);
        $this->logger?->info('Mail dispatched (log driver)', [
            'to'      => $message['to'],
            'subject' => $message['subject'],
        ]);

        return true;
    }

    /**
     * Sends via Resend or Brevo transactional REST API (ideal when Shared Hosting blocks SMTP ports).
     *
     * @param array<string, ?string> $message
     */
    private function sendHttpApi(array $message): bool
    {
        $provider = strtolower((string)($this->config['http_provider'] ?? 'resend'));
        $apiKey = (string)($this->config['api_key'] ?? '');
        if ($apiKey === '') {
            throw new RuntimeException('MAIL_API_KEY is required when using the http mail driver.');
        }

        if ($provider === 'brevo') {
            $endpoint = 'https://api.brevo.com/v3/smtp/email';
            $headers = [
                'api-key'      => $apiKey,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ];
            $payload = [
                'sender'      => ['name' => $message['from_name'], 'email' => $message['from']],
                'to'          => [['email' => $message['to']]],
                'subject'     => $message['subject'],
                'htmlContent' => $message['html'],
                'textContent' => $message['text'],
            ];
            if ($message['reply_to'] !== null) {
                $payload['replyTo'] = ['email' => $message['reply_to']];
            }
        } else {
            // Default: Resend API
            $endpoint = 'https://api.resend.com/emails';
            $headers = [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ];
            $payload = [
                'from'    => sprintf('%s <%s>', $message['from_name'], $message['from']),
                'to'      => [$message['to']],
                'subject' => $message['subject'],
                'html'    => $message['html'],
                'text'    => $message['text'],
            ];
            if ($message['reply_to'] !== null) {
                $payload['reply_to'] = $message['reply_to'];
            }
        }

        $json = (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $res = $this->executeHttpPost($endpoint, $headers, $json);

        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException("Mail HTTP API ({$provider}) failed with status {$res['status']}: {$res['body']}");
        }

        $this->sendArray($message);
        return true;
    }

    /**
     * Pure PHP socket SMTP transport supporting STARTTLS (587) and SSL (465).
     *
     * @param array<string, ?string> $message
     */
    private function sendSmtp(array $message): bool
    {
        $host = (string)($this->config['host'] ?? '127.0.0.1');
        $port = (int)($this->config['port'] ?? 587);
        $encryption = strtolower((string)($this->config['encryption'] ?? 'tls'));
        $username = (string)($this->config['username'] ?? '');
        $password = (string)($this->config['password'] ?? '');
        $timeout = (int)($this->config['timeout'] ?? 15);

        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout);
        if (!is_resource($socket)) {
            throw new RuntimeException("SMTP connection failed ({$remote}): {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, $timeout);

        try {
            $this->smtpExpect($socket, 220);
            $ehloHost = gethostname() ?: 'localhost';
            $this->smtpCommand($socket, "EHLO {$ehloHost}", 250);

            if ($encryption === 'tls') {
                $this->smtpCommand($socket, 'STARTTLS', 220);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP STARTTLS negotiation failed.');
                }
                $this->smtpCommand($socket, "EHLO {$ehloHost}", 250);
            }

            if ($username !== '') {
                $this->smtpCommand($socket, 'AUTH LOGIN', 334);
                $this->smtpCommand($socket, base64_encode($username), 334);
                $this->smtpCommand($socket, base64_encode($password), 235);
            }

            $this->smtpCommand($socket, "MAIL FROM:<{$message['from']}>", 250);
            $this->smtpCommand($socket, "RCPT TO:<{$message['to']}>", 250);
            $this->smtpCommand($socket, 'DATA', 354);

            $mime = $this->buildMimePayload($message);
            // Dot-stuff lines beginning with '.' per RFC 5321
            $escapedMime = preg_replace('/^\./m', '..', $mime) ?? $mime;
            fwrite($socket, $escapedMime . "\r\n.\r\n");
            $this->smtpExpect($socket, 250);

            $this->smtpCommand($socket, 'QUIT', 221);
        } finally {
            fclose($socket);
        }

        $this->sendArray($message);
        return true;
    }

    /**
     * @param array<string, ?string> $message
     */
    private function buildMimePayload(array $message): string
    {
        $boundary = 'b_' . bin2hex(random_bytes(12));
        $encodedSubject = '=?UTF-8?B?' . base64_encode((string)$message['subject']) . '?=';
        $encodedFromName = '=?UTF-8?B?' . base64_encode((string)$message['from_name']) . '?=';

        $headers = [
            "Date: " . date('r'),
            "From: {$encodedFromName} <{$message['from']}>",
            "To: <{$message['to']}>",
            "Subject: {$encodedSubject}",
            'MIME-Version: 1.0',
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
        ];
        if ($message['reply_to'] !== null) {
            $headers[] = "Reply-To: <{$message['reply_to']}>";
        }

        $parts = [
            "--{$boundary}",
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode((string)$message['text'])),
            "--{$boundary}",
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode((string)$message['html'])),
            "--{$boundary}--",
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts);
    }

    /**
     * @param resource $socket
     */
    private function smtpCommand($socket, string $command, int $expectedCode): void
    {
        fwrite($socket, $command . "\r\n");
        $this->smtpExpect($socket, $expectedCode);
    }

    /**
     * @param resource $socket
     */
    private function smtpExpect($socket, int $expectedCode): void
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }

        $code = (int)substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new RuntimeException("SMTP unexpected response (expected {$expectedCode}, got {$code}): " . trim($response));
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    private function executeHttpPost(string $url, array $headers, string $body): array
    {
        if ($this->httpTransport !== null) {
            return ($this->httpTransport)($url, $headers, $body);
        }

        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = "{$k}: {$v}";
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headerLines),
                'content'       => $body,
                'timeout'       => 10,
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/HTTP\/\S+\s+(\d{3})/', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }

        return [
            'status' => $status,
            'body'   => is_string($responseBody) ? $responseBody : '',
        ];
    }

    private function sanitizeHeader(string $value): string
    {
        return trim((string)preg_replace('/[\r\n]+/', ' ', $value));
    }

    private function sanitizeAddress(string $email): string
    {
        $clean = $this->sanitizeHeader($email);
        if (!filter_var($clean, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email address: {$clean}");
        }

        return $clean;
    }
}
