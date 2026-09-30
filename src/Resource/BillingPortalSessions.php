<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

/**
 * Addresses into the customer portal, where a customer manages their subscriptions, card and invoices.
 */
class BillingPortalSessions extends AbstractResource
{
    /**
     * POST /v1/billing_portal/sessions: send the customer to url straight away: it works for an hour.
     *
     * @param array<string, mixed> $params customerId and optionally returnUrl.
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/billing_portal/sessions', [], $params, $options);
    }
}
