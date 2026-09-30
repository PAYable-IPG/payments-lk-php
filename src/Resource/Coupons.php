<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

/**
 * Discounts on subscriptions' invoices, once, for some months or forever, with an optional code customers type.
 */
class Coupons extends AbstractResource
{
    private const LIST_PARAMS = ['limit', 'cursor', 'active'];

    /**
     * POST /v1/coupons
     *
     * @param array<string, mixed> $params name, duration, and percentOffBps or amountOffCents; optionally durationInMonths, maxRedemptions, redeemBy and code.
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/coupons', [], $params, $options);
    }

    /**
     * GET /v1/coupons: one page, newest first.
     *
     * @param array{limit?: int, cursor?: string, active?: string} $query
     *
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->requestor->request('GET', '/v1/coupons', self::query($query, self::LIST_PARAMS));
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

        return $this->paginate('/v1/coupons', $query, self::LIST_PARAMS);
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
     * GET /v1/coupons/{id}
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->requestor->request('GET', '/v1/coupons/' . self::pathId($id, 'A coupon id'));
    }

    /**
     * DELETE /v1/coupons/{id}: stops new subscriptions using it. Subscriptions it already discounts keep the discount.
     *
     * @return array<string, mixed>
     */
    public function archive(string $id): array
    {
        return $this->requestor->request('DELETE', '/v1/coupons/' . self::pathId($id, 'A coupon id'));
    }
}
