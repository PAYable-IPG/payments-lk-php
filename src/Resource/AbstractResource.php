<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

use PaymentsLk\Http\Requestor;

abstract class AbstractResource
{
    public const MAX_LIMIT = 100;

    public function __construct(protected readonly Requestor $requestor)
    {
    }

    /** A path segment: a non empty id, rawurlencoded. */
    protected static function pathId(string $id, string $label): string
    {
        if ($id === '' || $id === '.' || $id === '..') {
            throw new \InvalidArgumentException(sprintf('%s must be a non empty id string.', $label));
        }

        return rawurlencode($id);
    }

    /**
     * Validates list parameters against the ones this endpoint takes and
     * returns them in a stable order, without nulls.
     *
     * @param array<array-key, mixed> $query
     * @param list<string> $allowed
     *
     * @return array<string, string>
     */
    protected static function query(array $query, array $allowed): array
    {
        foreach (array_keys($query) as $name) {
            if (!\in_array($name, $allowed, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Unknown query parameter "%s". This endpoint takes: %s.',
                    (string) $name,
                    implode(', ', $allowed),
                ));
            }
        }

        $out = [];
        foreach ($allowed as $name) {
            if (!\array_key_exists($name, $query) || $query[$name] === null) {
                continue;
            }
            $value = $query[$name];
            if ($name === 'limit') {
                if (!\is_int($value) || $value < 1 || $value > self::MAX_LIMIT) {
                    throw new \InvalidArgumentException('limit must be an integer from 1 to 100.');
                }
                $out[$name] = (string) $value;
                continue;
            }
            if (!\is_string($value) || $value === '') {
                throw new \InvalidArgumentException(sprintf('%s must be a non empty string.', $name));
            }
            $out[$name] = $value;
        }

        return $out;
    }

    /** @param array<string, mixed> $params */
    protected static function assertAmount(array $params): void
    {
        if (\array_key_exists('amountCents', $params) && !\is_int($params['amountCents'])) {
            throw new \InvalidArgumentException('amountCents must be an integer number of LKR cents (Rs. 10.00 is 1000), never a float or a string.');
        }
    }

    /**
     * Walks every page of a list endpoint. Validation already happened in the caller.
     *
     * @param array<array-key, mixed> $query
     * @param list<string> $allowed
     *
     * @return \Generator<int, array<string, mixed>>
     */
    protected function paginate(string $path, array $query, array $allowed): \Generator
    {
        $cursor = $query['cursor'] ?? null;
        while (true) {
            $query['cursor'] = $cursor;
            $page = $this->requestor->request('GET', $path, self::query($query, $allowed));

            $data = $page['data'] ?? [];
            if (\is_array($data)) {
                foreach ($data as $item) {
                    yield $item;
                }
            }

            $next = $page['nextCursor'] ?? null;
            if (($page['hasMore'] ?? false) !== true || !\is_string($next) || $next === '') {
                return;
            }
            if ($next === $cursor) {
                throw new \UnexpectedValueException('The API returned the same cursor twice; stopping instead of looping forever.');
            }
            $cursor = $next;
        }
    }
}
