# Changelog

## 0.4.1 (29 September 2026)

The first version on Packagist. The code is the same as 0.4.0.

Changed

- Install with `composer require payments-lk/payments-lk-php`. Installed from the downloaded archive before? Remove the `path` repository from your `composer.json`, delete `packages/payments-lk-php`, then run that command.
- Composer's download leaves out the tests. The package carries its licence and links to its source and issues.
- Requests say `payments-lk-php/0.4.1` in their `User-Agent`.

## 0.4.0 (25 September 2026)

Monthly limits, subscriptions and billing, card save outcomes.

Added

- `$client->account->retrieve()` answers `plan` and `usage`: this month's card payments in the key's mode against the plan's monthly limit.
- Webhook events `account.limit.approaching`, `account.limit.reached` and `account.limit.reset`.
- A payment that would take the month past the limit is refused with reason `monthly_limit_reached` (409).
- Subscriptions: webhook events `customer.subscription.*` and `invoice.*`, and a payment's `invoiceId`.
- Methods for every billing endpoint: `$client->customers`, `prices`, `products` (features), `subscriptions`, `invoices`, `creditNotes`, `coupons`, `taxRates`, `invoiceItems`, `subscriptionSchedules`, `subscriptionLinks`, `billingPortal->sessions`, `meters`, `meterEvents`, `usageAlerts`, `entitlements->features`, `entitlements->activeEntitlements` and `testClocks`, each with `all()` and `listAll()` where the endpoint is a list. List filters are checked before anything is sent, as on the other lists.
- Billing on the 1st: `$client->subscriptions->create()` takes `billingCycleAnchor` (`now`, `first_of_month` or a time at most one period ahead; the part period before it is invoiced at once, prorated), and a subscription carries `billingCycleAnchor`. A refused anchor answers with reason `billing_cycle_anchor_invalid` (400).
- A constant for every webhook event type (`Webhook::EVENT_INVOICE_PAID`, `Webhook::EVENT_CUSTOMER_SUBSCRIPTION_CREATED` and the rest), all of them in `Webhook::EVENT_TYPES`.
- Card save failures: the webhook event `card.save_failed` (`Webhook::EVENT_CARD_SAVE_FAILED`), whose data is the payment; a payment's `cardSave` (`not_requested`, `requested`, `saved` or `failed`); and a subscription's `cardMissingSince`.

Fixed

- `Webhook::EVENT_TYPES` listed only the six payment, card and refund events, so `events->list(['type' => 'invoice.paid'])` and the other billing and monthly limit types were refused before anything was sent. It now lists every type the API sends, and the tests check it against the API's document.

## 0.3.0 (21 September 2026)

Saved cards, in full.

Added

- `$client->cards->retrieve($id)`: `GET /v1/cards/{id}`, one card. It answers for a deleted card too, so you can still say why a charge stopped.
- `$client->cards->list($query)` takes `limit`, `cursor` and `customerEmail`, and pages like the other lists. `all()` and `listAll()` fetch page after page as you iterate.
- A saved card carries `expiry` (the month it expires, or null), `paymentId` (the payment the customer made when they kept the card), `consent` (`agreedAt`, `locale` and `wording`: what the customer agreed to, so you have a record of why you may charge them) and `retiredAt`.
- A payment carries `savedCardId`, the card it was charged to when it was charged without the customer present.

Changed

- `cards->list()` was not paginated and returned your 200 newest cards. It now returns one page of 25 by default, with `hasMore` and `nextCursor` like every other list. Code that read every card from one call should use `cards->all()`.
- Deleting a card that is already deleted answers the same card instead of a 404, so a retry is safe.

## 0.2.0 (18 September 2026)

Added

- `Webhook::EVENT_TEST_PING`, the type of the dashboard's Send test event. It is not in `EVENT_TYPES`, because `GET /v1/events` never lists it.
- `$client->events`: `list()`, `all()`, `listAll()` and `retrieve()` for `GET /v1/events` and `GET /v1/events/{id}`, the events your webhooks receive, in the same shape. `list()` takes `type` (one of `Webhook::EVENT_TYPES`) and `since` (an ISO 8601 time) as well as `limit` and `cursor`; an unknown type is refused before anything is sent.
- `$client->account->retrieve()`: `GET /v1/account`, the merchant behind the key.
- `Webhook::EVENT_TYPES`, every event type a webhook delivers.
- `checkouts->create()` takes `lineItems` (1 to 10 lines of `name`, optional `description`, integer `unitAmountCents` and integer `quantity`) instead of `amountCents`, with `description` then optional, and `locale` (`en`, `si` or `ta`) for the hosted checkout's language. Sending both `amountCents` and `lineItems` throws `InvalidArgumentException` before anything is sent.
- `listAll()` on payments, refunds and events, the same as `all()`, under the name the Node.js library uses.
- `ApiException::getReason()` (a finer stable reason, snake_case) and `getDocUrl()` (the error's documentation), both null when the API sends neither, and `getApiMessage()`, the API's sentence alone. `getMessage()` ends with the documentation link when there is one.

Changed

- The install instructions unzip the downloaded archive into your project instead of pointing at a clone of the repository.
- The refund documentation says what happens: a refund is queued for Payable, or for Payable's sandbox in test mode, and the webhook says how it ended.

Security

- The secret key is held outside the client's properties, so `var_export`, an `(array)` cast and the dumpers and error reporters built on them no longer print it; `var_dump` and `print_r` now show only which kind of key it is (`sk_test_...`), where they showed its first four characters. A client refuses `serialize()` with a clear message.
- No exception carries the key in its stack trace arguments: the key and the request headers are `SensitiveParameter` (PHP 8.2 and later) and are emptied before anything can throw, which covers PHP 8.1. `TransportInterface::send()` marks `$headers` the same way; do so in a transport of your own.
- `getDocUrl()` is kept, and linked from `getMessage()`, only when it is an https address on payments.lk or a subdomain of it. An address anywhere else in an error body is dropped.
- The README says what the 24 hour idempotency window means for a job that retries later than that: check the object first.

Unchanged, and now tested: a 409 is never retried.

## 0.1.0 (15 September 2026)

The first release: checkouts, payments, refunds, payment links, saved cards and webhook verification, with retries and idempotency keys.
