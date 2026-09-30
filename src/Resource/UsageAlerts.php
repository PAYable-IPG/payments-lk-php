<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

/**
 * Alerts when a customer's usage on a meter passes a threshold in a period: billing.alert.triggered.
 */
class UsageAlerts extends AbstractResource
{
    private const LIST_PARAMS = ['limit', 'cursor', 'meterId'];

    /**
     * POST /v1/usage_alerts
     *
     * @param array<string, mixed> $params meterId, title, threshold, and optionally customerId.
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/usage_alerts', [], $params, $options);
    }

    /**
     * GET /v1/usage_alerts: one page, newest first.
     *
     * @param array{limit?: int, cursor?: string, meterId?: string} $query
     *
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->requestor->request('GET', '/v1/usage_alerts', self::query($query, self::LIST_PARAMS));
    }

    /**
     * Every one matching the query, fetching further pages as you iterate.
     *
     * @param array{limit?: int, cursor?: string, meterId?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $query = []): \Generator
    {
        self::query($query, self::LIST_PARAMS);

        return $this->paginate('/v1/usage_alerts', $query, self::LIST_PARAMS);
    }

    /**
     * The same as all(), under the name the Node.js library uses.
     *
     * @param array{limit?: int, cursor?: string, meterId?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $query = []): \Generator
    {
        return $this->all($query);
    }

    /**
     * POST /v1/usage_alerts/{id}/archive: stops the alert firing for anyone new.
     *
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function archive(string $id, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/usage_alerts/' . self::pathId($id, 'A usage alert id') . '/archive', [], [], $options);
    }
}
