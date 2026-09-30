<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

/**
 * Tax rates added to or included in subscriptions' invoices.
 */
class TaxRates extends AbstractResource
{
    private const LIST_PARAMS = ['limit', 'cursor', 'active'];

    /**
     * POST /v1/tax_rates
     *
     * @param array<string, mixed> $params displayName, percentageBps, and optionally description and inclusive.
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/tax_rates', [], $params, $options);
    }

    /**
     * GET /v1/tax_rates: one page, newest first.
     *
     * @param array{limit?: int, cursor?: string, active?: string} $query
     *
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->requestor->request('GET', '/v1/tax_rates', self::query($query, self::LIST_PARAMS));
    }

    /**
     * Every one matching the query, fetching further pages as you iterate.
     *
     * @param array{limit?: int, cursor?: string, active?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $query = []): \Generator
    {
        self::query($query, self::LIST_PARAMS);

        return $this->paginate('/v1/tax_rates', $query, self::LIST_PARAMS);
    }

    /**
     * The same as all(), under the name the Node.js library uses.
     *
     * @param array{limit?: int, cursor?: string, active?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $query = []): \Generator
    {
        return $this->all($query);
    }

    /**
     * GET /v1/tax_rates/{id}
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->requestor->request('GET', '/v1/tax_rates/' . self::pathId($id, 'A tax rate id'));
    }

    /**
     * POST /v1/tax_rates/{id}: renames it or archives it (active false). Its percentage never changes.
     *
     * @param array<string, mixed> $params displayName, description or active.
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $params, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/tax_rates/' . self::pathId($id, 'A tax rate id'), [], $params, $options);
    }
}
