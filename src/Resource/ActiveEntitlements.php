<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

/**
 * The features a customer has now, from the products on their running subscriptions.
 */
class ActiveEntitlements extends AbstractResource
{
    private const LIST_PARAMS = ['customerId'];

    /**
     * GET /v1/entitlements/active_entitlements: the customer's features, by lookup key.
     *
     * @param array{customerId: string} $query
     *
     * @return array<string, mixed>
     */
    public function list(array $query): array
    {
        return $this->requestor->request('GET', '/v1/entitlements/active_entitlements', self::query($query, self::LIST_PARAMS));
    }
}
