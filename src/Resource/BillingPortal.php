<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

use PaymentsLk\Http\Requestor;

/**
 * The customer portal, where your customers manage their own subscriptions.
 */
final class BillingPortal
{
    public readonly BillingPortalSessions $sessions;

    public function __construct(Requestor $requestor)
    {
        $this->sessions = new BillingPortalSessions($requestor);
    }
}
