<?php

declare(strict_types=1);

namespace PaymentsLk\Exception;

/** HTTP 5xx (INTERNAL, NOT_SUPPORTED, or a gateway error), after any retries. */
class ServerException extends ApiException
{
}
