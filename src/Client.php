<?php

declare(strict_types=1);

namespace PaymentsLk;

use PaymentsLk\Http\CurlTransport;
use PaymentsLk\Http\Requestor;
use PaymentsLk\Http\TransportInterface;
use PaymentsLk\Resource\Account;
use PaymentsLk\Resource\BillingPortal;
use PaymentsLk\Resource\Cards;
use PaymentsLk\Resource\Checkouts;
use PaymentsLk\Resource\Coupons;
use PaymentsLk\Resource\CreditNotes;
use PaymentsLk\Resource\Customers;
use PaymentsLk\Resource\Entitlements;
use PaymentsLk\Resource\Events;
use PaymentsLk\Resource\InvoiceItems;
use PaymentsLk\Resource\Invoices;
use PaymentsLk\Resource\MeterEvents;
use PaymentsLk\Resource\Meters;
use PaymentsLk\Resource\PaymentLinks;
use PaymentsLk\Resource\Payments;
use PaymentsLk\Resource\Prices;
use PaymentsLk\Resource\Products;
use PaymentsLk\Resource\Refunds;
use PaymentsLk\Resource\SubscriptionLinks;
use PaymentsLk\Resource\SubscriptionSchedules;
use PaymentsLk\Resource\Subscriptions;
use PaymentsLk\Resource\TaxRates;
use PaymentsLk\Resource\TestClocks;
use PaymentsLk\Resource\UsageAlerts;

/**
 * The Payments.lk API client.
 *
 *     $payments = new PaymentsLk\Client('sk_test_...');
 *     $checkout = $payments->checkouts->create(['amountCents' => 250000, 'description' => 'Order 1042']);
 */
final class Client
{
    public const VERSION = '0.4.1';
    public const DEFAULT_API_BASE = 'https://api.payments.lk';
    public const DEFAULT_TIMEOUT = 30.0;
    public const DEFAULT_CONNECT_TIMEOUT = 10.0;
    public const DEFAULT_MAX_RETRIES = 2;
    public const MAX_RETRIES_LIMIT = 10;

    private const OPTIONS = ['apiBase', 'timeout', 'connectTimeout', 'maxRetries', 'transport', 'sleep'];
    private const KEY_PATTERN = '/^(sk|pk)_(test|live)_[A-Za-z0-9]+$/D';

    public readonly Checkouts $checkouts;
    public readonly Payments $payments;
    public readonly Refunds $refunds;
    public readonly PaymentLinks $paymentLinks;
    public readonly Cards $cards;
    public readonly Events $events;
    public readonly Account $account;
    public readonly Customers $customers;
    public readonly Prices $prices;
    public readonly Products $products;
    public readonly Subscriptions $subscriptions;
    public readonly Invoices $invoices;
    public readonly CreditNotes $creditNotes;
    public readonly Coupons $coupons;
    public readonly TaxRates $taxRates;
    public readonly InvoiceItems $invoiceItems;
    public readonly SubscriptionSchedules $subscriptionSchedules;
    public readonly SubscriptionLinks $subscriptionLinks;
    public readonly BillingPortal $billingPortal;
    public readonly Meters $meters;
    public readonly MeterEvents $meterEvents;
    public readonly UsageAlerts $usageAlerts;
    public readonly Entitlements $entitlements;
    public readonly TestClocks $testClocks;

    /**
     * @param string $secretKey A secret key, sk_test_... or sk_live_... (a pk_ key can only create checkouts).
     * @param array{apiBase?: string, timeout?: int|float, connectTimeout?: int|float, maxRetries?: int, transport?: TransportInterface, sleep?: callable(float): void} $options
     */
    public function __construct(
        #[\SensitiveParameter]
        string $secretKey,
        array $options = [],
    ) {
        // A stack trace reads an argument from its variable. PHP 8.1 has no SensitiveParameter, so the variable is
        // emptied before anything below can throw, and the key lives on in a local, which no trace shows.
        $key = $secretKey;
        $secretKey = '';
        if ($key === '') {
            throw new \InvalidArgumentException('A Payments.lk API key is required: sk_test_... or sk_live_...');
        }
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new \InvalidArgumentException('That is not a Payments.lk API key. Keys start with sk_test_, sk_live_, pk_test_ or pk_live_.');
        }

