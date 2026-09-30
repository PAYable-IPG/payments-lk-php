<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

class Checkouts extends AbstractResource
{
    /**
     * POST /v1/checkouts. Redirect the customer to the returned url.
     *
     * Send amountCents and description, or lineItems (1 to 10 lines of name,
     * optional description, unitAmountCents and quantity) and let the API add
     * them up, with description then optional. Not both. locale (en, si or ta)
     * opens the hosted checkout in that language.
     *
     * @param array{amountCents?: int, description?: string, lineItems?: list<array{name: string, description?: string, unitAmountCents: int, quantity: int}>, reference?: string, customer?: array{name: string, email: string, phone?: string}, successUrl?: string, cancelUrl?: string, saveCard?: bool, locale?: string} $params
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        self::assertAmount($params);
        if (\array_key_exists('lineItems', $params)) {
            if (\array_key_exists('amountCents', $params)) {
                throw new \InvalidArgumentException('Send amountCents or lineItems, not both: with lineItems the API works out the total.');
            }
            if (!\is_array($params['lineItems']) || !array_is_list($params['lineItems']) || $params['lineItems'] === []) {
                throw new \InvalidArgumentException('lineItems must be a list of 1 to 10 lines.');
            }
            foreach ($params['lineItems'] as $line) {
                if (!\is_array($line) || !\is_int($line['unitAmountCents'] ?? null) || !\is_int($line['quantity'] ?? null)) {
                    throw new \InvalidArgumentException('Each line item needs an integer unitAmountCents (LKR cents: Rs. 10.00 is 1000) and an integer quantity.');
                }
            }
        }

        return $this->requestor->request('POST', '/v1/checkouts', [], $params, $options);
    }

    /**
     * GET /v1/checkouts/{id}
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->requestor->request('GET', '/v1/checkouts/' . self::pathId($id, 'A checkout id'));
    }
}
