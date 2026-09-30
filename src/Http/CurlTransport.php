<?php

declare(strict_types=1);

namespace PaymentsLk\Http;

use PaymentsLk\Exception\ConnectionException;

/**
 * The default transport. TLS peer and host verification are always on and
 * cannot be turned off from here; redirects are never followed, so the
 * Authorization header is only ever sent to the host it was built for.
 *
 * The headers carry the secret key, and an exception's stack trace keeps the
 * arguments of every call it passed through, which error reporters print. So
 * the headers are a SensitiveParameter (PHP 8.2 and later), and each method
 * empties its own variable before anything can throw, which is what a trace
 * reads on PHP 8.1.
 */
final class CurlTransport implements TransportInterface
{
    public function __construct()
    {
        if (!\extension_loaded('curl')) {
            throw new \RuntimeException('The Payments.lk SDK needs the curl extension (ext-curl).');
        }
    }

    public function send(
        string $method,
        string $url,
        #[\SensitiveParameter]
        array $headers,
        ?string $body,
        float $timeout,
        float $connectTimeout,
    ): Response {
        $given = $headers;
        $headers = [];
        $options = $this->options($method, $url, $given, $body, $timeout, $connectTimeout);
        unset($given);

        $handle = curl_init();
        if ($handle === false) {
            throw new ConnectionException('Could not start a curl handle.');
        }

        $received = [];
        $options[CURLOPT_HEADERFUNCTION] = static function ($ch, string $line) use (&$received): int {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, 'HTTP/')) {
                // A new status line (after a 100 Continue, say) starts a fresh header set.
                $received = [];
            } elseif ($trimmed !== '' && str_contains($trimmed, ':')) {
                [$name, $value] = explode(':', $trimmed, 2);
                $name = strtolower(trim($name));
                $value = trim($value);
                $received[$name] = isset($received[$name]) ? $received[$name] . ', ' . $value : $value;
            }

            return \strlen($line);
        };

        if (!curl_setopt_array($handle, $options)) {
            throw new ConnectionException('Could not configure the curl handle.');
        }

        $result = curl_exec($handle);
        if ($result === false) {
            $errno = curl_errno($handle);
            $error = curl_error($handle);
            throw new ConnectionException(sprintf(
                'Could not complete the request to %s: %s (curl error %d).',
                (string) parse_url($url, PHP_URL_HOST),
                $error !== '' ? $error : 'unknown error',
                $errno,
            ));
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return new Response($status, $received, \is_string($result) ? $result : '');
    }

    /**
     * The curl options for one request, without the header callback.
     * Public so the test suite can prove verification stays on.
     *
     * @internal
     *
     * @param array<string, string> $headers
     *
     * @return array<int, mixed>
     */
    public function options(
        string $method,
        string $url,
        #[\SensitiveParameter]
        array $headers,
        ?string $body,
        float $timeout,
        float $connectTimeout,
    ): array {
        $given = $headers;
        $headers = [];
        $lines = [];
        foreach ($given as $name => $value) {
            $name = (string) $name;
            $value = (string) $value;
            if (preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1 || preg_match('/[\r\n\0]/', $value) === 1) {
                throw new \InvalidArgumentException(sprintf('Refusing to send a malformed header "%s".', $name));
            }
            $lines[] = $name . ': ' . $value;
        }
        // No "Expect: 100-continue" round trip on bodies.
        $lines[] = 'Expect:';

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_TIMEOUT_MS => max(1, (int) round($timeout * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round($connectTimeout * 1000)),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ENCODING => '',
        ];
        if (\defined('CURLOPT_REDIR_PROTOCOLS')) {
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        return $options;
    }
}
