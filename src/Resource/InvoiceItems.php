<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

/**
 * One-off lines waiting for a customer's next invoice, or added to a draft.
 */
class InvoiceItems extends AbstractResource
{
    private const LIST_PARAMS = ['limit', 'cursor', 'customerId', 'pending'];

    /**
     * POST /v1/invoice_items
     *
     * @param array<string, mixed> $params customerId, description, unitAmountCents, and optionally quantity, discountable, subscriptionId and invoiceId.
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/invoice_items', [], $params, $options);
    }

    /**
     * GET /v1/invoice_items: one page, newest first.
     *
     * @param array{limit?: int, cursor?: string, customerId?: string, pending?: string} $query
     *
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->requestor->request('GET', '/v1/invoice_items', self::query($query, self::LIST_PARAMS));
    }

    /**
     * Every one matching the query, fetching further pages as you iterate.
     *
     * @param array{limit?: int, cursor?: string, customerId?: string, pending?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $query = []): \Generator
    {
        self::query($query, self::LIST_PARAMS);

        return $this->paginate('/v1/invoice_items', $query, self::LIST_PARAMS);
    }

    /**
     * The same as all(), under the name the Node.js library uses.
     *
     * @param array{limit?: int, cursor?: string, customerId?: string, pending?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $query = []): \Generator
    {
        return $this->all($query);
    }

    /**
     * DELETE /v1/invoice_items/{id}: deletes an item no invoice has taken yet.
     *
     * @return array<string, mixed>
     */
    public function delete(string $id): array
    {
        return $this->requestor->request('DELETE', '/v1/invoice_items/' . self::pathId($id, 'An invoice item id'));
    }
}
