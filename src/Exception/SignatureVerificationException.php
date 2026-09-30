<?php

declare(strict_types=1);

namespace PaymentsLk\Exception;

/** A webhook request could not be proven to come from Payments.lk. Answer it with HTTP 400. */
class SignatureVerificationException extends \RuntimeException implements ExceptionInterface
{
}
