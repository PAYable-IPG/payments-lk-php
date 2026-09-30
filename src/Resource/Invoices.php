<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

/**
 * Invoices: those subscriptions make each period, and standalone ones you make from invoice items.
 */
class Invoices extends AbstractResource
{
    private const LIST_PARAMS = ['limit', 'cursor', 'subscriptionId', 'customerId', 'status'];
    private const UPCOMING_PARAMS = ['subscriptionId'];

    /**
     * POST /v1/invoices: a standalone draft of the customer's pending invoice items; finalize it to send or charge it.
     *
     * @param array<string, mixed> $params customerId, and optionally collectionMethod, daysUntilDue and memo.
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/invoices', [], $params, $options);
    }

    /**
     * GET /v1/invoices: one page, newest first.
     *
     * @param array{limit?: int, cursor?: string, subscriptionId?: string, customerId?: string, status?: string} $query
     *
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->requestor->request('GET', '/v1/invoices', self::query($query, self::LIST_PARAMS));
    }

    /**
     * Every one matching the query, fetching further pages as you iterate.
     *
     * @param array{limit?: int, cursor?: string, subscriptionId?: string, customerId?: string, status?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $query = []): \Generator
    {
        self::query($query, self::LIST_PARAMS);

        return $this->paginate('/v1/invoices', $query, self::LIST_PARAMS);
    }

    /**
     * The same as all(), under the name the Node.js library uses.
     *
     * @param array{limit?: int, cursor?: string, subscriptionId?: string, customerId?: string, status?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $query = []): \Generator
    {
        return $this->all($query);
    }

    /**
     * GET /v1/invoices/{id}
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->requestor->request('GET', '/v1/invoices/' . self::pathId($id, 'An invoice id'));
    }

    /**
     * GET /v1/invoices/upcoming: the subscription's next invoice as it stands now, usage so far included. Nothing is saved.
     *
     * @param array{subscriptionId: string} $query
     *
     * @return array<string, mixed>
     */
    public function upcoming(array $query): array
    {
        return $this->requestor->request('GET', '/v1/invoices/upcoming', self::query($query, self::UPCOMING_PARAMS));
    }

    /**
     * POST /v1/invoices/preview: what a change to a subscription would invoice, prorations included. Nothing is saved.
     *
     * @param array<string, mixed> $params subscriptionId and the change: items, or priceId and quantity, with prorationBehavior and prorationDate.
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function preview(array $params, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/invoices/preview', [], $params, $options);
    }

    /**
     * POST /v1/invoices/{id}/send: emails an open invoice's page to the customer again.
     *
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function send(string $id, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/invoices/' . self::pathId($id, 'An invoice id') . '/send', [], [], $options);
    }

    /**
     * POST /v1/invoices/{id}/pay: charges an open invoice to the card on file within a minute, whatever the retry schedule says.
     *
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function pay(string $id, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/invoices/' . self::pathId($id, 'An invoice id') . '/pay', [], [], $options);
    }

    /**
     * POST /v1/invoices/{id}/finalize: numbers a draft and opens it: charged to the card on file, or emailed to be paid by its due date.
     *
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function finalize(string $id, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/invoices/' . self::pathId($id, 'An invoice id') . '/finalize', [], [], $options);
    }

    /**
     * POST /v1/invoices/{id}/void: cancels a draft or open invoice: nothing is owed on it and nothing more is tried.
     *
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function void(string $id, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/invoices/' . self::pathId($id, 'An invoice id') . '/void', [], [], $options);
    }

    /**
     * POST /v1/invoices/{id}/mark_uncollectible: marks an open invoice uncollectible: it was owed, and will never be tried again.
     *
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function markUncollectible(string $id, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/invoices/' . self::pathId($id, 'An invoice id') . '/mark_uncollectible', [], [], $options);
    }
}
