<?php

declare(strict_types=1);

namespace PaymentsLk;

use PaymentsLk\Exception\SignatureVerificationException;

/**
 * Verifies the webhooks Payments.lk sends:
 *
 *     Payments-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">
 *
 * Verify the raw request body exactly as received, before any decoding.
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'Payments-Signature';
    public const DEFAULT_TOLERANCE_SECONDS = 300;
    public const MAX_HEADER_LENGTH = 1000;

    public const EVENT_PAYMENT_SUCCEEDED = 'payment.succeeded';
    public const EVENT_PAYMENT_FAILED = 'payment.failed';
    public const EVENT_CHECKOUT_EXPIRED = 'checkout.expired';
    public const EVENT_CARD_SAVED = 'card.saved';
    /** A payment succeeded but the card it was to keep was not saved; data is the payment, cardSave "failed". */
    public const EVENT_CARD_SAVE_FAILED = 'card.save_failed';
    public const EVENT_CARD_EXPIRING = 'card.expiring';
    public const EVENT_BILLING_ALERT_TRIGGERED = 'billing.alert.triggered';
    public const EVENT_SUBSCRIPTION_SCHEDULE_CREATED = 'subscription_schedule.created';
    public const EVENT_SUBSCRIPTION_SCHEDULE_COMPLETED = 'subscription_schedule.completed';
    public const EVENT_SUBSCRIPTION_SCHEDULE_RELEASED = 'subscription_schedule.released';
    public const EVENT_SUBSCRIPTION_SCHEDULE_CANCELED = 'subscription_schedule.canceled';
    public const EVENT_REFUND_SUCCEEDED = 'refund.succeeded';
    public const EVENT_REFUND_FAILED = 'refund.failed';
    public const EVENT_ACCOUNT_LIMIT_APPROACHING = 'account.limit.approaching';
    public const EVENT_ACCOUNT_LIMIT_REACHED = 'account.limit.reached';
    public const EVENT_ACCOUNT_LIMIT_RESET = 'account.limit.reset';
    public const EVENT_CUSTOMER_SUBSCRIPTION_CREATED = 'customer.subscription.created';
    public const EVENT_CUSTOMER_SUBSCRIPTION_UPDATED = 'customer.subscription.updated';
    public const EVENT_CUSTOMER_SUBSCRIPTION_DELETED = 'customer.subscription.deleted';
    public const EVENT_CUSTOMER_SUBSCRIPTION_TRIAL_WILL_END = 'customer.subscription.trial_will_end';
    public const EVENT_CUSTOMER_SUBSCRIPTION_PAUSED = 'customer.subscription.paused';
    public const EVENT_CUSTOMER_SUBSCRIPTION_RESUMED = 'customer.subscription.resumed';
    public const EVENT_INVOICE_CREATED = 'invoice.created';
    public const EVENT_INVOICE_FINALIZED = 'invoice.finalized';
    public const EVENT_INVOICE_SENT = 'invoice.sent';
    public const EVENT_INVOICE_OVERDUE = 'invoice.overdue';
    public const EVENT_CREDIT_NOTE_CREATED = 'credit_note.created';
    public const EVENT_ENTITLEMENTS_ACTIVE_ENTITLEMENT_SUMMARY_UPDATED = 'entitlements.active_entitlement_summary.updated';
    public const EVENT_INVOICE_PAID = 'invoice.paid';
    public const EVENT_INVOICE_PAYMENT_FAILED = 'invoice.payment_failed';
    public const EVENT_INVOICE_UPCOMING = 'invoice.upcoming';
    public const EVENT_INVOICE_VOIDED = 'invoice.voided';
    public const EVENT_INVOICE_MARKED_UNCOLLECTIBLE = 'invoice.marked_uncollectible';

    /**
     * The dashboard's Send test event: one endpoint only, whatever it subscribes
     * to, carrying data.object "ping" and data.endpointId. It is not in
     * EVENT_TYPES because GET /v1/events never lists it. Answer it with any 2xx.
     */
    public const EVENT_TEST_PING = 'test.ping';

    /** Every event type a webhook delivers and GET /v1/events lists. */
    public const EVENT_TYPES = [
        self::EVENT_PAYMENT_SUCCEEDED,
        self::EVENT_PAYMENT_FAILED,
        self::EVENT_CHECKOUT_EXPIRED,
        self::EVENT_CARD_SAVED,
        self::EVENT_CARD_SAVE_FAILED,
        self::EVENT_CARD_EXPIRING,
        self::EVENT_BILLING_ALERT_TRIGGERED,
        self::EVENT_SUBSCRIPTION_SCHEDULE_CREATED,
        self::EVENT_SUBSCRIPTION_SCHEDULE_COMPLETED,
        self::EVENT_SUBSCRIPTION_SCHEDULE_RELEASED,
        self::EVENT_SUBSCRIPTION_SCHEDULE_CANCELED,
        self::EVENT_REFUND_SUCCEEDED,
        self::EVENT_REFUND_FAILED,
        self::EVENT_ACCOUNT_LIMIT_APPROACHING,
        self::EVENT_ACCOUNT_LIMIT_REACHED,
        self::EVENT_ACCOUNT_LIMIT_RESET,
        self::EVENT_CUSTOMER_SUBSCRIPTION_CREATED,
        self::EVENT_CUSTOMER_SUBSCRIPTION_UPDATED,
        self::EVENT_CUSTOMER_SUBSCRIPTION_DELETED,
        self::EVENT_CUSTOMER_SUBSCRIPTION_TRIAL_WILL_END,
        self::EVENT_CUSTOMER_SUBSCRIPTION_PAUSED,
        self::EVENT_CUSTOMER_SUBSCRIPTION_RESUMED,
        self::EVENT_INVOICE_CREATED,
        self::EVENT_INVOICE_FINALIZED,
        self::EVENT_INVOICE_SENT,
        self::EVENT_INVOICE_OVERDUE,
        self::EVENT_CREDIT_NOTE_CREATED,
        self::EVENT_ENTITLEMENTS_ACTIVE_ENTITLEMENT_SUMMARY_UPDATED,
        self::EVENT_INVOICE_PAID,
        self::EVENT_INVOICE_PAYMENT_FAILED,
        self::EVENT_INVOICE_UPCOMING,
        self::EVENT_INVOICE_VOIDED,
        self::EVENT_INVOICE_MARKED_UNCOLLECTIBLE,
    ];

    /**
     * Checks the signature and freshness of a webhook and returns the decoded event,
     * {id: "evt_...", object: "event", type, mode, created, data}.
     *
     * @param string $payload The raw request body (file_get_contents('php://input')).
     * @param string|null $signatureHeader The Payments-Signature header value.
     * @param string $secret The endpoint's signing secret, whsec_...
     * @param int|null $now Unix seconds; defaults to time(). For tests.
     *
     * @return array<string, mixed>
     *
     * @throws SignatureVerificationException
     */
    public static function constructEvent(
        string $payload,
        ?string $signatureHeader,
        #[\SensitiveParameter]
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): array {
        if (!str_starts_with($secret, 'whsec_')) {
            throw new \InvalidArgumentException('The webhook secret is the endpoint signing secret, which starts with whsec_. It is not an API key.');
        }
        if ($toleranceSeconds < 1) {
            throw new \InvalidArgumentException('toleranceSeconds must be at least 1.');
        }

        if ($signatureHeader === null || trim($signatureHeader) === '') {
            throw new SignatureVerificationException('The request has no Payments-Signature header.');
        }
        if (\strlen($signatureHeader) > self::MAX_HEADER_LENGTH) {
            throw new SignatureVerificationException('The Payments-Signature header is longer than 1000 characters.');
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $pair = explode('=', trim($part), 2);
            if (\count($pair) !== 2) {
                continue;
            }
            [$key, $value] = $pair;
            if ($key === 't' && preg_match('/^[0-9]{1,12}$/D', $value) === 1) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && preg_match('/^[0-9a-f]{64}$/D', $value) === 1) {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null) {
            throw new SignatureVerificationException('The Payments-Signature header has no valid timestamp (t=<unix seconds>, 1 to 12 digits).');
        }
        if ($signatures === []) {
            throw new SignatureVerificationException('The Payments-Signature header has no valid v1 signature (64 lowercase hex characters).');
        }

        $now ??= time();
        if ($now - $timestamp > $toleranceSeconds) {
            throw new SignatureVerificationException(sprintf(
                'The webhook timestamp is more than %d seconds old, so it is refused as a possible replay.',
                $toleranceSeconds,
            ));
        }
        if ($timestamp - $now > $toleranceSeconds) {
            throw new SignatureVerificationException(sprintf(
                'The webhook timestamp is more than %d seconds in the future. Check this server\'s clock.',
                $toleranceSeconds,
            ));
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $matched = false;
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                $matched = true;
            }
        }
        if (!$matched) {
            throw new SignatureVerificationException(
                'No v1 signature matches this payload. Check that the endpoint secret is right and that the raw request body is verified before any parsing.',
            );
        }

        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SignatureVerificationException('The webhook body is correctly signed but is not valid JSON.', 0, $e);
        }
        if (!\is_array($event) || ($event['object'] ?? null) !== 'event') {
            throw new SignatureVerificationException('The webhook body is correctly signed but is not a Payments.lk event object.');
        }

        return $event;
    }
}
