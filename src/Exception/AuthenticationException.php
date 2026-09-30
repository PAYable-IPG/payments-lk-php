<?php

declare(strict_types=1);

namespace PaymentsLk\Exception;

/** HTTP 401: the key is missing, revoked or wrong for this mode. */
class AuthenticationException extends ApiException
{
}
