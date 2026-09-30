<?php

declare(strict_types=1);

namespace PaymentsLk\Resource;

/**
 * Usage you report, counted by the meter whose eventName it carries.
 */
class MeterEvents extends AbstractResource
{
    /**
     * POST /v1/meter_events: send your own identifier so a retry never counts twice.
     *
     * @param array<string, mixed> $params eventName, customerId, and optionally value, timestamp and identifier.
     * @param array{idempotencyKey?: string} $options
     *
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        return $this->requestor->request('POST', '/v1/meter_events', [], $params, $options);
    }
}
