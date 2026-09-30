<?php

declare(strict_types=1);

namespace PaymentsLk\Http;

use PaymentsLk\Exception\ConnectionException;

/**
 * Sends one HTTP request and returns whatever status came back. Retries, error
 * mapping and JSON live above this layer, so a test double only has to record
 * and replay.
 */
interface TransportInterface
{
    /**
     * @param array<string, string> $headers Header name to value. Authorization carries the secret key: never log them, and mark the parameter SensitiveParameter in your own transport so a stack trace does not keep it.
     * @param float $timeout Whole request budget in seconds.
     * @param float $connectTimeout Connection budget in seconds.
     *
     * @throws ConnectionException When no HTTP response was received.
     */
    public function send(
        string $method,
        string $url,
        #[\SensitiveParameter]
        array $headers,
        ?string $body,
        float $timeout,
        float $connectTimeout,
    ): Response;
}
