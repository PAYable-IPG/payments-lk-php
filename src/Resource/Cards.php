<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

/**
 * Cards your customers chose to keep on file at a checkout. A card id is not a
 * card number: it works only with your key, in the mode it was saved in.
 */
class Cards extends AbstractResource
{
    private const LIST_PARAMS = ['limit', 'cursor', 'customerEmail'];

    /**
     * GET /v1/cards: one page of the cards still usable, newest first.
     *
     * @param array{limit?: int, cursor?: string, customerEmail?: string} $query
     *
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->requestor->request('GET', '/v1/cards', self::query($query, self::LIST_PARAMS));
    }

    /**
     * GET /v1/cards/{id}: one card, deleted ones included, with its expiry
     * month and the consent the customer gave when they kept it.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->requestor->request('GET', '/v1/cards/' . self::pathId($id, 'A card id'));
    }

    /**
     * POST /v1/cards/{id}/charge
     *
     * @param array{amountCents: int, description: string, reference?: string} $params
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function charge(string $id, array $params, array $options = []): array
    {
        self::assertAmount($params);

        return $this->requestor->request('POST', '/v1/cards/' . self::pathId($id, 'A card id') . '/charge', [], $params, $options);
    }

    /**
     * DELETE /v1/cards/{id}: removes the card here and its token at the
     * processor. Removing one already removed answers the same card.
     *
     * @return array<string, mixed>
     */
    public function delete(string $id): array
    {
        return $this->requestor->request('DELETE', '/v1/cards/' . self::pathId($id, 'A card id'));
    }

    /**
     * Every card matching the query, fetching further pages as you iterate.
     *
     * @param array{limit?: int, cursor?: string, customerEmail?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $query = []): \Generator
    {
        self::query($query, self::LIST_PARAMS);

        return $this->paginate('/v1/cards', $query, self::LIST_PARAMS);
    }

    /**
     * The same as all(), under the name the Node.js library uses.
     *
     * @param array{limit?: int, cursor?: string, customerEmail?: string} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $query = []): \Generator
    {
        return $this->all($query);
    }
}