        foreach (array_keys($options) as $name) {
            if (!\in_array($name, self::OPTIONS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Unknown client option "%s". Options are: %s.',
                    (string) $name,
                    implode(', ', self::OPTIONS),
                ));
            }
        }

        $transport = $options['transport'] ?? null;
        if ($transport !== null && !$transport instanceof TransportInterface) {
            throw new \InvalidArgumentException('transport must implement PaymentsLk\Http\TransportInterface.');
        }
        $sleep = $options['sleep'] ?? null;
        if ($sleep !== null && !\is_callable($sleep)) {
            throw new \InvalidArgumentException('sleep must be callable with a number of seconds.');
        }

        $requestor = new Requestor(
            $key,
            self::apiBase($options['apiBase'] ?? self::DEFAULT_API_BASE),
            self::seconds('timeout', $options['timeout'] ?? self::DEFAULT_TIMEOUT),
            self::seconds('connectTimeout', $options['connectTimeout'] ?? self::DEFAULT_CONNECT_TIMEOUT),
            self::maxRetries($options['maxRetries'] ?? self::DEFAULT_MAX_RETRIES),
            $transport ?? new CurlTransport(),
            $sleep,
        );

        $this->checkouts = new Checkouts($requestor);
        $this->payments = new Payments($requestor);
        $this->refunds = new Refunds($requestor);
        $this->paymentLinks = new PaymentLinks($requestor);
        $this->cards = new Cards($requestor);
        $this->events = new Events($requestor);
        $this->account = new Account($requestor);
        $this->customers = new Customers($requestor);
        $this->prices = new Prices($requestor);
        $this->products = new Products($requestor);
        $this->subscriptions = new Subscriptions($requestor);
        $this->invoices = new Invoices($requestor);
        $this->creditNotes = new CreditNotes($requestor);
        $this->coupons = new Coupons($requestor);
        $this->taxRates = new TaxRates($requestor);
        $this->invoiceItems = new InvoiceItems($requestor);
        $this->subscriptionSchedules = new SubscriptionSchedules($requestor);
        $this->subscriptionLinks = new SubscriptionLinks($requestor);
        $this->billingPortal = new BillingPortal($requestor);
        $this->meters = new Meters($requestor);
        $this->meterEvents = new MeterEvents($requestor);
        $this->usageAlerts = new UsageAlerts($requestor);
        $this->entitlements = new Entitlements($requestor);
        $this->testClocks = new TestClocks($requestor);
    }

    /**
     * A client is never stored: in a session, a cache or a queued job its key would be written out with it.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('A Payments.lk client holds a secret key and cannot be serialized. Build it where it is used.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A Payments.lk client cannot be unserialized. Build it where it is used.');
    }

    private static function apiBase(mixed $value): string
    {
        $problem = 'apiBase must be an https URL with no query, fragment or credentials (plain http is allowed only for localhost).';
        if (!\is_string($value) || $value === '' || preg_match('/[\s]/', $value) === 1) {
            throw new \InvalidArgumentException($problem);
        }
        $base = rtrim($value, '/');
        $parts = parse_url($base);
        if (!\is_array($parts)) {
            throw new \InvalidArgumentException($problem);
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $local = \in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
        if (
            $host === ''
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || !($scheme === 'https' || ($scheme === 'http' && $local))
        ) {
            throw new \InvalidArgumentException($problem);
        }

        return $base;
    }

    private static function seconds(string $name, mixed $value): float
    {
        if ((!\is_int($value) && !\is_float($value)) || !is_finite((float) $value) || $value <= 0) {
            throw new \InvalidArgumentException(sprintf('%s must be a positive number of seconds.', $name));
        }

        return (float) $value;
    }

    private static function maxRetries(mixed $value): int
    {
        if (!\is_int($value) || $value < 0 || $value > self::MAX_RETRIES_LIMIT) {
            throw new \InvalidArgumentException(sprintf('maxRetries must be an integer from 0 to %d.', self::MAX_RETRIES_LIMIT));
        }

        return $value;
    }
}
