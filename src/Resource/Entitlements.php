<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

use PaymentsLk\Http\Requestor;

/**
 * Features, and which of them each customer has now. entitlements.active_entitlement_summary.updated says when that changes.
 */
final class Entitlements
{
    public readonly EntitlementFeatures $features;
    public readonly ActiveEntitlements $activeEntitlements;

    public function __construct(Requestor $requestor)
    {
        $this->features = new EntitlementFeatures($requestor);
        $this->activeEntitlements = new ActiveEntitlements($requestor);
    }
}
