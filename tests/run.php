<?php

// The SDK's test suite. No PHPUnit and no network: API calls go through
// FakeTransport, and CurlTransport is exercised against a php -S server on
// 127.0.0.1 only. Run with: php tests/run.php

declare(strict_types=1);

use PaymentsLk\Client;
use PaymentsLk\Exception\ApiException;
use PaymentsLk\Exception\AuthenticationException;
use PaymentsLk\Exception\ConflictException;
use PaymentsLk\Exception\ConnectionException;
use PaymentsLk\Exception\ExceptionInterface;
use PaymentsLk\Exception\NotFoundException;
use PaymentsLk\Exception\PermissionException;
use PaymentsLk\Exception\RateLimitException;
use PaymentsLk\Exception\ServerException;
use PaymentsLk\Exception\SignatureVerificationException;
use PaymentsLk\Exception\ValidationException;
use PaymentsLk\Http\CurlTransport;
use PaymentsLk\Http\Requestor;
use PaymentsLk\Http\Response;
use PaymentsLk\Tests\FakeTransport;
use PaymentsLk\Webhook;

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

spl_autoload_register(static function (string $class): void {
    $roots = [
        'PaymentsLk\\Tests\\' => __DIR__ . '/',
        'PaymentsLk\\' => dirname(__DIR__) . '/src/',
    ];
    foreach ($roots as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});

// ---------------------------------------------------------------------------
// A tiny assertion helper.
// ---------------------------------------------------------------------------

final class AssertionFailed extends Exception
{
}

final class Suite
{
    public static int $tests = 0;
    public static int $assertions = 0;
    public static int $skipped = 0;
    /** @var list<string> */
    public static array $failures = [];
}

function section(string $title): void
{
    fwrite(STDOUT, "\n{$title}\n");
}

function test(string $name, callable $body): void
{
    Suite::$tests++;
    try {
        $body();
        fwrite(STDOUT, "  ok    {$name}\n");
    } catch (Throwable $e) {
        Suite::$failures[] = $name;
        fwrite(STDOUT, sprintf(
            "  FAIL  %s\n        %s: %s\n        at %s:%d\n",
            $name,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
        ));
    }
}

function skip(string $name, string $why): void
{
    Suite::$skipped++;
    fwrite(STDOUT, "  skip  {$name} ({$why})\n");
}

function check(bool $condition, string $message): void
{
    Suite::$assertions++;
    if (!$condition) {
        throw new AssertionFailed($message);
    }
}

function same(mixed $expected, mixed $actual, string $label): void
{
    check($expected === $actual, $label . ': expected ' . show($expected) . ', got ' . show($actual));
}

function between(float $actual, float $min, float $max, string $label): void
{
    check($actual >= $min && $actual <= $max, "{$label}: expected {$min} to {$max}, got {$actual}");
}

function show(mixed $value): string
{
    $text = var_export($value, true);

    return strlen($text) > 400 ? substr($text, 0, 400) . '...' : $text;
}

/**
 * @template T of Throwable
 *
 * @param class-string<T> $class
 *
 * @return T
 */
function throws(string $class, callable $body, ?string $messageContains = null): Throwable
{
    try {
        $body();
    } catch (AssertionFailed $e) {
        throw $e;
    } catch (Throwable $e) {
        check($e instanceof $class, 'expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());
        if ($messageContains !== null) {
            check(
                str_contains($e->getMessage(), $messageContains),
                'expected a message containing ' . show($messageContains) . ', got ' . show($e->getMessage()),
            );
        }

        return $e;
    }
    check(false, 'expected ' . $class . ' but nothing was thrown');

    throw new LogicException('unreachable');
}

// ---------------------------------------------------------------------------
// Fixtures.
// ---------------------------------------------------------------------------

const TEST_KEY = 'sk_test_nvrshown9';
const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

/**
 * @param list<Response|Throwable> $responses
 * @param array<string, mixed> $options
 *
 * @return array{0: Client, 1: FakeTransport, 2: ArrayObject<int, float>}
 */
function fixture(array $responses, array $options = []): array
{
    $transport = new FakeTransport($responses);
    $sleeps = new ArrayObject();
    $client = new Client(TEST_KEY, $options + [
        'transport' => $transport,
        'sleep' => static function (float $seconds) use ($sleeps): void {
            $sleeps->append($seconds);
        },
    ]);

    return [$client, $transport, $sleeps];
}

/** @param array<string, mixed> $body */
function jsonResponse(int $status, array $body, array $headers = []): Response
{
    return new Response($status, $headers + ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
}

function apiError(int $status, string $code, string $message = 'Something is wrong.'): Response
{
    return jsonResponse($status, ['code' => $code, 'message' => $message, 'traceId' => 'tr_' . $status]);
}

/** @return array<string, mixed> */
function checkoutBody(): array
{
    return [
        'object' => 'checkout',
        'id' => 'chk_1',
        'mode' => 'test',
        'status' => 'open',
        'url' => 'https://pay.payments.lk/c/chk_1',
        'expiresAt' => '2026-09-15T12:00:00.000Z',
        'payment' => ['object' => 'payment', 'id' => 'pay_1', 'status' => 'pending'],
    ];
}

function sign(string $payload, string $secret, int $timestamp): string
{
    return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
}

// ---------------------------------------------------------------------------
section('Client construction');
// ---------------------------------------------------------------------------

test('an empty key is refused', function (): void {
    throws(InvalidArgumentException::class, fn () => new Client('', ['transport' => new FakeTransport()]), 'required');
});

test('keys that are not sk_ or pk_ test or live keys are refused without echoing them', function (): void {
    $bad = ['rk_test_abc123', 'sk_prod_abc123', 'sk_test_', 'whsec_abc123', ' sk_test_abc123', "sk_test_abc123\r\nX-Evil: 1", "sk_test_abc123\n", 'Bearer sk_test_abc123'];
    foreach ($bad as $key) {
        $e = throws(InvalidArgumentException::class, fn () => new Client($key, ['transport' => new FakeTransport()]));
        check(!str_contains($e->getMessage(), 'abc123'), 'the message must not echo the key');
    }
});

test('sk_ and pk_ keys in test and live mode are accepted', function (): void {
    foreach (['sk_test_x1', 'sk_live_x1', 'pk_test_x1', 'pk_live_x1', TEST_KEY] as $key) {
        $client = new Client($key, ['transport' => new FakeTransport()]);
        check($client instanceof Client, "accepts {$key}");
    }
});

test('options are validated', function (): void {
    $t = new FakeTransport();
    throws(InvalidArgumentException::class, fn () => new Client(TEST_KEY, ['transport' => $t, 'retries' => 3]), 'Unknown client option "retries"');
    throws(InvalidArgumentException::class, fn () => new Client(TEST_KEY, ['transport' => $t, 'apiBase' => 'http://api.payments.lk']), 'https');
    throws(InvalidArgumentException::class, fn () => new Client(TEST_KEY, ['transport' => $t, 'apiBase' => 'https://user:pw@api.payments.lk']));
    throws(InvalidArgumentException::class, fn () => new Client(TEST_KEY, ['transport' => $t, 'apiBase' => 'https://api.payments.lk/?x=1']));
    throws(InvalidArgumentException::class, fn () => new Client(TEST_KEY, ['transport' => $t, 'apiBase' => 'api.payments.lk']));
    throws(InvalidArgumentException::class, fn () => new Client(TEST_KEY, ['transport' => $t, 'timeout' => 0]));
    throws(InvalidArgumentException::class, fn () => new Client(TEST_KEY, ['transport' => $t, 'connectTimeout' => '10']));
    throws(InvalidArgumentException::class, fn () => new Client(TEST_KEY, ['transport' => $t, 'maxRetries' => -1]));
    throws(InvalidArgumentException::class, fn () => new Client(TEST_KEY, ['transport' => $t, 'maxRetries' => 11]));
    throws(InvalidArgumentException::class, fn () => new Client(TEST_KEY, ['transport' => new stdClass()]));
    throws(InvalidArgumentException::class, fn () => new Client(TEST_KEY, ['transport' => $t, 'sleep' => 'not a function at all']));
    check(new Client(TEST_KEY, ['transport' => $t, 'apiBase' => 'http://localhost:3312']) instanceof Client, 'http is fine for localhost');
    check(new Client(TEST_KEY, ['transport' => $t, 'apiBase' => 'http://127.0.0.1:3312/']) instanceof Client, 'http is fine for 127.0.0.1');
});

test('the default transport is CurlTransport with TLS verification on and no redirects', function (): void {
    $options = (new CurlTransport())->options('POST', 'https://api.payments.lk/v1/checkouts', ['Accept' => 'application/json'], '{}', 30.0, 10.0);
    same(true, $options[CURLOPT_SSL_VERIFYPEER], 'CURLOPT_SSL_VERIFYPEER');
    same(2, $options[CURLOPT_SSL_VERIFYHOST], 'CURLOPT_SSL_VERIFYHOST');
    same(false, $options[CURLOPT_FOLLOWLOCATION], 'CURLOPT_FOLLOWLOCATION');
    same(30000, $options[CURLOPT_TIMEOUT_MS], 'CURLOPT_TIMEOUT_MS');
    same(10000, $options[CURLOPT_CONNECTTIMEOUT_MS], 'CURLOPT_CONNECTTIMEOUT_MS');
    same('POST', $options[CURLOPT_CUSTOMREQUEST], 'CURLOPT_CUSTOMREQUEST');
    same('{}', $options[CURLOPT_POSTFIELDS], 'CURLOPT_POSTFIELDS');
    throws(InvalidArgumentException::class, fn () => (new CurlTransport())->options('GET', 'https://api.payments.lk/', ['X-A' => "b\r\nX-Evil: 1"], null, 1.0, 1.0));
});

/** Every string reachable from a value: array items and object properties, whatever their visibility. */
function reachableStrings(mixed $value, array &$seen = []): array
{
    if (is_string($value)) {
        return [$value];
    }
    if (is_object($value)) {
        if (isset($seen[spl_object_id($value)]) || $value instanceof Closure) {
            return [];
        }
        $seen[spl_object_id($value)] = true;
        // An (array) cast reads every property, private ones included, the way dumpers and error reporters do.
        $value = (array) $value;
    }
    $found = [];
    if (is_array($value)) {
        foreach ($value as $name => $item) {
            $found = array_merge($found, is_string($name) ? [$name] : [], reachableStrings($item, $seen));
        }
    }

    return $found;
}

/** The part of TEST_KEY after its prefix. None of it may be seen anywhere. */
const TEST_KEY_SECRET = 'nvrshown9';

function checkNoKey(string $what, string $text): void
{
    // Eight characters of it is already too much to show.
    check(!str_contains($text, substr(TEST_KEY_SECRET, 0, 8)), $what . ' shows the secret key: ' . substr($text, 0, 300));
}

test('nothing that prints or walks a client shows the secret key', function (): void {
    [$client] = fixture([]);
    ob_start();
    var_dump($client);
    $dump = (string) ob_get_clean();
    checkNoKey('var_dump', $dump);
    check(str_contains($dump, '"sk_test_..."'), 'var_dump says which kind of key, and nothing of the key: ' . $dump);
    checkNoKey('print_r', print_r($client, true));
    checkNoKey('var_export', var_export($client, true));
    checkNoKey('var_export of a resource', var_export($client->checkouts, true));
    checkNoKey('json_encode', (string) json_encode($client));
    ob_start();
    debug_zval_dump($client);
    checkNoKey('debug_zval_dump', (string) ob_get_clean());
    foreach (reachableStrings($client) as $text) {
        checkNoKey('a property reachable from the client', $text);
    }
    check(in_array('https://api.payments.lk', reachableStrings($client), true), 'the walk does reach the requestor: it finds the API base');
});

test('a client cannot be serialized or stored', function (): void {
    [$client] = fixture([]);
    $e = throws(LogicException::class, fn () => serialize($client), 'cannot be serialized');
    checkNoKey('the refusal', $e->getMessage());
    // Without the test's sleep closure, which PHP refuses to serialize on its own account.
    $plain = new Client(TEST_KEY, ['transport' => new FakeTransport([])]);
    throws(LogicException::class, fn () => serialize($plain), 'cannot be serialized');
    throws(LogicException::class, fn () => serialize($plain->checkouts), 'cannot be serialized');
    throws(Throwable::class, fn () => unserialize('O:17:"PaymentsLk\Client":0:{}'));
});

test('still signs every request with the key', function (): void {
    [$client, $transport] = fixture([jsonResponse(200, ['object' => 'list', 'data' => [], 'hasMore' => false, 'nextCursor' => null])]);
    $client->payments->list();
    same('Bearer ' . TEST_KEY, $transport->requests[0]['headers']['Authorization'], 'Authorization');
});

test('no exception the library throws carries the key, in its message, its trace or its arguments', function (): void {
    $thrown = [];
    $collect = static function (callable $body) use (&$thrown): void {
        try {
            $body();
        } catch (AssertionFailed $e) {
            throw $e;
        } catch (Throwable $e) {
            $thrown[] = $e;

            return;
        }
        check(false, 'expected an exception');
    };

    // Construction: the key is an argument of every one of these calls.
    $collect(fn () => new Client(TEST_KEY . ' '));
    $collect(fn () => new Client(TEST_KEY, ['bogus' => true]));
    $collect(fn () => new Client(TEST_KEY, ['apiBase' => 'http://api.payments.lk']));
    $collect(fn () => new Client(TEST_KEY, ['maxRetries' => -1]));
    // Requests: refusals, a body that is not JSON, and a failed connection.
    [$client] = fixture([
        jsonResponse(401, ['code' => 'UNAUTHENTICATED', 'message' => 'That key is not valid.', 'traceId' => 't-1']),
        new Response(200, [], 'not json'),
        new ConnectionException('Could not complete the request.'),
    ], ['maxRetries' => 0]);
    $collect(fn () => $client->payments->list());
    $collect(fn () => $client->account->retrieve());
    $collect(fn () => $client->checkouts->create(['amountCents' => 1000, 'description' => 'x']));
    // The real transport, where the headers that carry the key are an argument: a header it refuses, and a port nothing listens on.
    $headers = ['Authorization' => 'Bearer ' . TEST_KEY, 'X-Bad' => "a\r\nb"];
    $collect(fn () => (new CurlTransport())->send('GET', 'https://api.payments.lk/v1/account', $headers, null, 1.0, 1.0));
    $collect(fn () => (new CurlTransport())->options('GET', 'https://api.payments.lk/v1/account', $headers, null, 1.0, 1.0));
    $collect(fn () => (new CurlTransport())->send('GET', 'http://127.0.0.1:9/v1/account', ['Authorization' => 'Bearer ' . TEST_KEY], null, 0.5, 0.5));

    same(10, count($thrown), 'exceptions collected');
    foreach ($thrown as $e) {
        $what = get_class($e) . ' (' . $e->getMessage() . ')';
        checkNoKey('the message of ' . $what, $e->getMessage());
        checkNoKey('the trace of ' . $what, $e->getTraceAsString());
        checkNoKey('the text of ' . $what, (string) $e);
        // The arguments of every frame below this test's own closures, which hold the key on purpose.
        foreach ($e->getTrace() as $frame) {
            if (str_starts_with($frame['class'] ?? '', 'PaymentsLk\\')) {
                foreach (reachableStrings($frame['args'] ?? []) as $text) {
                    checkNoKey('an argument of ' . $frame['class'] . '::' . $frame['function'] . ' in the trace of ' . $what, $text);
                }
            }
        }
    }
});

// ---------------------------------------------------------------------------
section('Requests, headers and idempotency');
// ---------------------------------------------------------------------------

test('GET sends Authorization, Accept and User-Agent, and no body, Content-Type or Idempotency-Key', function (): void {
    [$client, $transport] = fixture([jsonResponse(200, ['object' => 'payment', 'id' => 'pay_1', 'status' => 'succeeded'])]);
    $payment = $client->payments->retrieve('pay_1');
    same('pay_1', $payment['id'], 'decoded id');
    same(1, count($transport->requests), 'request count');
    $r = $transport->requests[0];
    same('GET', $r['method'], 'method');
    same('https://api.payments.lk/v1/payments/pay_1', $r['url'], 'url');
    same('Bearer ' . TEST_KEY, $r['headers']['Authorization'] ?? null, 'Authorization');
    same('application/json', $r['headers']['Accept'] ?? null, 'Accept');
    same('payments-lk-php/0.4.1 PHP/' . PHP_VERSION, $r['headers']['User-Agent'] ?? null, 'User-Agent');
    same(false, isset($r['headers']['Content-Type']), 'no Content-Type without a body');
    same(false, isset($r['headers']['Idempotency-Key']), 'no Idempotency-Key on GET');
    same(null, $r['body'], 'body');
    same(30.0, $r['timeout'], 'default timeout');
    same(10.0, $r['connectTimeout'], 'default connect timeout');
});

test('POST sends a JSON body with Content-Type and a generated UUID v4 Idempotency-Key', function (): void {
    [$client, $transport] = fixture([jsonResponse(200, checkoutBody())], [
        'apiBase' => 'https://sandbox.payments.test/',
        'timeout' => 12,
        'connectTimeout' => 3.5,
    ]);
    $params = [
        'amountCents' => 250000,
        'description' => 'Order #1042',
        'reference' => 'order_1042',
        'customer' => ['name' => 'Nimal Perera', 'email' => 'nimal@example.com', 'phone' => '+94771234567'],
        'successUrl' => 'https://shop.example.lk/orders/1042/thanks',
        'cancelUrl' => 'https://shop.example.lk/cart',
        'saveCard' => false,
    ];
    $checkout = $client->checkouts->create($params);
    same('chk_1', $checkout['id'], 'decoded checkout id');
    same('https://pay.payments.lk/c/chk_1', $checkout['url'], 'decoded checkout url');
    $r = $transport->requests[0];
    same('POST', $r['method'], 'method');
    same('https://sandbox.payments.test/v1/checkouts', $r['url'], 'url with the trailing slash of apiBase trimmed');
    same('application/json', $r['headers']['Content-Type'] ?? null, 'Content-Type');
    same('Bearer ' . TEST_KEY, $r['headers']['Authorization'] ?? null, 'Authorization');
    same($params, json_decode((string) $r['body'], true), 'body round trip');
    check(!str_contains((string) $r['body'], '\\/'), 'slashes in URLs are not escaped');
    check(preg_match(UUID_V4, $r['headers']['Idempotency-Key'] ?? '') === 1, 'Idempotency-Key is a UUID v4: ' . ($r['headers']['Idempotency-Key'] ?? 'missing'));
    same(12.0, $r['timeout'], 'timeout option');
    same(3.5, $r['connectTimeout'], 'connectTimeout option');
});

test('uuid4 produces distinct version 4 UUIDs', function (): void {
    $seen = [];
    for ($i = 0; $i < 200; $i++) {
        $uuid = Requestor::uuid4();
        check(preg_match(UUID_V4, $uuid) === 1, "valid v4: {$uuid}");
        $seen[$uuid] = true;
    }
    same(200, count($seen), 'no duplicates');
});

test('the generated Idempotency-Key is reused on a retry and fresh on the next call', function (): void {
    [$client, $transport, $sleeps] = fixture([
        apiError(503, 'INTERNAL'),
        jsonResponse(200, checkoutBody()),
        jsonResponse(200, checkoutBody()),
    ]);
    $client->checkouts->create(['amountCents' => 1000, 'description' => 'One']);
    same(2, count($transport->requests), 'two attempts');
    same(1, count($sleeps), 'one backoff');
    $first = $transport->requests[0]['headers']['Idempotency-Key'];
    check(preg_match(UUID_V4, $first) === 1, 'generated key is a UUID v4');
    same($first, $transport->requests[1]['headers']['Idempotency-Key'], 'same key on the retry');
    same($transport->requests[0]['body'], $transport->requests[1]['body'], 'same body on the retry');

    $client->checkouts->create(['amountCents' => 1000, 'description' => 'Two']);
    check($transport->requests[2]['headers']['Idempotency-Key'] !== $first, 'a new call gets a new key');
});

test('a caller supplied idempotencyKey is sent as is on every attempt', function (): void {
    [$client, $transport] = fixture([
        new ConnectionException('Connection reset by peer'),
        jsonResponse(200, ['object' => 'refund', 'id' => 're_1', 'status' => 'pending']),
    ]);
    $refund = $client->refunds->create(['paymentId' => 'pay_1', 'amountCents' => 5000, 'reason' => 'Returned'], ['idempotencyKey' => 'refund:order-1042.v1']);
    same('re_1', $refund['id'], 'decoded refund');
    same(2, count($transport->requests), 'two attempts');
    foreach ($transport->requests as $i => $r) {
        same('refund:order-1042.v1', $r['headers']['Idempotency-Key'] ?? null, "attempt {$i} key");
    }
});

test('an invalid idempotencyKey or an unknown request option is refused before anything is sent', function (): void {
    [$client, $transport] = fixture([]);
    $params = ['amountCents' => 1000, 'description' => 'x'];
    foreach (['short', str_repeat('a', 256), "order-1042\r\nX-Evil: 1", "order-10421\n", 'order 1042 with spaces', 12345678, ''] as $bad) {
        throws(InvalidArgumentException::class, fn () => $client->checkouts->create($params, ['idempotencyKey' => $bad]), 'idempotencyKey');
    }
    check($client->checkouts instanceof PaymentsLk\Resource\Checkouts, 'resource is typed');
    throws(InvalidArgumentException::class, fn () => $client->checkouts->create($params, ['idempotency_key' => 'order-1042']), 'Unknown request option');
    same(0, count($transport->requests), 'nothing was sent');
});

test('amountCents must be an integer', function (): void {
    [$client, $transport] = fixture([]);
    throws(InvalidArgumentException::class, fn () => $client->checkouts->create(['amountCents' => 10.5, 'description' => 'x']), 'integer');
    throws(InvalidArgumentException::class, fn () => $client->refunds->create(['paymentId' => 'pay_1', 'amountCents' => '1000']), 'integer');
    throws(InvalidArgumentException::class, fn () => $client->paymentLinks->create(['title' => 'x', 'amountCents' => 1000.0]), 'integer');
    throws(InvalidArgumentException::class, fn () => $client->cards->charge('card_1', ['amountCents' => null, 'description' => 'x']), 'integer');
    same(0, count($transport->requests), 'nothing was sent');
});

test('every endpoint uses the documented method and path', function (): void {
    $responses = [];
    for ($i = 0; $i < 14; $i++) {
        $responses[] = jsonResponse(200, ['ok' => true]);
    }
    [$client, $transport] = fixture($responses);
    $client->checkouts->create(['amountCents' => 1000, 'description' => 'x']);
    $client->checkouts->retrieve('chk_1');
    $client->payments->list();
    $client->payments->retrieve('pay_1');
    $client->refunds->create(['paymentId' => 'pay_1']);
    $client->refunds->list();
    $client->refunds->retrieve('re_1');
    $client->paymentLinks->create(['title' => 'Deposit', 'description' => 'Tour deposit', 'amountCents' => 150000]);
    $client->paymentLinks->list();
    $client->paymentLinks->retire('plink_1');
    $client->cards->list();
    $client->cards->retrieve('card_1');
    $client->cards->charge('card_1', ['amountCents' => 1000, 'description' => 'Renewal', 'reference' => 'sub_9']);
    $client->cards->delete('card_1');

    $expected = [
        ['POST', '/v1/checkouts'],
        ['GET', '/v1/checkouts/chk_1'],
        ['GET', '/v1/payments'],
        ['GET', '/v1/payments/pay_1'],
        ['POST', '/v1/refunds'],
        ['GET', '/v1/refunds'],
        ['GET', '/v1/refunds/re_1'],
        ['POST', '/v1/payment_links'],
        ['GET', '/v1/payment_links'],
        ['DELETE', '/v1/payment_links/plink_1'],
        ['GET', '/v1/cards'],
        ['GET', '/v1/cards/card_1'],
        ['POST', '/v1/cards/card_1/charge'],
        ['DELETE', '/v1/cards/card_1'],
    ];
    same(count($expected), count($transport->requests), 'request count');
    foreach ($expected as $i => [$method, $path]) {
        $r = $transport->requests[$i];
        same($method, $r['method'], "#{$i} method");
        same('https://api.payments.lk' . $path, $r['url'], "#{$i} url");
        same($method === 'POST', isset($r['headers']['Idempotency-Key']), "#{$i} Idempotency-Key only on POST");
        same($method === 'POST', $r['body'] !== null, "#{$i} body only on POST");
    }
    // Found in the list rather than counted, so inserting an endpoint above it does not silently check the wrong body.
    $charge = array_search(['POST', '/v1/cards/card_1/charge'], $expected, true);
    same(['amountCents' => 1000, 'description' => 'Renewal', 'reference' => 'sub_9'], json_decode((string) $transport->requests[$charge]['body'], true), 'card charge body');
});

test('events and the account use the documented method, path and query', function (): void {
    $list = ['object' => 'list', 'data' => [], 'hasMore' => false, 'nextCursor' => null];
    [$client, $transport] = fixture([jsonResponse(200, $list), jsonResponse(200, ['id' => 'evt_1', 'object' => 'event']), jsonResponse(200, ['id' => 'mer_1', 'liveEnabled' => true])]);
    $client->events->list(['type' => Webhook::EVENT_REFUND_SUCCEEDED, 'since' => '2026-09-18T00:00:00Z', 'limit' => 5]);
    same('evt_1', $client->events->retrieve('evt_1')['id'], 'event id');
    same(true, $client->account->retrieve()['liveEnabled'], 'account');
    same('https://api.payments.lk/v1/events?limit=5&type=refund.succeeded&since=2026-09-18T00%3A00%3A00Z', $transport->requests[0]['url'], 'events query');
    same('https://api.payments.lk/v1/events/evt_1', $transport->requests[1]['url'], 'event path');
    same('https://api.payments.lk/v1/account', $transport->requests[2]['url'], 'account path');
    foreach ($transport->requests as $i => $r) {
        same('GET', $r['method'], "#{$i} method");
        same(false, isset($r['headers']['Idempotency-Key']), "#{$i} no Idempotency-Key");
    }
    throws(InvalidArgumentException::class, fn () => $client->events->list(['type' => 'payable.provisioning_requested']), 'type must be one of');
    throws(InvalidArgumentException::class, fn () => $client->events->list(['created' => '2026-09-18']), 'Unknown query parameter "created"');
    throws(InvalidArgumentException::class, fn () => $client->events->retrieve(''), 'non empty');
    same(3, count($transport->requests), 'nothing more was sent');
});

test('a checkout from line items in the customer language, and never with an amount too', function (): void {
    [$client, $transport] = fixture([jsonResponse(201, checkoutBody())]);
    $params = [
        'lineItems' => [
            ['name' => 'Chicken kottu', 'unitAmountCents' => 145000, 'quantity' => 2],
            ['name' => 'Ceylon tea', 'description' => '250g, loose leaf', 'unitAmountCents' => 95000, 'quantity' => 1],
        ],
        'reference' => 'order_1043',
        'locale' => 'si',
    ];
    $client->checkouts->create($params);
    same($params, json_decode((string) $transport->requests[0]['body'], true), 'body round trip');
    throws(InvalidArgumentException::class, fn () => $client->checkouts->create($params + ['amountCents' => 385000]), 'not both');
    throws(InvalidArgumentException::class, fn () => $client->checkouts->create(['lineItems' => []]), '1 to 10');
    throws(InvalidArgumentException::class, fn () => $client->checkouts->create(['lineItems' => [['name' => 'Tea', 'unitAmountCents' => 950.0, 'quantity' => 1]]]), 'integer unitAmountCents');
    throws(InvalidArgumentException::class, fn () => $client->checkouts->create(['lineItems' => [['name' => 'Tea', 'unitAmountCents' => 95000, 'quantity' => '1']]]), 'integer quantity');
    same(1, count($transport->requests), 'nothing more was sent');
});

test('an empty 2xx body decodes to an empty array', function (): void {
    [$client] = fixture([new Response(204, [], '')]);
    same([], $client->cards->delete('card_1'), 'empty result');
});

test('a 2xx body that is not JSON is an ApiException', function (): void {
    [$client] = fixture([new Response(200, ['Content-Type' => 'text/html'], '<html>proxy page</html>')]);
    $e = throws(ApiException::class, fn () => $client->payments->retrieve('pay_1'), 'not valid JSON');
    same(200, $e->getHttpStatus(), 'status');
});

// ---------------------------------------------------------------------------
section('Retries');
// ---------------------------------------------------------------------------

test('a 503 is retried after 0.5 seconds plus jitter, then succeeds', function (): void {
    [$client, $transport, $sleeps] = fixture([apiError(503, 'INTERNAL'), jsonResponse(200, ['id' => 'pay_1'])]);
    same(['id' => 'pay_1'], $client->payments->retrieve('pay_1'), 'result after the retry');
    same(2, count($transport->requests), 'two attempts');
    same(1, count($sleeps), 'one sleep');
    between($sleeps[0], 0.5, 0.625, 'first backoff');
});

test('backoff doubles on each attempt', function (): void {
    [$client, $transport, $sleeps] = fixture([
        new Response(500, [], ''),
        new Response(502, [], ''),
        new Response(504, [], ''),
        jsonResponse(200, ['ok' => true]),
    ], ['maxRetries' => 3]);
    $client->payments->list();
    same(4, count($transport->requests), 'four attempts');
    same(3, count($sleeps), 'three sleeps');
    between($sleeps[0], 0.5, 0.625, 'backoff 1');
    between($sleeps[1], 1.0, 1.25, 'backoff 2');
    between($sleeps[2], 2.0, 2.5, 'backoff 3');
});

test('when retries run out the last error is thrown', function (): void {
    [$client, $transport, $sleeps] = fixture([apiError(503, 'INTERNAL'), apiError(503, 'INTERNAL'), apiError(503, 'INTERNAL', 'Still down')]);
    $e = throws(ServerException::class, fn () => $client->payments->retrieve('pay_1'), 'Still down');
    same(503, $e->getHttpStatus(), 'status');
    same(3, count($transport->requests), 'maxRetries 2 means three attempts');
    same(2, count($sleeps), 'two sleeps');
});

test('a connection failure is retried, and thrown once retries run out', function (): void {
    [$client, $transport] = fixture([new ConnectionException('timed out'), jsonResponse(200, ['ok' => true])]);
    same(['ok' => true], $client->cards->list(), 'result after reconnecting');
    same(2, count($transport->requests), 'two attempts');

    [$client, $transport, $sleeps] = fixture([
        new ConnectionException('refused 1'),
        new ConnectionException('refused 2'),
        new ConnectionException('refused 3'),
    ]);
    throws(ConnectionException::class, fn () => $client->checkouts->create(['amountCents' => 1000, 'description' => 'x']), 'refused 3');
    same(3, count($transport->requests), 'three attempts');
    same(2, count($sleeps), 'two sleeps');
    same(1, count(array_unique(array_map(fn (array $r) => $r['headers']['Idempotency-Key'], $transport->requests))), 'one key across all attempts');
});

test('a 400 is never retried', function (): void {
    [$client, $transport, $sleeps] = fixture([apiError(400, 'VALIDATION_FAILED'), jsonResponse(200, ['ok' => true])]);
    throws(ValidationException::class, fn () => $client->checkouts->create(['amountCents' => 1, 'description' => 'x']));
    same(1, count($transport->requests), 'one attempt');
    same(0, count($sleeps), 'no sleep');
});

test('no other 4xx except 429 is retried', function (): void {
    foreach ([[401, 'UNAUTHENTICATED'], [403, 'FORBIDDEN'], [404, 'NOT_FOUND'], [409, 'CONFLICT'], [422, 'VALIDATION_FAILED']] as [$status, $code]) {
        [$client, $transport, $sleeps] = fixture([apiError($status, $code), jsonResponse(200, ['ok' => true])]);
        throws(ApiException::class, fn () => $client->payments->retrieve('pay_1'));
        same(1, count($transport->requests), "{$status} attempts");
        same(0, count($sleeps), "{$status} sleeps");
    }
    foreach ([501, 505] as $status) {
        [$client, $transport] = fixture([new Response($status, [], ''), jsonResponse(200, ['ok' => true])]);
        throws(ServerException::class, fn () => $client->payments->retrieve('pay_1'));
        same(1, count($transport->requests), "{$status} is not in the retry list");
    }
});

test('a numeric Retry-After is honored through the injected sleep', function (): void {
    [$client, $transport, $sleeps] = fixture([
        new Response(429, ['Retry-After' => '3', 'Content-Type' => 'application/json'], '{"code":"RATE_LIMITED","message":"Slow down","traceId":"tr_9"}'),
        jsonResponse(200, ['ok' => true]),
    ]);
    same(['ok' => true], $client->payments->list(['limit' => 10]), 'result after waiting');
    same(2, count($transport->requests), 'two attempts');
    same([3.0], $sleeps->getArrayCopy(), 'slept exactly Retry-After');
});

test('Retry-After is capped at 20 seconds and a date form falls back to backoff', function (): void {
    [$client, , $sleeps] = fixture([
        new Response(503, ['retry-after' => '120'], ''),
        new Response(429, ['Retry-After' => '1.5'], ''),
        jsonResponse(200, ['ok' => true]),
    ]);
    $client->payments->list();
    same([20.0, 1.5], $sleeps->getArrayCopy(), 'capped, then fractional');

    [$client, , $sleeps] = fixture([
        new Response(429, ['Retry-After' => 'Wed, 16 Sep 2026 07:28:00 GMT'], ''),
        jsonResponse(200, ['ok' => true]),
    ]);
    $client->payments->list();
    between($sleeps[0], 0.5, 0.625, 'date form uses backoff');
});

test('maxRetries 0 sends exactly once', function (): void {
    [$client, $transport, $sleeps] = fixture([new Response(429, ['Retry-After' => '1'], '{"code":"RATE_LIMITED","message":"Slow down","traceId":"tr_1"}')], ['maxRetries' => 0]);
    throws(RateLimitException::class, fn () => $client->payments->list(), 'Slow down');
    same(1, count($transport->requests), 'one attempt');
    same(0, count($sleeps), 'no sleep');
});

// ---------------------------------------------------------------------------
section('Errors');
// ---------------------------------------------------------------------------

test('each error status maps to its exception with code, message, trace id and fields', function (): void {
    $cases = [
        [400, 'VALIDATION_FAILED', ValidationException::class],
        [401, 'UNAUTHENTICATED', AuthenticationException::class],
        [403, 'FORBIDDEN', PermissionException::class],
        [404, 'NOT_FOUND', NotFoundException::class],
        [409, 'CONFLICT', ConflictException::class],
        [422, 'VALIDATION_FAILED', ValidationException::class],
        [429, 'RATE_LIMITED', RateLimitException::class],
        [500, 'INTERNAL', ServerException::class],
        [501, 'NOT_SUPPORTED', ServerException::class],
        [503, 'INTERNAL', ServerException::class],
    ];
    foreach ($cases as [$status, $code, $class]) {
        $fields = $status === 422 ? [['path' => 'customer.email', 'message' => 'Enter a valid email address.']] : [];
        $body = ['code' => $code, 'message' => "Message for {$status}", 'traceId' => "tr_{$status}"];
        if ($fields !== []) {
            $body['fields'] = $fields;
        }
        [$client] = fixture([jsonResponse($status, $body)], ['maxRetries' => 0]);
        $e = throws($class, fn () => $client->payments->retrieve('pay_1'));
        same($class, get_class($e), "{$status} class");
        check($e instanceof ApiException && $e instanceof ExceptionInterface, "{$status} is an ApiException and an SDK exception");
        same($status, $e->getHttpStatus(), "{$status} getHttpStatus");
        same($code, $e->getErrorCode(), "{$status} getErrorCode");
        same("tr_{$status}", $e->getTraceId(), "{$status} getTraceId");
        same("Message for {$status}", $e->getMessage(), "{$status} getMessage");
        same($fields, $e->getFields(), "{$status} getFields");
    }
});

test('reason and docUrl are read from the body, and the link ends the message', function (): void {
    [$client, $transport, $sleeps] = fixture([jsonResponse(409, [
        'code' => 'CONFLICT',
        'message' => 'That key was used with a different request.',
        'traceId' => 'tr_409',
        'reason' => 'idempotency_key_reused',
        'docUrl' => 'https://payments.lk/developers/errors#conflict',
    ]), jsonResponse(201, checkoutBody())]);
    $e = throws(ConflictException::class, fn () => $client->checkouts->create(['amountCents' => 1000, 'description' => 'x'], ['idempotencyKey' => 'order_1042_try_1']));
    same(1, count($transport->requests), 'a 409 is never retried');
    same(0, count($sleeps), 'no sleep');
    same('idempotency_key_reused', $e->getReason(), 'getReason');
    same('https://payments.lk/developers/errors#conflict', $e->getDocUrl(), 'getDocUrl');
    same('That key was used with a different request.', $e->getApiMessage(), 'getApiMessage');
    same('That key was used with a different request. See https://payments.lk/developers/errors#conflict', $e->getMessage(), 'getMessage');

    [$client] = fixture([jsonResponse(404, ['code' => 'NOT_FOUND', 'message' => 'Not found.', 'traceId' => 't', 'docUrl' => 'javascript:alert(1)']), jsonResponse(409, ['code' => 'CONFLICT', 'reason' => 'already_refunded'])], ['maxRetries' => 0]);
    $plain = throws(NotFoundException::class, fn () => $client->payments->retrieve('pay_1'));
    same(null, $plain->getReason(), 'no reason');
    same(null, $plain->getDocUrl(), 'a link that is not https on payments.lk is dropped');
    same('Not found.', $plain->getMessage(), 'message unchanged');
    $bare = throws(ConflictException::class, fn () => $client->payments->retrieve('pay_1'));
    same('The Payments.lk API returned HTTP 409 (already_refunded).', $bare->getMessage(), 'the reason names a bare error');
});

test('docUrl is kept only when it is an https address on payments.lk, and otherwise never reaches the message', function (): void {
    $ours = [
        'https://payments.lk/developers/errors#conflict',
        'https://payments.lk',
        'https://payments.lk/',
        'https://PAYMENTS.LK/developers/errors#conflict',
        'https://docs.payments.lk/errors?code=conflict#top',
        'https://a.b-c.payments.lk/x',
    ];
    $notOurs = [
        'http://payments.lk/developers/errors#conflict',
        'https://evil.test/developers/errors#conflict',
        'https://payments.lk.evil.test/developers/errors',
        'https://evilpayments.lk/developers/errors',
        'https://payments.lk@evil.test/',
        'https://user:pass@payments.lk/',
        'https://payments.lk:8443/',
        'https://payments.lk\\@evil.test/',
        'https://evil.test\\@payments.lk/',
        'https://evil.test/https://payments.lk/',
        'https://evil.test?https://payments.lk/',
        'https://evil.test#https://payments.lk/',
        'https://payments.lk./',
        'https://-payments.lk/',
        'https://.payments.lk/',
        'https://xn--pyments-1fb.lk/',
        "https://payments.lk/developers/errors\nFAKE log line",
        "https://payments.lk/developers/errors\n",
        'https://payments.lk/developers/errors see https://evil.test',
        ' https://payments.lk/',
        "https://payments.lk/\t",
        'https://payments.lk/\\evil',
        '//payments.lk/developers/errors',
        '/developers/errors',
        'javascript:alert(1)//payments.lk/',
        'data:text/html,https://payments.lk/',
        'ftp://payments.lk/',
        '',
        'https://payments.lk/' . str_repeat('a', 500),
        42,
        null,
        ['https://payments.lk/'],
    ];
    $error = static function (mixed $docUrl): ApiException {
        [$client] = fixture([jsonResponse(409, ['code' => 'CONFLICT', 'message' => 'That key was used with a different request.', 'traceId' => 't-1', 'docUrl' => $docUrl])], ['maxRetries' => 0]);

        return throws(ConflictException::class, fn () => $client->payments->retrieve('pay_1'));
    };
    foreach ($ours as $docUrl) {
        $e = $error($docUrl);
        same($docUrl, $e->getDocUrl(), 'kept: ' . $docUrl);
        same('That key was used with a different request. See ' . $docUrl, $e->getMessage(), 'linked: ' . $docUrl);
    }
    foreach ($notOurs as $docUrl) {
        $e = $error($docUrl);
        same(null, $e->getDocUrl(), 'dropped: ' . show($docUrl));
        same('That key was used with a different request.', $e->getMessage(), 'not in the message: ' . show($docUrl));
        same('CONFLICT', $e->getErrorCode(), 'the rest of the body is still read');
        same('t-1', $e->getTraceId(), 'the rest of the body is still read');
    }
    same(null, (new ApiException('No.', 400, null, null, [], null, null, 'https://evil.test/'))->getDocUrl(), 'an exception built directly is held to the same rule');
    same('https://payments.lk/developers/errors#x', (new ApiException('No.', 400, null, null, [], null, null, 'https://payments.lk/developers/errors#x'))->getDocUrl(), 'and keeps an address of ours');
});

test('an unlisted 4xx is a plain ApiException and malformed fields are dropped', function (): void {
    [$client] = fixture([jsonResponse(418, ['code' => 'TEAPOT', 'message' => 'No', 'traceId' => 'tr_x', 'fields' => [['path' => 'a', 'message' => 'b'], ['path' => 1], 'junk']])]);
    $e = throws(ApiException::class, fn () => $client->payments->retrieve('pay_1'));
    same(ApiException::class, get_class($e), 'class');
    same([['path' => 'a', 'message' => 'b']], $e->getFields(), 'fields');
});

test('a non JSON error body still produces the right subclass with a generic message', function (): void {
    $cases = [
        [502, '<html><body>Bad gateway</body></html>', ServerException::class],
        [401, 'Unauthorized', AuthenticationException::class],
        [404, '', NotFoundException::class],
        [422, '"just a string"', ValidationException::class],
        [429, 'Too many', RateLimitException::class],
    ];
    foreach ($cases as [$status, $body, $class]) {
        [$client] = fixture([new Response($status, ['Content-Type' => 'text/html'], $body)], ['maxRetries' => 0]);
        $e = throws($class, fn () => $client->payments->retrieve('pay_1'), "HTTP {$status}");
        same($class, get_class($e), "{$status} class");
        same($status, $e->getHttpStatus(), "{$status} status");
        same(null, $e->getErrorCode(), "{$status} code");
        same(null, $e->getTraceId(), "{$status} trace id");
        same([], $e->getFields(), "{$status} fields");
    }
});

// ---------------------------------------------------------------------------
section('Paths, queries and pagination');
// ---------------------------------------------------------------------------

test('path ids are rawurlencoded', function (): void {
    $responses = [];
    for ($i = 0; $i < 6; $i++) {
        $responses[] = jsonResponse(200, ['ok' => true]);
    }
    [$client, $transport] = fixture($responses);
    $client->payments->retrieve('pay 1/../2?x=#y');
    $client->cards->charge('card/1', ['amountCents' => 1000, 'description' => 'x']);
    $client->refunds->retrieve('re_1+2&3');
    $client->checkouts->retrieve("chk_\u{00fc}");
    $client->paymentLinks->retire('plink_%2F');
    $client->cards->delete('..x');
    $urls = array_map(fn (array $r) => $r['url'], $transport->requests);
    same([
        'https://api.payments.lk/v1/payments/pay%201%2F..%2F2%3Fx%3D%23y',
        'https://api.payments.lk/v1/cards/card%2F1/charge',
        'https://api.payments.lk/v1/refunds/re_1%2B2%263',
        'https://api.payments.lk/v1/checkouts/chk_%C3%BC',
        'https://api.payments.lk/v1/payment_links/plink_%252F',
        'https://api.payments.lk/v1/cards/..x',
    ], $urls, 'encoded urls');
});

test('empty, dot and dot dot ids are refused before sending', function (): void {
    [$client, $transport] = fixture([]);
    foreach (['', '.', '..'] as $id) {
        throws(InvalidArgumentException::class, fn () => $client->checkouts->retrieve($id), 'non empty');
        throws(InvalidArgumentException::class, fn () => $client->payments->retrieve($id));
        throws(InvalidArgumentException::class, fn () => $client->refunds->retrieve($id));
        throws(InvalidArgumentException::class, fn () => $client->paymentLinks->retire($id));
        throws(InvalidArgumentException::class, fn () => $client->cards->charge($id, ['amountCents' => 1000, 'description' => 'x']));
        throws(InvalidArgumentException::class, fn () => $client->cards->delete($id));
    }
    same(0, count($transport->requests), 'nothing was sent');
});

test('list queries are built in a stable order, RFC 3986 encoded, nulls skipped', function (): void {
    [$client, $transport] = fixture([
        jsonResponse(200, ['object' => 'list', 'data' => [], 'hasMore' => false, 'nextCursor' => null]),
        jsonResponse(200, ['object' => 'list', 'data' => [], 'hasMore' => false, 'nextCursor' => null]),
        jsonResponse(200, ['object' => 'list', 'data' => [], 'hasMore' => false, 'nextCursor' => null]),
    ]);
    $client->payments->list(['status' => 'succeeded', 'cursor' => 'abc=/+ x', 'limit' => 100]);
    $client->refunds->list(['paymentId' => 'pay_1', 'limit' => 1, 'cursor' => null]);
    $client->payments->list([]);
    same('https://api.payments.lk/v1/payments?limit=100&cursor=abc%3D%2F%2B%20x&status=succeeded', $transport->requests[0]['url'], 'payments query');
    same('https://api.payments.lk/v1/refunds?limit=1&paymentId=pay_1', $transport->requests[1]['url'], 'refunds query');
    same('https://api.payments.lk/v1/payments', $transport->requests[2]['url'], 'no query string when empty');
});

test('invalid list queries are refused before sending', function (): void {
    [$client, $transport] = fixture([]);
    throws(InvalidArgumentException::class, fn () => $client->payments->list(['limit' => 0]), '1 to 100');
    throws(InvalidArgumentException::class, fn () => $client->payments->list(['limit' => 101]), '1 to 100');
    throws(InvalidArgumentException::class, fn () => $client->payments->list(['limit' => '25']), '1 to 100');
    throws(InvalidArgumentException::class, fn () => $client->payments->list(['paymentId' => 'pay_1']), 'Unknown query parameter "paymentId"');
    throws(InvalidArgumentException::class, fn () => $client->refunds->list(['status' => 'succeeded']), 'Unknown query parameter "status"');
    throws(InvalidArgumentException::class, fn () => $client->refunds->list(['paymentId' => ['pay_1']]), 'non empty string');
    throws(InvalidArgumentException::class, fn () => $client->payments->all(['limit' => 500]), '1 to 100');
    same(0, count($transport->requests), 'nothing was sent');
});

test('payments->all() follows nextCursor across two pages, lazily', function (): void {
    [$client, $transport] = fixture([
        jsonResponse(200, ['object' => 'list', 'data' => [['id' => 'pay_1'], ['id' => 'pay_2']], 'hasMore' => true, 'nextCursor' => 'cur/2=']),
        jsonResponse(200, ['object' => 'list', 'data' => [['id' => 'pay_3']], 'hasMore' => false, 'nextCursor' => null]),
    ]);
    $iterator = $client->payments->all(['limit' => 2, 'status' => 'succeeded']);
    same(0, count($transport->requests), 'no request until iterated');
    $ids = [];
    foreach ($iterator as $payment) {
        $ids[] = $payment['id'];
    }
    same(['pay_1', 'pay_2', 'pay_3'], $ids, 'every item in order');
    same(2, count($transport->requests), 'two pages');
    same('https://api.payments.lk/v1/payments?limit=2&status=succeeded', $transport->requests[0]['url'], 'first page');
    same('https://api.payments.lk/v1/payments?limit=2&cursor=cur%2F2%3D&status=succeeded', $transport->requests[1]['url'], 'second page carries the cursor');
});

test('refunds->all() stops when hasMore is false or nextCursor is missing, and refuses a repeated cursor', function (): void {
    [$client, $transport] = fixture([
        jsonResponse(200, ['object' => 'list', 'data' => [['id' => 're_1']], 'hasMore' => true, 'nextCursor' => null]),
    ]);
    same(['re_1'], array_map(fn (array $r) => $r['id'], iterator_to_array($client->refunds->all(['paymentId' => 'pay_1']), false)), 'one page');
    same('https://api.payments.lk/v1/refunds?paymentId=pay_1', $transport->requests[0]['url'], 'query');

    [$client] = fixture([
        jsonResponse(200, ['object' => 'list', 'data' => [['id' => 're_1']], 'hasMore' => true, 'nextCursor' => 'same']),
        jsonResponse(200, ['object' => 'list', 'data' => [['id' => 're_2']], 'hasMore' => true, 'nextCursor' => 'same']),
    ]);
    throws(UnexpectedValueException::class, fn () => iterator_to_array($client->refunds->all(), false), 'same cursor twice');
});

test('cards->list() filters by customer and cards->all() walks every page', function (): void {
    [$client, $transport] = fixture([
        jsonResponse(200, ['object' => 'list', 'data' => [['id' => 'card_1']], 'hasMore' => true, 'nextCursor' => 'card_1']),
        jsonResponse(200, ['object' => 'list', 'data' => [['id' => 'card_2']], 'hasMore' => false, 'nextCursor' => null]),
    ]);
    $ids = array_map(fn (array $c) => $c['id'], iterator_to_array($client->cards->all(['customerEmail' => 'ruwan@example.lk', 'limit' => 1]), false));
    same(['card_1', 'card_2'], $ids, 'every card in order');
    same('https://api.payments.lk/v1/cards?limit=1&customerEmail=ruwan%40example.lk', $transport->requests[0]['url'], 'first page');
    same('https://api.payments.lk/v1/cards?limit=1&cursor=card_1&customerEmail=ruwan%40example.lk', $transport->requests[1]['url'], 'second page carries the cursor');

    [$client] = fixture([]);
    throws(InvalidArgumentException::class, fn () => $client->cards->list(['status' => 'active']), 'Unknown query parameter "status"');
});

test('listAll() and all() are the same walk, on payments, refunds and events', function (): void {
    $page = static fn (array $ids, ?string $next): Response => jsonResponse(200, ['object' => 'list', 'data' => array_map(fn (string $id) => ['id' => $id], $ids), 'hasMore' => $next !== null, 'nextCursor' => $next]);
    [$client, $transport] = fixture([
        $page(['evt_1'], 'evt_1'),
        $page(['evt_2'], null),
        $page(['pay_1'], null),
        $page(['re_1'], null),
        $page(['evt_3'], null),
    ]);
    $ids = [];
    foreach ($client->events->listAll(['type' => Webhook::EVENT_PAYMENT_SUCCEEDED]) as $event) {
        $ids[] = $event['id'];
    }
    foreach ($client->payments->listAll() as $payment) {
        $ids[] = $payment['id'];
    }
    foreach ($client->refunds->listAll(['paymentId' => 'pay_1']) as $refund) {
        $ids[] = $refund['id'];
    }
    foreach ($client->events->all() as $event) {
        $ids[] = $event['id'];
    }
    same(['evt_1', 'evt_2', 'pay_1', 're_1', 'evt_3'], $ids, 'every item in order');
    same('https://api.payments.lk/v1/events?cursor=evt_1&type=payment.succeeded', $transport->requests[1]['url'], 'the cursor rides with the filter');
    throws(InvalidArgumentException::class, fn () => iterator_to_array($client->events->listAll(['type' => 'nope']), false), 'type must be one of');
});

// ---------------------------------------------------------------------------
section('Billing');
// ---------------------------------------------------------------------------

/**
 * The API's document, in the repository beside this library. A copy of the library on its own has no document to
 * check against, so the tests that need it are skipped there.
 *
 * @return array<string, mixed>|null
 */
function apiDocument(): ?array
{
    $file = dirname(__DIR__, 3) . '/packages/openapi/openapi.json';
    if (!is_file($file)) {
        return null;
    }
    $document = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

    return is_array($document) ? $document : null;
}

/** Calls every method the library has, once, and answers each with an empty list. */
function callEverything(Client $client): void
{
    $id = 'x_1';
    $client->checkouts->create(['amountCents' => 1000, 'description' => 'x']);
    $client->checkouts->retrieve($id);
    $client->payments->list();
    $client->payments->retrieve($id);
    $client->refunds->create(['paymentId' => 'pay_1']);
    $client->refunds->list();
    $client->refunds->retrieve($id);
    $client->paymentLinks->create(['title' => 'x', 'amountCents' => 1000]);
    $client->paymentLinks->list();
    $client->paymentLinks->retire($id);
    $client->cards->list();
    $client->cards->retrieve($id);
    $client->cards->charge($id, ['amountCents' => 1000, 'description' => 'x']);
    $client->cards->delete($id);
    $client->events->list();
    $client->events->retrieve($id);
    $client->account->retrieve();
    $client->customers->create(['email' => 'nimali@example.lk']);
    $client->customers->list();
    $client->customers->retrieve($id);
    $client->customers->createBalanceTransaction($id, ['amountCents' => 5000]);
    $client->customers->listBalanceTransactions($id);
    $client->prices->create(['product' => ['name' => 'Tea club'], 'unitAmountCents' => 250000, 'interval' => 'month']);
    $client->prices->list();
    $client->prices->retrieve($id);
    $client->products->attachFeature($id, ['featureId' => 'feat_1']);
    $client->products->listFeatures($id);
    $client->products->detachFeature($id, 'feat_1');
    $client->subscriptions->create(['customerId' => 'cus_1', 'priceId' => 'price_1']);
    $client->subscriptions->list();
    $client->subscriptions->retrieve($id);
    $client->subscriptions->update($id, ['quantity' => 2]);
    $client->subscriptions->cancel($id);
    $client->subscriptions->resume($id);
    $client->invoices->create(['customerId' => 'cus_1']);
    $client->invoices->list();
    $client->invoices->retrieve($id);
    $client->invoices->upcoming(['subscriptionId' => 'sub_1']);
    $client->invoices->preview(['subscriptionId' => 'sub_1', 'quantity' => 2]);
    $client->invoices->send($id);
    $client->invoices->pay($id);
    $client->invoices->finalize($id);
    $client->invoices->void($id);
    $client->invoices->markUncollectible($id);
    $client->creditNotes->create(['invoiceId' => 'in_1', 'amountCents' => 1000]);
    $client->creditNotes->list();
    $client->creditNotes->retrieve($id);
    $client->coupons->create(['name' => 'Launch', 'percentOffBps' => 1000, 'duration' => 'once']);
    $client->coupons->list();
    $client->coupons->retrieve($id);
    $client->coupons->archive($id);
    $client->taxRates->create(['displayName' => 'VAT', 'percentageBps' => 1800]);
    $client->taxRates->list();
    $client->taxRates->retrieve($id);
    $client->taxRates->update($id, ['displayName' => 'VAT 18%']);
    $client->invoiceItems->create(['customerId' => 'cus_1', 'description' => 'x', 'unitAmountCents' => 1000]);
    $client->invoiceItems->list();
    $client->invoiceItems->delete($id);
    $client->testClocks->advance(['subscriptionId' => 'sub_1', 'seconds' => 3600]);
    $client->subscriptionSchedules->create(['customerId' => 'cus_1', 'phases' => [['items' => [['priceId' => 'price_1']]]]]);
    $client->subscriptionSchedules->list();
    $client->subscriptionSchedules->retrieve($id);
    $client->subscriptionSchedules->cancel($id);
    $client->subscriptionSchedules->release($id);
    $client->entitlements->features->create(['name' => 'Pool', 'lookupKey' => 'pool']);
    $client->entitlements->features->list();
    $client->entitlements->activeEntitlements->list(['customerId' => 'cus_1']);
    $client->meters->create(['name' => 'Cups', 'eventName' => 'cups']);
    $client->meters->list();
    $client->meters->retrieve($id);
    $client->meters->update($id, ['name' => 'Cups brewed']);
    $client->meters->eventSummary($id, ['customerId' => 'cus_1', 'start' => '2026-09-01T00:00:00Z', 'end' => '2026-10-01T00:00:00Z']);
    $client->meterEvents->create(['eventName' => 'cups', 'customerId' => 'cus_1']);
    $client->usageAlerts->create(['meterId' => 'mtr_1', 'title' => 'Heavy', 'threshold' => 100]);
    $client->usageAlerts->list();
    $client->usageAlerts->archive($id);
    $client->subscriptionLinks->create(['name' => 'Join', 'priceIds' => ['price_1']]);
    $client->subscriptionLinks->list();
    $client->subscriptionLinks->retrieve($id);
    $client->subscriptionLinks->update($id, ['active' => false]);
    $client->billingPortal->sessions->create(['customerId' => 'cus_1']);
}

test('there is a method for every operation in the API\'s document, each on its method and path', function (): void {
    $document = apiDocument();
    if ($document === null) {
        skip('the API document is not beside this copy of the library', 'no packages/openapi/openapi.json');

        return;
    }
    $operations = [];
    foreach ($document['paths'] as $path => $methods) {
        foreach ($methods as $method => $operation) {
            $id = $operation['operationId'] ?? null;
            if (!is_string($id) || $id === 'getOpenApiDocument') {
                continue;
            }
            $pattern = '#^' . preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', preg_quote((string) $path, '#')) . '$#';
            $operations[] = ['id' => $id, 'method' => strtoupper((string) $method), 'path' => (string) $path, 'pattern' => $pattern];
        }
    }
    $list = ['object' => 'list', 'data' => [], 'hasMore' => false, 'nextCursor' => null];
    $responses = [];
    for ($i = 0; $i < 120; $i++) {
        $responses[] = jsonResponse(200, $list);
    }
    [$client, $transport] = fixture($responses);
    callEverything($client);

    // A literal path (/v1/invoices/upcoming) wins over a template it also fits (/v1/invoices/{id}).
    $operationOf = static function (string $method, string $path) use ($operations): ?string {
        $template = null;
        foreach ($operations as $op) {
            if ($op['method'] !== $method || preg_match($op['pattern'], $path) !== 1) {
                continue;
            }
            if ($op['path'] === $path) {
                return $op['id'];
            }
            $template ??= $op['id'];
        }

        return $template;
    };
    $reached = [];
    foreach ($transport->requests as $i => $r) {
        $path = (string) parse_url($r['url'], PHP_URL_PATH);
        $id = $operationOf($r['method'], $path);
        check($id !== null, "#{$i} {$r['method']} {$path} is an operation in the API's document");
        $reached[] = $id;
        same($r['method'] === 'POST', isset($r['headers']['Idempotency-Key']), "#{$i} Idempotency-Key only on POST");
    }
    $missing = array_values(array_diff(array_column($operations, 'id'), $reached));
    same([], $missing, 'operations the library has no method for');
});

test('billing lists take their filters in a stable order and refuse unknown ones before sending', function (): void {
    $list = ['object' => 'list', 'data' => [], 'hasMore' => false, 'nextCursor' => null];
    [$client, $transport] = fixture([jsonResponse(200, $list), jsonResponse(200, $list), jsonResponse(200, ['object' => 'invoice']), jsonResponse(200, ['aggregatedValue' => 3])]);
    $client->subscriptions->list(['status' => 'active', 'customerId' => 'cus_1', 'limit' => 5]);
    $client->invoiceItems->list(['pending' => 'true']);
    $client->invoices->upcoming(['subscriptionId' => 'sub_1']);
    same(3, $client->meters->eventSummary('mtr_1', ['customerId' => 'cus_1', 'start' => '2026-09-01T00:00:00Z', 'end' => '2026-10-01T00:00:00Z'])['aggregatedValue'], 'summary');
    same('https://api.payments.lk/v1/subscriptions?limit=5&customerId=cus_1&status=active', $transport->requests[0]['url'], 'subscriptions query');
    same('https://api.payments.lk/v1/invoice_items?pending=true', $transport->requests[1]['url'], 'invoice items query');
    same('https://api.payments.lk/v1/invoices/upcoming?subscriptionId=sub_1', $transport->requests[2]['url'], 'upcoming query');
    same('https://api.payments.lk/v1/meters/mtr_1/event_summaries?customerId=cus_1&start=2026-09-01T00%3A00%3A00Z&end=2026-10-01T00%3A00%3A00Z', $transport->requests[3]['url'], 'summary query');
    throws(InvalidArgumentException::class, fn () => $client->subscriptions->list(['plan' => 'gold']), 'Unknown query parameter "plan"');
    throws(InvalidArgumentException::class, fn () => $client->customers->createBalanceTransaction('cus_1', ['amountCents' => 50.0]), 'amountCents must be an integer');
    throws(InvalidArgumentException::class, fn () => $client->products->detachFeature('prod_1', ''), 'non empty');
    same(4, count($transport->requests), 'nothing more was sent');
});

test('an action with nothing to send posts an empty object with an idempotency key', function (): void {
    [$client, $transport] = fixture([jsonResponse(200, ['object' => 'invoice', 'status' => 'void'])]);
    same('void', $client->invoices->void('in_1', ['idempotencyKey' => 'void-in_1'])['status'], 'status');
    same('POST', $transport->requests[0]['method'], 'method');
    same('https://api.payments.lk/v1/invoices/in_1/void', $transport->requests[0]['url'], 'path');
    same('{}', $transport->requests[0]['body'], 'body');
    same('void-in_1', $transport->requests[0]['headers']['Idempotency-Key'], 'the caller\'s key');
});

test('every event type in the API\'s document is one EVENT_TYPES lists, and events->list takes each', function (): void {
    $document = apiDocument();
    if ($document === null) {
        skip('the API document is not beside this copy of the library', 'no packages/openapi/openapi.json');

        return;
    }
    $types = array_keys($document['webhooks'] ?? []);
    check(count($types) > 6, 'the document lists the webhook events');
    same($types, Webhook::EVENT_TYPES, 'EVENT_TYPES');
    [$client, $transport] = fixture([jsonResponse(200, ['object' => 'list', 'data' => [], 'hasMore' => false, 'nextCursor' => null])]);
    $client->events->list(['type' => Webhook::EVENT_INVOICE_PAID]);
    same('https://api.payments.lk/v1/events?type=invoice.paid', $transport->requests[0]['url'], 'a billing event type is sent');
});

// ---------------------------------------------------------------------------
section('Webhooks');
// ---------------------------------------------------------------------------

const WEBHOOK_SECRET = 'whsec_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
const NOW = 1726400000;

function eventPayload(): string
{
    return json_encode([
        'id' => 'evt_123',
        'object' => 'event',
        'type' => 'payment.succeeded',
        'mode' => 'test',
        'created' => NOW,
        'data' => ['object' => 'payment', 'id' => 'pay_1', 'amountCents' => 250000],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

test('the cross language vector computed with Node crypto.createHmac verifies', function (): void {
    // Computed once with:
    // node -e 'const c=require("crypto");const b="{\"id\":\"evt_1\",\"object\":\"event\"}";
    //   console.log("t=1726400000,v1="+c.createHmac("sha256","whsec_test").update("1726400000."+b).digest("hex"))'
    $body = '{"id":"evt_1","object":"event"}';
    $header = 't=1726400000,v1=da3ac9bfe911ba0bc6b90e3c5591d7942230249bc4c73334e8def4d9354a14aa';
    same($header, sign($body, 'whsec_test', 1726400000), 'PHP hash_hmac agrees with Node');
    $event = Webhook::constructEvent($body, $header, 'whsec_test', 300, 1726400000);
    same(['id' => 'evt_1', 'object' => 'event'], $event, 'decoded event');
});

test('a good signature returns the decoded event', function (): void {
    $payload = eventPayload();
    $event = Webhook::constructEvent($payload, sign($payload, WEBHOOK_SECRET, NOW), WEBHOOK_SECRET, 300, NOW);
    same('evt_123', $event['id'], 'id');
    same('payment.succeeded', $event['type'], 'type');
    same(Webhook::EVENT_PAYMENT_SUCCEEDED, $event['type'], 'type constant');
    same('pay_1', $event['data']['id'], 'data');
    $event = Webhook::constructEvent($payload, sign($payload, WEBHOOK_SECRET, time()), WEBHOOK_SECRET);
    same('evt_123', $event['id'], 'defaults to the real clock and a 300 second tolerance');
});

test('a wrong secret is refused', function (): void {
    $payload = eventPayload();
    $header = sign($payload, 'whsec_BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB', NOW);
    throws(SignatureVerificationException::class, fn () => Webhook::constructEvent($payload, $header, WEBHOOK_SECRET, 300, NOW), 'No v1 signature matches');
});

test('a tampered body is refused', function (): void {
    $payload = eventPayload();
    $header = sign($payload, WEBHOOK_SECRET, NOW);
    $tampered = str_replace('250000', '250001', $payload);
    throws(SignatureVerificationException::class, fn () => Webhook::constructEvent($tampered, $header, WEBHOOK_SECRET, 300, NOW), 'No v1 signature matches');
    throws(SignatureVerificationException::class, fn () => Webhook::constructEvent($payload . "\n", $header, WEBHOOK_SECRET, 300, NOW), 'No v1 signature matches');
});

test('a stale timestamp is refused, the boundary is accepted', function (): void {
    $payload = eventPayload();
    $old = NOW - 301;
    throws(SignatureVerificationException::class, fn () => Webhook::constructEvent($payload, sign($payload, WEBHOOK_SECRET, $old), WEBHOOK_SECRET, 300, NOW), 'seconds old');
    $edge = NOW - 300;
    same('evt_123', Webhook::constructEvent($payload, sign($payload, WEBHOOK_SECRET, $edge), WEBHOOK_SECRET, 300, NOW)['id'], 'exactly 300 seconds old is accepted');
    throws(SignatureVerificationException::class, fn () => Webhook::constructEvent($payload, sign($payload, WEBHOOK_SECRET, NOW - 61), WEBHOOK_SECRET, 60, NOW), '60 seconds old');
});

test('a future timestamp is refused, the boundary is accepted', function (): void {
    $payload = eventPayload();
    throws(SignatureVerificationException::class, fn () => Webhook::constructEvent($payload, sign($payload, WEBHOOK_SECRET, NOW + 301), WEBHOOK_SECRET, 300, NOW), 'in the future');
    same('evt_123', Webhook::constructEvent($payload, sign($payload, WEBHOOK_SECRET, NOW + 300), WEBHOOK_SECRET, 300, NOW)['id'], 'exactly 300 seconds ahead is accepted');
});

test('any one of several v1 values may match', function (): void {
    $payload = eventPayload();
    $good = hash_hmac('sha256', NOW . '.' . $payload, WEBHOOK_SECRET);
    $other = hash_hmac('sha256', NOW . '.' . $payload, 'whsec_old_secret');
    same('evt_123', Webhook::constructEvent($payload, 't=' . NOW . ",v1={$other},v1={$good}", WEBHOOK_SECRET, 300, NOW)['id'], 'second of two');
    same('evt_123', Webhook::constructEvent($payload, "v1={$good}, t=" . NOW . ", v1={$other}, v0=ignored", WEBHOOK_SECRET, 300, NOW)['id'], 'any order, spaces, unknown schemes ignored');
    throws(SignatureVerificationException::class, fn () => Webhook::constructEvent($payload, 't=' . NOW . ",v1={$other},v1={$other}", WEBHOOK_SECRET, 300, NOW), 'No v1 signature matches');
    throws(SignatureVerificationException::class, fn () => Webhook::constructEvent($payload, 't=' . NOW . ',v1=' . strtoupper($good), WEBHOOK_SECRET, 300, NOW), 'no valid v1');
});

test('malformed Payments-Signature headers are refused with a clear message', function (): void {
    $payload = eventPayload();
    $good = hash_hmac('sha256', NOW . '.' . $payload, WEBHOOK_SECRET);
    $cases = [
        [null, 'no Payments-Signature header'],
        ['', 'no Payments-Signature header'],
        ['   ', 'no Payments-Signature header'],
        ['garbage', 'no valid timestamp'],
        ["v1={$good}", 'no valid timestamp'],
        ["t=abc,v1={$good}", 'no valid timestamp'],
        ["t=,v1={$good}", 'no valid timestamp'],
        ["t=-1726400000,v1={$good}", 'no valid timestamp'],
        ["t=1234567890123,v1={$good}", 'no valid timestamp'],
        ['t=' . NOW, 'no valid v1'],
        ['t=' . NOW . ',v1=', 'no valid v1'],
        ['t=' . NOW . ',v1=' . substr($good, 0, 63), 'no valid v1'],
        ['t=' . NOW . ',v1=' . $good . 'a', 'no valid v1'],
        ['t=' . NOW . ',v1=' . str_repeat('z', 64), 'no valid v1'],
        ['t=' . NOW . ',v2=' . $good, 'no valid v1'],
        ['t=' . NOW . ',v1=' . $good . ',' . str_repeat('x', 1000), 'longer than 1000'],
    ];
    foreach ($cases as [$header, $message]) {
        throws(SignatureVerificationException::class, fn () => Webhook::constructEvent($payload, $header, WEBHOOK_SECRET, 300, NOW), $message);
    }
});

test('a correctly signed body must be a JSON event object', function (): void {
    foreach (['not json', '[1,2,3]', '{"object":"payment","id":"pay_1"}', '"event"'] as $payload) {
        throws(SignatureVerificationException::class, fn () => Webhook::constructEvent($payload, sign($payload, WEBHOOK_SECRET, NOW), WEBHOOK_SECRET, 300, NOW), 'correctly signed');
    }
});

test('a secret that is not whsec_ or a bad tolerance is a programming error', function (): void {
    $payload = eventPayload();
    throws(InvalidArgumentException::class, fn () => Webhook::constructEvent($payload, sign($payload, TEST_KEY, NOW), TEST_KEY, 300, NOW), 'whsec_');
    throws(InvalidArgumentException::class, fn () => Webhook::constructEvent($payload, sign($payload, '', NOW), '', 300, NOW), 'whsec_');
    throws(InvalidArgumentException::class, fn () => Webhook::constructEvent($payload, sign($payload, WEBHOOK_SECRET, NOW), WEBHOOK_SECRET, 0, NOW), 'toleranceSeconds');
});

// ---------------------------------------------------------------------------
section('CurlTransport over a local socket (php -S on 127.0.0.1)');
// ---------------------------------------------------------------------------

function freePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        throw new RuntimeException("Could not find a free port: {$errstr}");
    }
    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr($name, (int) strrpos($name, ':') + 1);
}

/** @return array{0: resource, 1: int}|null */
function startLocalServer(): ?array
{
    $port = freePort();
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fixtures/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        return null;
    }
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline) {
        $connection = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.2);
        if ($connection !== false) {
            fclose($connection);

            return [$process, $port];
        }
        usleep(50_000);
    }
    proc_terminate($process);

    return null;
}

if (!extension_loaded('curl')) {
    skip('CurlTransport tests', 'ext-curl is not loaded');
} elseif (!function_exists('proc_open')) {
    skip('CurlTransport tests', 'proc_open is disabled');
} else {
    $server = startLocalServer();
    test('the local php -S server started', function () use ($server): void {
        check($server !== null, 'php -S did not start within 10 seconds');
    });

    if ($server !== null) {
        [$process, $port] = $server;
        $base = "http://127.0.0.1:{$port}";

        test('CurlTransport returns status, lowercased headers and body', function () use ($base): void {
            $response = (new CurlTransport())->send('GET', "{$base}/echo?x=1", ['Accept' => 'application/json', 'Authorization' => 'Bearer abc'], null, 5.0, 2.0);
            same(200, $response->status, 'status');
            same('Hello', $response->headers['x-custom-header'] ?? null, 'lowercased custom header');
            check(str_contains($response->headers['content-type'] ?? '', 'application/json'), 'content-type header');
            foreach (array_keys($response->headers) as $name) {
                same(strtolower($name), $name, 'header name is lowercase');
            }
            $echo = json_decode($response->body, true);
            same('GET', $echo['method'], 'method seen by the server');
            same('/echo?x=1', $echo['uri'], 'uri seen by the server');
            same('Bearer abc', $echo['authorization'], 'Authorization seen by the server');
        });

        test('CurlTransport sends a POST body', function () use ($base): void {
            $response = (new CurlTransport())->send('POST', "{$base}/echo", ['Content-Type' => 'application/json'], '{"a":1}', 5.0, 2.0);
            $echo = json_decode($response->body, true);
            same('POST', $echo['method'], 'method');
            same('{"a":1}', $echo['body'], 'body');
            same('application/json', $echo['contentType'], 'content type');
        });

        test('CurlTransport does not follow redirects', function () use ($base): void {
            $response = (new CurlTransport())->send('GET', "{$base}/redirect", [], null, 5.0, 2.0);
            same(302, $response->status, 'the 302 itself comes back');
            same("{$base}/followed", $response->headers['location'] ?? null, 'location header');
            same('{"redirected":true}', $response->body, 'body of the 302');
        });

        test('the Client works end to end over CurlTransport', function () use ($base): void {
            $client = new Client(TEST_KEY, ['apiBase' => $base, 'maxRetries' => 0, 'timeout' => 5]);
            $echo = $client->checkouts->create(['amountCents' => 1000, 'description' => 'Local'], ['idempotencyKey' => 'local-test-0001']);
            same('POST', $echo['method'], 'method');
            same('/v1/checkouts', $echo['uri'], 'uri');
            same('Bearer ' . TEST_KEY, $echo['authorization'], 'Authorization');
            same('local-test-0001', $echo['idempotencyKey'], 'Idempotency-Key');
            same('application/json', $echo['contentType'], 'Content-Type');
            same('payments-lk-php/' . Client::VERSION . ' PHP/' . PHP_VERSION, $echo['userAgent'], 'User-Agent');
            same(['amountCents' => 1000, 'description' => 'Local'], json_decode($echo['body'], true), 'body');

            $e = throws(ValidationException::class, fn () => $client->payments->retrieve('error'), 'below the minimum');
            same(422, $e->getHttpStatus(), 'status');
            same('VALIDATION_FAILED', $e->getErrorCode(), 'code');
            same('tr_local', $e->getTraceId(), 'trace id');
            same([['path' => 'amountCents', 'message' => 'At least 1000']], $e->getFields(), 'fields');
        });

        test('CurlTransport raises ConnectionException when nothing is listening', function (): void {
            $closed = freePort();
            throws(ConnectionException::class, fn () => (new CurlTransport())->send('GET', "http://127.0.0.1:{$closed}/", [], null, 2.0, 1.0), 'curl error');
        });

        test('CurlTransport raises ConnectionException on a timeout', function () use ($base): void {
            $started = microtime(true);
            throws(ConnectionException::class, fn () => (new CurlTransport())->send('GET', "{$base}/slow", [], null, 0.3, 1.0), 'curl error 28');
            check(microtime(true) - $started < 1.4, 'gave up before the server answered');
        });

        proc_terminate($process);
        proc_close($process);
    }
}

// ---------------------------------------------------------------------------

$failed = count(Suite::$failures);
fwrite(STDOUT, sprintf(
    "\nPHP %s: %d tests, %d assertions, %d failed, %d skipped\n",
    PHP_VERSION,
    Suite::$tests,
    Suite::$assertions,
    $failed,
    Suite::$skipped,
));
if ($failed > 0) {
    fwrite(STDOUT, 'Failed: ' . implode('; ', Suite::$failures) . "\n");
}
exit($failed === 0 ? 0 : 1);
