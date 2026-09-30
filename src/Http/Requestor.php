<?php

declare(strict_types=1);

namespace PaymentsLk\Http;

use PaymentsLk\Client;
use PaymentsLk\Exception\ApiException;
use PaymentsLk\Exception\AuthenticationException;
use PaymentsLk\Exception\ConflictException;
use PaymentsLk\Exception\ConnectionException;
use PaymentsLk\Exception\NotFoundException;
use PaymentsLk\Exception\PermissionException;
use PaymentsLk\Exception\RateLimitException;
use PaymentsLk\Exception\ServerException;
use PaymentsLk\Exception\ValidationException;

/**
 * Builds the request, retries what is safe to retry, and turns the answer into
 * an array or an exception. Internal: use the resources on PaymentsLk\Client.
 *
 * @internal
 */
final class Requestor
{
    /** Statuses worth another attempt. Every other 4xx is final. */
    public const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];
    public const INITIAL_BACKOFF_SECONDS = 0.5;
    public const MAX_BACKOFF_SECONDS = 8.0;
    public const MAX_RETRY_AFTER_SECONDS = 20.0;

    /** The API's own rule for an Idempotency-Key. */
    private const IDEMPOTENCY_KEY_PATTERN = '/^[A-Za-z0-9_\-:.]{8,255}$/D';

    /**
     * The secret keys, held beside the objects that use them and never in them.
     * A private property is hidden from var_dump and print_r only by
     * __debugInfo(); var_export, an (array) cast and the error reporters and
     * dumpers built on them print private properties as they are. Nothing that
     * walks a client reaches a static map, and an entry goes when its object does.
     *
     * @var \WeakMap<self, string>|null
     */
    private static ?\WeakMap $keys = null;

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    public function __construct(
        #[\SensitiveParameter]
        string $secretKey,
        private readonly string $apiBase,
        private readonly float $timeout,
        private readonly float $connectTimeout,
        private readonly int $maxRetries,
        private readonly TransportInterface $transport,
        ?callable $sleep = null,
    ) {
        self::$keys ??= new \WeakMap();
        self::$keys[$this] = $secretKey;
        // A stack trace reads an argument from its variable. PHP 8.1 has no SensitiveParameter, so the variable is emptied.
        $secretKey = '';
        $this->sleep = $sleep !== null
            ? \Closure::fromCallable($sleep)
            : static function (float $seconds): void {
                if ($seconds > 0) {
                    usleep((int) round($seconds * 1_000_000));
                }
            };
    }

    /**
     * @param array<string, string> $query Already validated query parameters.
     * @param array<string, mixed>|null $body JSON body, or null for none.
     * @param array<string, mixed> $options Per request options: idempotencyKey.
     *
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, array $options = []): array
    {
        foreach (array_keys($options) as $name) {
            if ($name !== 'idempotencyKey') {
                throw new \InvalidArgumentException(sprintf('Unknown request option "%s". The only request option is idempotencyKey.', (string) $name));
            }
        }

        $method = strtoupper($method);
        $url = $this->apiBase . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->secretKey(),
            'Accept' => 'application/json',
            'User-Agent' => 'payments-lk-php/' . Client::VERSION . ' PHP/' . PHP_VERSION,
        ];

        $payload = null;
        if ($body !== null) {
            try {
                $payload = json_encode(
                    $body === [] ? new \stdClass() : $body,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
                );
            } catch (\JsonException $e) {
                throw new \InvalidArgumentException('The request parameters could not be encoded as JSON: ' . $e->getMessage(), 0, $e);
            }
            $headers['Content-Type'] = 'application/json';
        }

        if ($method === 'POST') {
            // Chosen once, so every retry of this call carries the same key.
            $headers['Idempotency-Key'] = $this->idempotencyKey($options['idempotencyKey'] ?? null);
        } elseif (isset($options['idempotencyKey'])) {
            throw new \InvalidArgumentException('Only POST requests take an idempotencyKey.');
        }

        $attempt = 0;
        while (true) {
            try {
                $response = $this->transport->send($method, $url, $headers, $payload, $this->timeout, $this->connectTimeout);
            } catch (ConnectionException $e) {
                if ($attempt >= $this->maxRetries) {
                    throw $e;
                }
                ($this->sleep)($this->backoff($attempt));
                $attempt++;
                continue;
            }

            if ($attempt < $this->maxRetries && \in_array($response->status, self::RETRYABLE_STATUSES, true)) {
                ($this->sleep)($this->retryAfter($response) ?? $this->backoff($attempt));
                $attempt++;
                continue;
            }

            return $this->interpret($response);
        }
    }

    /** A random RFC 4122 version 4 UUID from random_bytes. */
    public static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /** Maps an error response to the matching ApiException subclass. */
    public static function exceptionFor(Response $response): ApiException
    {
        $status = $response->status;
        $message = sprintf('The Payments.lk API returned HTTP %d without a readable error body.', $status);
        $code = null;
        $traceId = null;
        $fields = [];
        $reason = null;
        $docUrl = null;
        $sentence = null;

        $decoded = null;
        if (trim($response->body) !== '') {
            try {
                $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = null;
            }
        }

        if (\is_array($decoded)) {
            if (isset($decoded['message']) && \is_string($decoded['message']) && $decoded['message'] !== '') {
                $sentence = $decoded['message'];
            }
            if (isset($decoded['reason']) && \is_string($decoded['reason']) && preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $decoded['reason']) === 1) {
                $reason = $decoded['reason'];
            }
            if (isset($decoded['docUrl']) && \is_string($decoded['docUrl']) && ApiException::isDocUrl($decoded['docUrl'])) {
                $docUrl = $decoded['docUrl'];
            }
            if (isset($decoded['code']) && \is_string($decoded['code']) && $decoded['code'] !== '') {
                $code = $decoded['code'];
            }
            if (isset($decoded['traceId']) && \is_string($decoded['traceId']) && $decoded['traceId'] !== '') {
                $traceId = $decoded['traceId'];
            }
            if (isset($decoded['fields']) && \is_array($decoded['fields'])) {
                foreach ($decoded['fields'] as $field) {
                    if (\is_array($field) && \is_string($field['path'] ?? null) && \is_string($field['message'] ?? null)) {
                        $fields[] = ['path' => $field['path'], 'message' => $field['message']];
                    }
                }
            }
        }

        if ($sentence !== null) {
            $message = $sentence;
        } elseif ($reason !== null) {
            $message = sprintf('The Payments.lk API returned HTTP %d (%s).', $status, $reason);
        }
        $apiMessage = $message;
        if ($docUrl !== null) {
            $message .= ' See ' . $docUrl;
        }

        $class = match (true) {
            $status === 400, $status === 422 => ValidationException::class,
            $status === 401 => AuthenticationException::class,
            $status === 403 => PermissionException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 && $status <= 599 => ServerException::class,
            default => ApiException::class,
        };

        return new $class($message, $status, $code, $traceId, $fields, null, $reason, $docUrl, $apiMessage);
    }

    /**
     * What var_dump and print_r show: which kind of key, and nothing of the key itself.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'secretKey' => (preg_match('/^(?:sk|pk)_(?:test|live)_/', $this->secretKey(), $kind) === 1 ? $kind[0] : '') . '...',
            'apiBase' => $this->apiBase,
            'timeout' => $this->timeout,
            'connectTimeout' => $this->connectTimeout,
            'maxRetries' => $this->maxRetries,
            'transport' => $this->transport::class,
        ];
    }

    /**
     * A client is never stored: in a session, a cache or a queued job its key would be written out with it.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('A Payments.lk client holds a secret key and cannot be serialized. Build it where it is used.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A Payments.lk client cannot be unserialized. Build it where it is used.');
    }

    /** A copy would have no key of its own. */
    private function __clone()
    {
    }

    private function secretKey(): string
    {
        return self::$keys[$this] ?? throw new \LogicException('This Payments.lk client has no key. Build it with new PaymentsLk\\Client().');
    }

    /** @return array<string, mixed> */
    private function interpret(Response $response): array
    {
        $status = $response->status;
        if ($status < 200 || $status > 299) {
            throw self::exceptionFor($response);
        }

        if (trim($response->body) === '') {
            return [];
        }

        try {
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApiException('The Payments.lk API returned a success response that is not valid JSON.', $status, null, null, [], $e);
        }
        if (!\is_array($decoded)) {
            throw new ApiException('The Payments.lk API returned a success response that is not a JSON object.', $status);
        }

        return $decoded;
    }

    private function idempotencyKey(mixed $given): string
    {
        if ($given === null) {
            return self::uuid4();
        }
        if (!\is_string($given) || preg_match(self::IDEMPOTENCY_KEY_PATTERN, $given) !== 1) {
            throw new \InvalidArgumentException('idempotencyKey must be 8 to 255 letters, digits, dashes, underscores, colons or dots.');
        }

        return $given;
    }

    /** Exponential backoff from 0.5 seconds, plus up to 25 percent random jitter. */
    private function backoff(int $attempt): float
    {
        $base = min(self::INITIAL_BACKOFF_SECONDS * (2 ** $attempt), self::MAX_BACKOFF_SECONDS);

        return $base + $base * 0.25 * (random_int(0, 1000) / 1000);
    }

    /** A numeric Retry-After in seconds, capped; null for an absent or date form header. */
    private function retryAfter(Response $response): ?float
    {
        $value = $response->header('retry-after');
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^[0-9]{1,10}(\.[0-9]{1,6})?$/D', $value) !== 1) {
            return null;
        }

        return min((float) $value, self::MAX_RETRY_AFTER_SECONDS);
    }
}
