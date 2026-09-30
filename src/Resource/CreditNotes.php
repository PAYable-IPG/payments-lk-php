<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

/**
 * Credit notes: part or all of an invoice taken back, off what is due, to the customer's credit, or refunded.
 */
class CreditNotes extends AbstractResource
{
    private const LIST_PARAMS = ['limit', 'cursor', 'invoiceId'];

    /**
     * POST /v1/credit_notes
     *
     * @param array<string, mixed> $params invoiceId, amountCents, and optionally reason, memo and refund.
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        self::assertAmount($params);

        return $this->requestor->request('POST', '/v1/credit_notes', [], $params, $options);
    }

    /**
     * GET /v1/credit_notes: one page, newest first.
     *
     * @param array{limit?: int, cursor?: string, invoiceId?: string} $query
     *
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->requestor->request('GET', '/v1/credit_notes', self::query($query, self::LIST_PARAMS));
    }

    /**
     * Every one matching the query, fetching further pages as you iterate.
     *
     * @param array{limit?: int, cursor?: string, invoiceId?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $query = []): \Generator
    {
        self::query($query, self::LIST_PARAMS);

        return $this->paginate('/v1/credit_notes', $query, self::LIST_PARAMS);
    }

    /**
     * The same as all(), under the name the Node.js library uses.
     *
     * @param array{limit?: int, cursor?: string, invoiceId?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $query = []): \Generator
    {
        return $this->all($query);
    }

    /**
     * GET /v1/credit_notes/{id}
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->requestor->request('GET', '/v1/credit_notes/' . self::pathId($id, 'A credit note id'));
    }
}
