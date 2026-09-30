<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

/**
 * Links anyone can open to subscribe to your prices: share one, or put it behind a button.
 */
class SubscriptionLinks extends AbstractResource
{
    private const LIST_PARAMS = ['limit', 'cursor', 'active'];

    /**
     * POST /v1/subscription_links
     *
     * @param array<string, mixed> $params name, priceIds, and optionally trialDays, allowPromotionCodes and successUrl.
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/subscription_links', [], $params, $options);
    }

    /**
     * GET /v1/subscription_links: one page, newest first.
     *
     * @param array{limit?: int, cursor?: string, active?: string} $query
     *
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->requestor->request('GET', '/v1/subscription_links', self::query($query, self::LIST_PARAMS));
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

        return $this->paginate('/v1/subscription_links', $query, self::LIST_PARAMS);
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
     * GET /v1/subscription_links/{id}
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->requestor->request('GET', '/v1/subscription_links/' . self::pathId($id, 'A subscription link id'));
    }

    /**
     * POST /v1/subscription_links/{id}: renames it, changes its trial, codes or landing page, or turns it off (active false). Its prices never change.
     *
     * @param array<string, mixed> $params name, active, trialDays, allowPromotionCodes or successUrl.
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $params, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/subscription_links/' . self::pathId($id, 'A subscription link id'), [], $params, $options);
    }
}
