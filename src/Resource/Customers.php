<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

/**
 * The people you bill, found or made by email, and the credit each holds toward their next invoices.
 */
class Customers extends AbstractResource
{
    private const LIST_PARAMS = ['limit', 'cursor', 'email'];
    private const BALANCE_PARAMS = ['limit', 'cursor'];

    /**
     * POST /v1/customers
     *
     * @param array<string, mixed> $params email, and optionally name, phone and locale. A customer with this email in this mode is answered, not made again.
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/customers', [], $params, $options);
    }

    /**
     * GET /v1/customers: one page, newest first.
     *
     * @param array{limit?: int, cursor?: string, email?: string} $query
     *
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->requestor->request('GET', '/v1/customers', self::query($query, self::LIST_PARAMS));
    }

    /**
     * Every one matching the query, fetching further pages as you iterate.
     *
     * @param array{limit?: int, cursor?: string, email?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $query = []): \Generator
    {
        self::query($query, self::LIST_PARAMS);

        return $this->paginate('/v1/customers', $query, self::LIST_PARAMS);
    }

    /**
     * The same as all(), under the name the Node.js library uses.
     *
     * @param array{limit?: int, cursor?: string, email?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $query = []): \Generator
    {
        return $this->all($query);
    }

    /**
     * GET /v1/customers/{id}
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->requestor->request('GET', '/v1/customers/' . self::pathId($id, 'A customer id'));
    }

    /**
     * POST /v1/customers/{id}/balance_transactions: adds credit to the customer's balance (a positive amountCents) or takes it away (negative, never below zero).
     *
     * @param array<string, mixed> $params amountCents and an optional description.
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function createBalanceTransaction(string $id, array $params, array $options = []): array
    {
        self::assertAmount($params);

        return $this->requestor->request('POST', '/v1/customers/' . self::pathId($id, 'A customer id') . '/balance_transactions', [], $params, $options);
    }

    /**
     * GET /v1/customers/{id}/balance_transactions: one page, newest first.
     *
     * @param array{limit?: int, cursor?: string} $query
     *
     * @return array<string, mixed>
     */
    public function listBalanceTransactions(string $id, array $query = []): array
    {
        return $this->requestor->request('GET', '/v1/customers/' . self::pathId($id, 'A customer id') . '/balance_transactions', self::query($query, self::BALANCE_PARAMS));
    }
}
