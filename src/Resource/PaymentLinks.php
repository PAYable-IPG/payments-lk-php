<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

class PaymentLinks extends AbstractResource
{
    /**
     * POST /v1/payment_links
     *
     * @param array{title: string, description?: string, amountCents: int} $params
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        self::assertAmount($params);

        return $this->requestor->request('POST', '/v1/payment_links', [], $params, $options);
    }

    /**
     * GET /v1/payment_links
     *
     * @return array<string, mixed>
     */
    public function list(): array
    {
        return $this->requestor->request('GET', '/v1/payment_links');
    }

    /**
     * DELETE /v1/payment_links/{id}: the link stops taking payments.
     *
     * @return array<string, mixed>
     */
    public function retire(string $id): array
    {
        return $this->requestor->request('DELETE', '/v1/payment_links/' . self::pathId($id, 'A payment link id'));
    }
}
