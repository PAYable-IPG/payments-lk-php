<?php

declare(strict_types=1);

namespace PaymentsLk\Exception;

/**
 * HTTP 409: the change conflicts with the current state, or an Idempotency-Key
 * was reused with a different request. Never retried.
 */
class ConflictException extends ApiException
{
}
