<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

/**
 * Features your app checks by lookup key. Give them to products with products->attachFeature().
 */
class EntitlementFeatures extends AbstractResource
{
    private const LIST_PARAMS = ['limit', 'cursor'];

    /**
     * POST /v1/entitlements/features
     *
     * @param array<string, mixed> $params name and lookupKey.
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/entitlements/features', [], $params, $options);
    }

    /**
     * GET /v1/entitlements/features: one page, newest first.
     *
     * @param array{limit?: int, cursor?: string} $query
     *
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->requestor->request('GET', '/v1/entitlements/features', self::query($query, self::LIST_PARAMS));
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

        return $this->paginate('/v1/entitlements/features', $query, self::LIST_PARAMS);
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
}
