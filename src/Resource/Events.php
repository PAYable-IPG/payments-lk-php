<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

use PaymentsLk\Webhook;

/**
 * The events your webhooks receive, in the same shape
 * ({id, object: "event", type, mode, created, data}), newest first. Only the
 * types a webhook can deliver are listed. Useful to catch up after downtime.
 */
class Events extends AbstractResource
{
    private const LIST_PARAMS = ['limit', 'cursor', 'type', 'since'];

    /**
     * GET /v1/events: one page, {object: "list", data, hasMore, nextCursor}.
     *
     * @param array{limit?: int, cursor?: string, type?: string, since?: string} $query
     *   type is one event type (Webhook::EVENT_*); since is an ISO 8601 time,
     *   such as 2026-09-18T00:00:00Z, and keeps events created at or after it.
     *
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->requestor->request('GET', '/v1/events', self::eventQuery($query));
    }

    /**
     * GET /v1/events/{id}
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->requestor->request('GET', '/v1/events/' . self::pathId($id, 'An event id'));
    }

    /**
     * Every event matching the query, fetching further pages as you iterate.
     *
     * @param array{limit?: int, cursor?: string, type?: string, since?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $query = []): \Generator
    {
        self::eventQuery($query);

        return $this->paginate('/v1/events', $query, self::LIST_PARAMS);
    }

    /**
     * The same as all(), under the name the Node.js library uses.
     *
     * @param array{limit?: int, cursor?: string, type?: string, since?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $query = []): \Generator
    {
        return $this->all($query);
    }

    /**
     * @param array<array-key, mixed> $query
     *
     * @return array<string, string>
     */
    private static function eventQuery(array $query): array
    {
        $out = self::query($query, self::LIST_PARAMS);
        if (isset($out['type']) && !\in_array($out['type'], Webhook::EVENT_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('type must be one of: %s.', implode(', ', Webhook::EVENT_TYPES)));
        }

        return $out;
    }
}
