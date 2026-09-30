<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

class Refunds extends AbstractResource
{
    private const LIST_PARAMS = ['limit', 'cursor', 'paymentId'];

    /**
     * POST /v1/refunds
     *
     * @param array{paymentId: string, amountCents?: int, reason?: string} $params
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        self::assertAmount($params);

        return $this->requestor->request('POST', '/v1/refunds', [], $params, $options);
    }

    /**
     * GET /v1/refunds: one page.
     *
     * @param array{limit?: int, cursor?: string, paymentId?: string} $query
     *
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->requestor->request('GET', '/v1/refunds', self::query($query, self::LIST_PARAMS));
    }

    /**
     * GET /v1/refunds/{id}
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->requestor->request('GET', '/v1/refunds/' . self::pathId($id, 'A refund id'));
    }

    /**
     * Every refund matching the query, fetching further pages as you iterate.
     *
     * @param array{limit?: int, cursor?: string, paymentId?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $query = []): \Generator
    {
        self::query($query, self::LIST_PARAMS);

        return $this->paginate('/v1/refunds', $query, self::LIST_PARAMS);
    }

    /**
     * The same as all(), under the name the Node.js library uses.
     *
     * @param array{limit?: int, cursor?: string, paymentId?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $query = []): \Generator
    {
        return $this->all($query);
    }
}
