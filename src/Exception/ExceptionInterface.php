<?php

declare(strict_types=1);

namespace PaymentsLk\Exception;

/**
 * Implemented by every exception this SDK throws on purpose, so a caller can
 * catch them all with one clause. InvalidArgumentException for a programming
 * mistake (a bad key, a float amount) is deliberately not part of it.
 */
interface ExceptionInterface extends \Throwable
{
}
