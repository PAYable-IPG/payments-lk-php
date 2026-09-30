<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

/**
 * Plans that change on their own: phases of prices, trials and coupons, and installments that end after a set number of payments.
 */
class SubscriptionSchedules extends AbstractResource
{
    private const LIST_PARAMS = ['limit', 'cursor', 'customerId', 'status'];

    /**
     * POST /v1/subscription_schedules
     *
     * @param array<string, mixed> $params customerId, phases, and optionally startDate, endBehavior and reference.
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/subscription_schedules', [], $params, $options);
    }

    /**
     * GET /v1/subscription_schedules: one page, newest first.
     *
     * @param array{limit?: int, cursor?: string, customerId?: string, status?: string} $query
     *
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->requestor->request('GET', '/v1/subscription_schedules', self::query($query, self::LIST_PARAMS));
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

        return $this->paginate('/v1/subscription_schedules', $query, self::LIST_PARAMS);
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
     * GET /v1/subscription_schedules/{id}
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->requestor->request('GET', '/v1/subscription_schedules/' . self::pathId($id, 'A subscription schedule id'));
    }

    /**
     * POST /v1/subscription_schedules/{id}/cancel: a schedule not started never starts; a running one cancels its subscription now.
     *
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function cancel(string $id, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/subscription_schedules/' . self::pathId($id, 'A subscription schedule id') . '/cancel', [], [], $options);
    }

    /**
     * POST /v1/subscription_schedules/{id}/release: lets go of the subscription, which carries on at the prices it has now.
     *
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function release(string $id, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/subscription_schedules/' . self::pathId($id, 'A subscription schedule id') . '/release', [], [], $options);
    }
}
