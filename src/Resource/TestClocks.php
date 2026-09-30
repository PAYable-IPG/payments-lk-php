<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

/**
 * Sandbox only: runs a test subscription's time ahead, to see renewals, trials and retries without waiting.
 */
class TestClocks extends AbstractResource
{
    /**
     * POST /v1/test_clocks/advance
     *
     * @param array<string, mixed> $params subscriptionId and seconds (60 seconds to a year).
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function advance(array $params, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/test_clocks/advance', [], $params, $options);
    }
}
