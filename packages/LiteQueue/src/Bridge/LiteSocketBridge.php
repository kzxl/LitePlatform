<?php

declare(strict_types=1);

namespace LiteQueue\Bridge;

use LiteQueue\Driver\QueueRecord;
use LiteQueue\Job\BroadcastableJobInterface;
use LiteQueue\Worker;
use Throwable;

/**
 * Real-time event bridge connecting LiteQueue worker executions with LiteSocket real-time channels.
 * Automatically pushes job completion and failure events to WebSocket clients/rooms.
 */
class LiteSocketBridge
{
    /** @var callable fn(string $channel, array $payload): void */
    private $dispatcher;
    private bool $broadcastFailures = false;

    /**
     * @param callable $dispatcher fn(string $channel, array $payload): void
     */
    public function __construct(callable $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Create bridge using an in-process LiteSocket instance or RoomManager.
     *
     * @param object $serverOrRoomManager An instance of LiteSocket\WebSocketServer or LiteSocket\Messaging\RoomManager
     */
    public static function inProcess(object $serverOrRoomManager): self
    {
        return new self(function (string $channel, array $payload) use ($serverOrRoomManager) {
            if (method_exists($serverOrRoomManager, 'broadcastToRoom')) {
                $serverOrRoomManager->broadcastToRoom($channel, $payload);
            } elseif (method_exists($serverOrRoomManager, 'broadcast')) {
                $serverOrRoomManager->broadcast($channel, $payload);
            }
        });
    }

    /**
     * Create bridge using a custom dispatch callback.
     */
    public static function custom(callable $callback): self
    {
        return new self($callback);
    }

    /**
     * Create bridge that sends real-time broadcast payloads over TCP socket to LiteSocket daemon.
     */
    public static function tcp(string $host = '127.0.0.1', int $port = 8088, float $timeout = 2.0): self
    {
        return new self(function (string $channel, array $payload) use ($host, $port, $timeout) {
            self::sendTcpBroadcast($host, $port, $channel, $payload, $timeout);
        });
    }

    /**
     * Attach the bridge to a Worker instance to listen for job success and failure events.
     */
    public function attach(Worker $worker, bool $broadcastFailures = true): self
    {
        $this->broadcastFailures = $broadcastFailures;

        $worker->onJobSuccess(function (QueueRecord $record, mixed $job) {
            $this->handleSuccess($record, $job);
        });

        if ($broadcastFailures) {
            $worker->onJobFailure(function (QueueRecord $record, mixed $job, Throwable $exception) {
                $this->handleFailure($record, $job, $exception);
            });
        }

        return $this;
    }

    /**
     * Handle job execution success event.
     */
    public function handleSuccess(QueueRecord $record, mixed $job): void
    {
        if (!($job instanceof BroadcastableJobInterface)) {
            return;
        }

        $channel = $job->broadcastChannel();
        $payload = [
            'type'      => 'job_completed',
            'event'     => $job->broadcastAs(),
            'channel'   => $channel,
            'jobId'     => $record->id,
            'status'    => 'success',
            'data'      => $job->broadcastWith(),
            'timestamp' => microtime(true),
        ];

        try {
            ($this->dispatcher)($channel, $payload);
        } catch (Throwable $e) {
            echo sprintf("[LiteQueue Bridge Error] Failed to broadcast success: %s\n", $e->getMessage());
        }
    }

    /**
     * Handle job execution failure event.
     */
    public function handleFailure(QueueRecord $record, mixed $job, Throwable $exception): void
    {
        if (!($job instanceof BroadcastableJobInterface)) {
            return;
        }

        $channel = $job->broadcastChannel();
        $payload = [
            'type'      => 'job_failed',
            'event'     => $job->broadcastAs(),
            'channel'   => $channel,
            'jobId'     => $record->id,
            'status'    => 'failed',
            'error'     => $exception->getMessage(),
            'attempts'  => $record->attempts,
            'timestamp' => microtime(true),
        ];

        try {
            ($this->dispatcher)($channel, $payload);
        } catch (Throwable $e) {
            echo sprintf("[LiteQueue Bridge Error] Failed to broadcast failure: %s\n", $e->getMessage());
        }
    }

    /**
     * Send WebSocket-framed or raw JSON broadcast payload over TCP stream.
     */
    private static function sendTcpBroadcast(
        string $host,
        int $port,
        string $channel,
        array $payload,
        float $timeout
    ): void {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);

        if (!$socket) {
            return;
        }

        // Minimal RFC 6455 Client Handshake
        $key = base64_encode(random_bytes(16));
        $handshake = "GET / HTTP/1.1\r\n"
            . "Host: {$host}:{$port}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";

        @fwrite($socket, $handshake);
        $response = @fread($socket, 1024);

        if ($response && str_contains($response, '101 Switching Protocols')) {
            // Encode client-masked text frame
            $json = json_encode([
                'type'    => 'broadcast',
                'room'    => $channel,
                'payload' => $payload,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $frame = self::encodeMaskedTextFrame($json);
            @fwrite($socket, $frame);
        }

        @fclose($socket);
    }

    /**
     * Encode masked RFC 6455 frame (Client -> Server).
     */
    private static function encodeMaskedTextFrame(string $payload): string
    {
        $length = strlen($payload);
        $mask = random_bytes(4);
        $firstByte = 0x81; // FIN + OPCODE_TEXT

        if ($length <= 125) {
            $header = pack('CC', $firstByte, 0x80 | $length) . $mask;
        } elseif ($length <= 65535) {
            $header = pack('CCn', $firstByte, 0x80 | 126, $length) . $mask;
        } else {
            $header = pack('CCJ', $firstByte, 0x80 | 127, $length) . $mask;
        }

        $maskedPayload = '';
        for ($i = 0; $i < $length; $i++) {
            $maskedPayload .= $payload[$i] ^ $mask[$i % 4];
        }

        return $header . $maskedPayload;
    }
}
