<?php

declare(strict_types=1);

namespace PaymentsLk\Tests;

use PaymentsLk\Http\Response;
use PaymentsLk\Http\TransportInterface;

/**
 * Records every request and answers from a queue. A queued Throwable is thrown
 * instead of answering, which is how a connection failure is simulated.
 */
final class FakeTransport implements TransportInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: float, connectTimeout: float}> */
    public array $requests = [];

    /** @var list<Response|\Throwable> */
    private array $queue;

    /** @param list<Response|\Throwable> $queue */
    public function __construct(array $queue = [])
    {
        $this->queue = array_values($queue);
    }

    public function push(Response|\Throwable $next): void
    {
        $this->queue[] = $next;
    }

    public function remaining(): int
    {
        return \count($this->queue);
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
        float $connectTimeout,
    ): Response {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $timeout,
            'connectTimeout' => $connectTimeout,
        ];

        if ($this->queue === []) {
            throw new \LogicException(sprintf('FakeTransport has no queued response for %s %s.', $method, $url));
        }

        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
