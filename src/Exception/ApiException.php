<?php

declare(strict_types=1);

namespace PaymentsLk\Exception;

/**
 * The API answered with an error. The body on the wire is
 * {code, message, traceId, reason?, docUrl?, fields?: [{path, message}]};
 * quote the trace id when you contact support. getMessage() is the API's
 * sentence followed by the documentation link when there is one;
 * getApiMessage() is the sentence alone.
 */
class ApiException extends \RuntimeException implements ExceptionInterface
{
    private readonly ?string $docUrl;

    /**
     * @param list<array{path: string, message: string}> $fields
     * @param string|null $docUrl Kept only when it is an https address on payments.lk.
     */
    public function __construct(
        string $message,
        private readonly int $httpStatus,
        private readonly ?string $errorCode = null,
        private readonly ?string $traceId = null,
        private readonly array $fields = [],
        ?\Throwable $previous = null,
        private readonly ?string $reason = null,
        ?string $docUrl = null,
        private readonly ?string $apiMessage = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->docUrl = $docUrl !== null && self::isDocUrl($docUrl) ? $docUrl : null;
    }

    /**
     * Whether a docUrl from an error body may be shown. The body is whatever
     * answered the request, which behind a proxy or a wrong apiBase is not
     * always the API, and the address ends up in the exception's message, in
     * logs and in front of people. So it is kept only when it is an https
     * address on payments.lk or a subdomain of it, written plainly: the host
     * straight after the scheme (no user, password or port), then a path, query
     * or fragment of printable ASCII with no space and no backslash. One
     * pattern rather than parse_url(), which reads some hosts differently
     * from a browser.
     *
     * @internal
     */
    public static function isDocUrl(string $value): bool
    {
        return \strlen($value) <= 500
            && preg_match('~^https://(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*payments\.lk(?:[/?#][\x21-\x5b\x5d-\x7e]*)?$~Di', $value) === 1;
    }

    /** A finer, stable reason in snake_case, such as merchant_not_live; null when the API gave none. Branch on this or on the code, never on the message. */
    public function getReason(): ?string
    {
        return $this->reason;
    }

    /** The documentation page for this error, always an https address on payments.lk; null when the body named none, or named anywhere else. */
    public function getDocUrl(): ?string
    {
        return $this->docUrl;
    }

    /** The API's sentence on its own, without the documentation link. */
    public function getApiMessage(): string
    {
        return $this->apiMessage ?? $this->getMessage();
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /** VALIDATION_FAILED, UNAUTHENTICATED and so on; null when the body was not JSON. */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getTraceId(): ?string
    {
        return $this->traceId;
    }

    /**
     * Field level problems on a validation error, for example
     * [['path' => 'amountCents', 'message' => '...']].
     *
     * @return list<array{path: string, message: string}>
     */
    public function getFields(): array
    {
        return $this->fields;
    }
}
