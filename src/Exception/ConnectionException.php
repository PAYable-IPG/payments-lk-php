<?php

declare(strict_types=1);

namespace PaymentsLk\Exception;

/**
 * The request did not get an HTTP response: DNS, TCP, TLS or a timeout. A POST
 * may or may not have reached the API; retrying it with the same
 * Idempotency-Key is safe.
 */
class ConnectionException extends \RuntimeException implements ExceptionInterface
{
}
