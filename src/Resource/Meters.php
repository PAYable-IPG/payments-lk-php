<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

/**
 * Meters add up the usage you report, for metered prices to bill at the end of each period.
 */
class Meters extends AbstractResource
{
    private const LIST_PARAMS = ['limit', 'cursor'];
    private const SUMMARY_PARAMS = ['customerId', 'start', 'end'];

    /**
     * POST /v1/meters
     *
     * @param array<string, mixed> $params name, eventName, and optionally aggregation (sum, count or last).
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/meters', [], $params, $options);
    }

    /**
     * GET /v1/meters: one page, newest first.
     *
     * @param array{limit?: int, cursor?: string} $query
     *
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->requestor->request('GET', '/v1/meters', self::query($query, self::LIST_PARAMS));
    }

    /**
     * Every one matching the query, fetching further pages as you iterate.
     *
     * @param array{limit?: int, cursor?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $query = []): \Generator
    {
        self::query($query, self::LIST_PARAMS);

        return $this->paginate('/v1/meters', $query, self::LIST_PARAMS);
    }

    /**
     * The same as all(), under the name the Node.js library uses.
     *
     * @param array{limit?: int, cursor?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $query = []): \Generator
    {
        return $this->all($query);
    }

    /**
     * GET /v1/meters/{id}
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->requestor->request('GET', '/v1/meters/' . self::pathId($id, 'A meter id'));
    }

    /**
     * POST /v1/meters/{id}: renames it, or turns it off (active false: its events are refused) or back on.
     *
     * @param array<string, mixed> $params name or active.
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $params, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/meters/' . self::pathId($id, 'A meter id'), [], $params, $options);
    }

    /**
     * GET /v1/meters/{id}/event_summaries: a customer's usage on the meter between two times, added up the meter's way.
     *
     * @param array{customerId: string, start: string, end: string} $query
     *
     * @return array<string, mixed>
     */
    public function eventSummary(string $id, array $query): array
    {
        return $this->requestor->request('GET', '/v1/meters/' . self::pathId($id, 'A meter id') . '/event_summaries', self::query($query, self::SUMMARY_PARAMS));
    }
}
