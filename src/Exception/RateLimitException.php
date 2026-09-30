<?php

declare(strict_types=1);

namespace PaymentsLk\Exception;

/** HTTP 429, after the SDK's own retries were used up. */
class RateLimitException extends ApiException
{
}
