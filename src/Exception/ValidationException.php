<?php

declare(strict_types=1);

namespace PaymentsLk\Exception;

/** HTTP 400 or 422: the request was refused; getFields() says which fields and why. */
class ValidationException extends ApiException
{
}
