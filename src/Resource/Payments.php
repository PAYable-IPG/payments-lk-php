<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

class Payments extends AbstractResource
{
    private const LIST_PARAMS = ['limit', 'cursor', 'status'];

    /**
     * GET /v1/payments: one page, {object: "list", data, hasMore, nextCursor}.
     *
     * @param array{limit?: int, cursor?: string, status?: string} $query
     *
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->requestor->request('GET', '/v1/payments', self::query($query, self::LIST_PARAMS));
    }

    /**
     * GET /v1/payments/{id}
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->requestor->request('GET', '/v1/payments/' . self::pathId($id, 'A payment id'));
    }

    /**
     * Every payment matching the query, fetching further pages as you iterate.
     *
     * @param array{limit?: int, cursor?: string, status?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $query = []): \Generator
    {
        self::query($query, self::LIST_PARAMS);

        return $this->paginate('/v1/payments', $query, self::LIST_PARAMS);
    }

    /**
     * The same as all(), under the name the Node.js library uses.
     *
     * @param array{limit?: int, cursor?: string, status?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $query = []): \Generator
    {
        return $this->all($query);
    }
}
