<?php

declare(strict_types=1);

namespace PaymentsLk\Exception;

/** HTTP 403: the key is valid but may not do this (a pk_ key outside checkout creation, for example). */
class PermissionException extends ApiException
{
}
