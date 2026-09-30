# Payments.lk PHP SDK

The server side PHP library for the [Payments.lk](https://payments.lk) API: hosted checkouts, payments, refunds, payment links, saved cards, events, the account and webhook verification. What changed in each version is in [CHANGELOG.md](CHANGELOG.md).

- PHP 8.1 or later, with `ext-curl` and `ext-json`
- No runtime Composer dependencies
- Amounts are always integer cents of LKR: Rs. 10.00 is `1000`, Rs. 2,500.00 is `250000`

Use a secret key (`sk_test_...` or `sk_live_...`) from the merchant portal, and keep it on your server. A publishable key (`pk_...`) can only create checkouts.

## Install

```sh
composer require payments-lk/payments-lk-php
```

The package is on [Packagist](https://packagist.org/packages/payments-lk/payments-lk-php), released from [github.com/PAYable-IPG/payments-lk-php](https://github.com/PAYable-IPG/payments-lk-php).

Installed it from a downloaded archive before? Remove the `path` repository that points at `packages/payments-lk-php` from your `composer.json`, delete that folder, then run the command above.

Then load Composer's autoloader:

```php
require __DIR__ . '/vendor/autoload.php';
```

## Quick start: create a checkout and redirect

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$payments = new PaymentsLk\Client((string) getenv('PAYMENTS_LK_SECRET_KEY'));

$checkout = $payments->checkouts->create([
    'amountCents' => 250000, // Rs. 2,500.00
    'description' => 'Order #1042',
    'reference'   => 'order_1042',
    'customer'    => [
        'name'  => 'Nimal Perera',
        'email' => 'nimal@example.com',
        'phone' => '+94771234567',
    ],
    'successUrl'  => 'https://shop.example.lk/orders/1042/thanks',
    'cancelUrl'   => 'https://shop.example.lk/cart',
], [
    'idempotencyKey' => 'checkout-order-1042',
]);

// $checkout is {object: "checkout", id: "chk_...", mode, status, url, expiresAt, payment: {...}}
header('Location: ' . $checkout['url'], true, 303);
exit;
```

### Line items and the checkout's language

Send `lineItems` instead of `amountCents` and the API adds them up; `description` is then optional and defaults to a summary line. `locale` opens the hosted checkout in Sinhala (`si`), Tamil (`ta`) or English (`en`), and the checkout's `url` carries the language.

```php
$checkout = $payments->checkouts->create([
    'lineItems' => [
        ['name' => 'Chicken kottu', 'unitAmountCents' => 145000, 'quantity' => 2],
        ['name' => 'Ceylon tea', 'description' => '250g, loose leaf', 'unitAmountCents' => 95000, 'quantity' => 1],
    ],
    'reference' => 'order_1043',
    'locale'    => 'si',
], [
    'idempotencyKey' => 'checkout-order-1043',
]);
```

One to ten lines, each 1 to 99 of a unit price from Rs. 1 (`100`) to Rs. 1,000,000. Sending both `amountCents` and `lineItems` throws `InvalidArgumentException` before anything is sent.

The customer enters their card on the hosted page, never on your site. Do not treat a visit to `successUrl` as proof of payment: confirm it with a webhook or by retrieving the checkout.

```php
$checkout = $payments->checkouts->retrieve('chk_...');
echo $checkout['status'];
```

## Payments

```php
$payment = $payments->payments->retrieve('pay_...');
echo $payment['status'];

// One page: {object: "list", data: [...], hasMore, nextCursor}
$page = $payments->payments->list(['limit' => 50, 'status' => 'succeeded']);

// Every page, fetched as you iterate
foreach ($payments->payments->all(['status' => 'succeeded']) as $payment) {
    echo $payment['id'], PHP_EOL;
}
```

`limit` is 1 to 100 (the API default is 25). `list()` takes `limit`, `cursor` and `status`; pass the previous page's `nextCursor` as `cursor` to page by hand. `listAll()` is the same as `all()`, under the name the Node.js library uses, on payments, refunds and events.

## Refunds

```php
$refund = $payments->refunds->create([
    'paymentId'   => 'pay_...',
    'amountCents' => 50000, // Rs. 500.00
    'reason'      => 'Customer returned one item',
], [
    'idempotencyKey' => 'refund-order-1042-1',
]);

$refund = $payments->refunds->retrieve($refund['id']);

$page = $payments->refunds->list(['paymentId' => 'pay_...', 'limit' => 10]);

foreach ($payments->refunds->all(['paymentId' => 'pay_...']) as $refund) {
    echo $refund['id'], PHP_EOL;
}
```

`amountCents` and `reason` are optional. A refund is recorded as `pending` and queued for Payable: a live refund goes to Payable, a sandbox refund to Payable's sandbox, and the `refund.succeeded` or `refund.failed` webhook tells you how it ended. Payable refunds a card payment only once it has settled, from the next bank working day, so a refund asked for sooner fails and can be asked for again. Only a payment taken by a local development API's simulator refunds at once.

## Events and the account

The events your webhooks receive can also be read, in the same shape, newest first. Use them to catch up after your endpoint was down:

```php
use PaymentsLk\Webhook;

foreach ($payments->events->all(['type' => Webhook::EVENT_PAYMENT_SUCCEEDED, 'since' => '2026-09-18T00:00:00Z']) as $event) {
    // $event is {id, object: "event", type, mode, created, data}
}

$event = $payments->events->retrieve('evt_...');
$account = $payments->account->retrieve(); // status, clearance, pricingPlan, liveEnabled
```

`list()` takes `limit`, `cursor`, `type` (one of `Webhook::EVENT_TYPES`) and `since` (an ISO 8601 time). Only those six types are listed; a test key reads test events and a live key live ones.

## Payment links

```php
$link = $payments->paymentLinks->create([
    'title'       => 'Kandy tour deposit',
    'description' => 'Two nights, per person',
    'amountCents' => 1500000, // Rs. 15,000.00
]);

$links = $payments->paymentLinks->list();

// Retire a link so it stops taking payments
$payments->paymentLinks->retire($link['id']);
```

## Saved cards

A card is saved when the customer agrees at checkout (`'saveCard' => true` on the checkout). The `card.saved` webhook tells you when that happened, and brings a card id to keep against your customer. You never see the card number.

```php
// One page of a customer's cards, newest first.
$cards = $payments->cards->list(['customerEmail' => 'ruwan@example.lk']);

// Or every card, page after page.
foreach ($payments->cards->all(['customerEmail' => 'ruwan@example.lk']) as $card) {
    echo $card['id'], ' ', $card['scheme'], ' ', $card['last4'], PHP_EOL;
}

// One card, deleted ones included, with its expiry month and the customer's consent.
$card = $payments->cards->retrieve('card_...');

$result = $payments->cards->charge('card_...', [
    'amountCents' => 99000, // Rs. 990.00
    'description' => 'Monthly plan, October',
    'reference'   => 'invoice_2026_10_381',
], [
    'idempotencyKey' => 'invoice_2026_10_381',
]);

$payments->cards->delete('card_...');
```

Four things are worth knowing.

- **Charge only what the customer agreed to.** `$card['consent']` says when they agreed, in which language and which wording they read; `retrieve()` answers for a deleted card as well, so you can still show it.
- **One idempotency key per billing period**, such as `invoice_2026_10_381`. A retry then never charges twice. The window is 24 hours: a job that retries later than that should read the payment first.
- **A charge is usually `processing`.** The outcome arrives as `payment.succeeded` or `payment.failed`; the payment carries `savedCardId`.
- **Watch `$card['expiry']`.** A charge after the card expires is declined, so ask the customer for a new card before then. `delete()` removes the card here and its token at the processor; deleting one already deleted answers the same card.

Saved cards need Payable's Advanced plan, in the sandbox as well as live, and switching on in the dashboard for live. One-time checkouts and payment links work on every plan.

## Subscriptions and billing

We renew each subscription every period, charge the card the customer kept, retry a decline on days 1, 3, 7 and 14, email the customer and send you the events. You make the prices and start the subscriptions; the `customer.subscription.*` and `invoice.*` webhooks (`Webhook::EVENT_TYPES` lists them all) tell you what happened.

```php
$customer = $payments->customers->create(['email' => 'nimali@example.lk', 'name' => 'Nimali Perera']);
$price = $payments->prices->create(['product' => ['name' => 'Gym and pool'], 'unitAmountCents' => 600000, 'interval' => 'month']);

// With a card on file the first invoice is charged within a minute. Without one, send the customer to checkoutUrl.
$subscription = $payments->subscriptions->create(
    ['customerId' => $customer['id'], 'priceId' => $price['id'], 'trialDays' => 14],
    ['idempotencyKey' => 'member-1042'],
);

// See what a change costs before making it.
$preview = $payments->invoices->preview(['subscriptionId' => $subscription['id'], 'quantity' => 2]);
$payments->subscriptions->update($subscription['id'], ['quantity' => 2]);
$payments->subscriptions->cancel($subscription['id'], ['atPeriodEnd' => true]);

// Let the customer manage it themselves: send them to the portal address, good for an hour.
$portal = $payments->billingPortal->sessions->create(['customerId' => $customer['id'], 'returnUrl' => 'https://gym.example.lk/account']);
header('Location: ' . $portal['url'], true, 303);
```

Everything else the billing API does has a method with the same name as its endpoint:

- `customers` (and `createBalanceTransaction()` for credit), `prices`, `coupons`, `taxRates`, `invoiceItems`.
- `invoices`: `upcoming()`, `preview()`, standalone `create()`, then `finalize()`, `send()`, `pay()`, `void()` and `markUncollectible()`. `creditNotes` take part of an invoice back.
- `subscriptionSchedules` for plans that change on their own and installments. `subscriptionLinks` for a link anyone can open to subscribe.
- `meters` and `meterEvents` for usage based prices, and `usageAlerts`. Send an `identifier` with each meter event so a retry counts once.
- `entitlements->features`, `products->attachFeature()` and `entitlements->activeEntitlements->list(['customerId' => ...])`, so your app asks which features a customer has.
- `testClocks->advance()` runs a sandbox subscription's time ahead, to see a renewal or a trial's end without waiting.

Live subscriptions need saved cards switched on, as charging a saved card does.

On a website with no server, a subscription link does the same job: `<button data-payments-lk-subscribe="LINKCODE" data-payments-lk-plan="price_...">` opens it with a plan chosen, and `<div data-payments-lk-plans="LINKCODE"></div>` puts its whole plan chooser on the page, both with `https://payments.lk/js/pay.js`. The dashboard's Integrate panel on each plan writes the snippets.

## Webhooks

Payments.lk signs every webhook with the endpoint's signing secret (`whsec_...`). The header looks like:

```
Payments-Signature: t=1726400000,v1=da3ac9bfe911ba0bc6b90e3c5591d7942230249bc4c73334e8def4d9354a14aa
```

`Webhook::constructEvent()` checks the HMAC-SHA256 over `"<t>.<raw body>"` in constant time, accepts any of several `v1` values (useful while a secret rotates), refuses a timestamp more than 300 seconds from now in either direction, and returns the decoded event. Verify the raw body exactly as it arrived: do not decode and re-encode it first.

A complete receiver in plain PHP, for example `webhooks/payments-lk.php`:

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

use PaymentsLk\Exception\SignatureVerificationException;
use PaymentsLk\Webhook;

$payload   = (string) file_get_contents('php://input');
$signature = $_SERVER['HTTP_PAYMENTS_SIGNATURE'] ?? null;
$secret    = (string) getenv('PAYMENTS_LK_WEBHOOK_SECRET');

try {
    $event = Webhook::constructEvent($payload, $signature, $secret);
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}

// $event is {id: "evt_...", object: "event", type, mode, created, data}
// data is the payment, the saved card, {object: "checkout", id, paymentId} for
// checkout.expired, or the refund with the payment after it in data.payment.
switch ($event['type']) {
    case Webhook::EVENT_PAYMENT_SUCCEEDED:   // payment.succeeded
        // Mark the order paid. Key your handling on $event['id'] so a repeat does no harm.
        break;
    case Webhook::EVENT_PAYMENT_FAILED:      // payment.failed
    case Webhook::EVENT_CHECKOUT_EXPIRED:    // checkout.expired
    case Webhook::EVENT_CARD_SAVED:          // card.saved
    case Webhook::EVENT_REFUND_SUCCEEDED:    // refund.succeeded
    case Webhook::EVENT_REFUND_FAILED:       // refund.failed
        break;
}

http_response_code(200);
```

Frameworks that hand you a request object work the same way: pass the raw body string and the `Payments-Signature` header value. The optional fourth and fifth arguments set the tolerance in seconds and the current time (for tests).

## Error handling

Every API error is an `ApiException` subclass carrying the HTTP status, the API's error code, a finer reason when the API gives one (`getReason()`, snake_case, or null), a link to the error's documentation (`getDocUrl()`: always an https address on payments.lk, or null, since an address anywhere else in an error body is dropped), a trace id to quote to support, and field level problems on validation errors. `getMessage()` is the API's sentence followed by the documentation link when there is one; `getApiMessage()` is the sentence alone. Branch on the code or the reason, never on the text.

| Exception | When |
| --- | --- |
| `ValidationException` | 400 or 422, `VALIDATION_FAILED` |
| `AuthenticationException` | 401, `UNAUTHENTICATED` |
| `PermissionException` | 403, `FORBIDDEN` |
| `NotFoundException` | 404, `NOT_FOUND` |
| `ConflictException` | 409, `CONFLICT`, never retried |
| `RateLimitException` | 429, `RATE_LIMITED`, after retries |
| `ServerException` | 5xx, including 501 `NOT_SUPPORTED`, after retries |
| `ApiException` | any other non 2xx status |
| `ConnectionException` | no HTTP response at all (DNS, TCP, TLS, timeout), after retries |
| `SignatureVerificationException` | a webhook failed verification |

All of them implement `PaymentsLk\Exception\ExceptionInterface`. Mistakes in your own code, such as a float amount, an unknown option or an empty id, throw `InvalidArgumentException` before anything is sent.

```php
use PaymentsLk\Exception\ApiException;
use PaymentsLk\Exception\ConnectionException;
use PaymentsLk\Exception\ValidationException;

try {
    $checkout = $payments->checkouts->create([
        'amountCents' => 500, // below the Rs. 10.00 minimum
        'description' => 'Order #1043',
    ]);
} catch (ValidationException $e) {
    foreach ($e->getFields() as $field) {
        error_log($field['path'] . ': ' . $field['message']);
    }
} catch (ApiException $e) {
    error_log(sprintf(
        'Payments.lk error %s, reason %s (HTTP %d, trace %s): %s',
        $e->getErrorCode() ?? 'unknown',
        $e->getReason() ?? 'none',
        $e->getHttpStatus(),
        $e->getTraceId() ?? 'none',
        $e->getMessage(),
    ));
} catch (ConnectionException $e) {
    // The request may or may not have reached Payments.lk.
    // Retry later with the same idempotencyKey.
}
```

If an error body is not JSON (a proxy's HTML page, say), you still get the right subclass for the status, with a generic message and a null error code.

## Idempotency and retries

Every POST carries an `Idempotency-Key` header, so repeating a request never charges or refunds twice.

- If you pass `['idempotencyKey' => '...']` as the last argument to `create()` or `charge()`, that key is used. It must be 8 to 255 letters, digits, dashes, underscores, colons or dots.
- Otherwise the SDK generates a random UUID v4 for the call.
- Either way, the same key is sent on every automatic retry of that call.

Pass your own key derived from something stable in your system (an order or invoice id) when you might repeat the call yourself, for example after a `ConnectionException` or a crash. A generated key only protects the SDK's own retries.

Retries happen on connection failures and on HTTP 429, 500, 502, 503 and 504, up to `maxRetries` times (default 2). The wait starts at 0.5 seconds and doubles each attempt, with random jitter. A numeric `Retry-After` header is honored, capped at 20 seconds. No other 4xx is retried, a 409 included. One 409 is worth repeating yourself: `getReason()` `idempotency_key_in_progress` means a first request with the same key is still running, so wait a moment and send the same call with the same `idempotencyKey` to get its answer. The key is remembered for 24 hours, separately for test and live keys. After that the same key starts a new request, so a job that may retry more than 24 hours later must first check whether the object exists (`$payments->events->list()`, or `retrieve()` with the id it saved) rather than rely on the key. Every reason is explained at https://payments.lk/developers/errors, which is where `getDocUrl()` points.

## Configuration

```php
$payments = new PaymentsLk\Client($secretKey, [
    'apiBase'        => 'https://api.payments.lk', // default
    'timeout'        => 30,  // seconds for the whole request
    'connectTimeout' => 10,  // seconds to connect
    'maxRetries'     => 2,   // 0 turns retries off
]);
```

TLS certificate verification is always on and redirects are never followed. `apiBase` must be https; plain http is accepted only for `localhost` and `127.0.0.1`.

## Testing your integration

The client accepts any `PaymentsLk\Http\TransportInterface` as `transport`, and a `sleep` callable so retries do not wait in tests:

```php
use PaymentsLk\Http\Response;
use PaymentsLk\Http\TransportInterface;

final class StubTransport implements TransportInterface
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout, float $connectTimeout): Response
    {
        return new Response(200, ['content-type' => 'application/json'], '{"object":"payment","id":"pay_1","status":"succeeded"}');
    }
}

$payments = new PaymentsLk\Client('sk_test_' . str_repeat('0', 32), [
    'transport' => new StubTransport(),
    'sleep'     => static function (float $seconds): void {},
]);
```

## Developing this SDK

The suite needs no PHPUnit and makes no network calls outside 127.0.0.1. From the repository root:

```sh
docker run --rm -v "$PWD/sdks/php":/app -w /app php:8.3-cli \
  sh -c 'for f in $(find src tests -name "*.php"); do php -l "$f" || exit 1; done && php tests/run.php'
```

Swap `php:8.3-cli` for `php:8.1-cli` to check the minimum version.

## License

MIT
