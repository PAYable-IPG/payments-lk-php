<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

/**
 * What you charge each period: per unit, per package, tiered or metered, each on a product.
 */
class Prices extends AbstractResource
{
    private const LIST_PARAMS = ['limit', 'cursor', 'active'];

    /**
     * POST /v1/prices
     *
     * @param array<string, mixed> $params productId or product, interval, and unitAmountCents or tiers; see the API reference for billingScheme, tiersMode, packageSize, usageType and meterId.
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/prices', [], $params, $options);
    }

    /**
     * GET /v1/prices: one page, newest first; active is "true" or "false".
     *
     * @param array{limit?: int, cursor?: string, active?: string} $query
     *
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->requestor->request('GET', '/v1/prices', self::query($query, self::LIST_PARAMS));
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

        return $this->paginate('/v1/prices', $query, self::LIST_PARAMS);
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
     * GET /v1/prices/{id}
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->requestor->request('GET', '/v1/prices/' . self::pathId($id, 'A price id'));
    }
}
