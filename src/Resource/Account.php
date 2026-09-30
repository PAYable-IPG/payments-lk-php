<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

class Account extends AbstractResource
{
    /**
     * GET /v1/account: the merchant behind the key,
     * {id, tradingName, status, applicationStatus, clearance: {tier, monthlyCapCents},
     * pricingPlan, plan, usage: {month, mode, plan, capCents, settledCents, reservedCents,
     * percent, resetsAt, refusedCount, refusedCents}, locale, liveEnabled, phase1Position}.
     *
     * @return array<string, mixed>
     */
    public function retrieve(): array
    {
        return $this->requestor->request('GET', '/v1/account');
    }
}
