<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

/**
 * Customers paying a price every period, renewed and charged for you.
 */
class Subscriptions extends AbstractResource
{
    private const LIST_PARAMS = ['limit', 'cursor', 'customerId', 'status'];

    /**
     * POST /v1/subscriptions: with a card on file its first invoice is charged within a minute; without one, send the customer to checkoutUrl.
     *
     * @param array<string, mixed> $params customerId or customer, priceId or items, and optionally cardId, trialDays, couponId, taxRateIds, collectionMethod, billingCycleAnchor ('now', 'first_of_month' or a time at most one period ahead) and more.
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/subscriptions', [], $params, $options);
    }

    /**
     * GET /v1/subscriptions: one page, newest first.
     *
     * @param array{limit?: int, cursor?: string, customerId?: string, status?: string} $query
     *
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->requestor->request('GET', '/v1/subscriptions', self::query($query, self::LIST_PARAMS));
    }

    /**
     * Every one matching the query, fetching further pages as you iterate.
     *
     * @param array{limit?: int, cursor?: string, customerId?: string, status?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $query = []): \Generator
    {
        self::query($query, self::LIST_PARAMS);

        return $this->paginate('/v1/subscriptions', $query, self::LIST_PARAMS);
    }

    /**
     * The same as all(), under the name the Node.js library uses.
     *
     * @param array{limit?: int, cursor?: string, customerId?: string, status?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $query = []): \Generator
    {
        return $this->all($query);
    }

    /**
     * GET /v1/subscriptions/{id}
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->requestor->request('GET', '/v1/subscriptions/' . self::pathId($id, 'A subscription id'));
    }

    /**
     * POST /v1/subscriptions/{id}: changes prices and quantities (with prorations), the card, the coupon and tax rates, a trial, pausing and a cancellation date.
     *
     * @param array<string, mixed> $params the fields to change; preview a change first with invoices->preview().
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $params, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/subscriptions/' . self::pathId($id, 'A subscription id'), [], $params, $options);
    }

    /**
     * POST /v1/subscriptions/{id}/cancel: ends it now, or at the end of the period paid for with atPeriodEnd.
     *
     * @param array<string, mixed> $params atPeriodEnd, feedback and comment, all optional.
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function cancel(string $id, array $params = [], array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/subscriptions/' . self::pathId($id, 'A subscription id') . '/cancel', [], $params, $options);
    }

    /**
     * POST /v1/subscriptions/{id}/resume: withdraws a cancellation set for the period's end or a date, or starts a paused subscription again.
     *
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function resume(string $id, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/subscriptions/' . self::pathId($id, 'A subscription id') . '/resume', [], [], $options);
    }
}
