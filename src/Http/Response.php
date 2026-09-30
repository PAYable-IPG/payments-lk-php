<?php

declare(strict_types=1);

namespace PaymentsLk\Http;

final class Response
{
    /** @var array<string, string> */
    public readonly array $headers;

    /**
     * @param array<string, string> $headers Names are lowercased on the way in.
     */
    public function __construct(
        public readonly int $status,
        array $headers = [],
        public readonly string $body = '',
    ) {
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower((string) $name)] = (string) $value;
        }
        $this->headers = $lower;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
