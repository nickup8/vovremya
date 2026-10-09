<?php

namespace Tests\Feature\Billing;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

/**
 * Drives requests through the REAL global Guzzle retry middleware registered
 * by AppServiceProvider and delivers the failure as an ASYNC rejected promise
 * carrying a Guzzle ConnectException.
 *
 * Http::fake(Http::failedConnection()) cannot be used for that: Laravel's stub
 * handler calls $promise->wait() inside the stub (on_stats is always set),
 * which turns the rejection into a SYNCHRONOUS throw before Guzzle's
 * RetryMiddleware attaches its onRejected handler — the retry chain never
 * sees it and the attempt count silently stays 1.
 *
 * globalMiddleware() appends in registration order and the first registered
 * middleware ends up OUTERMOST (HandlerStack::resolve wraps in reverse), so a
 * middleware registered here sits directly UNDER the production retry
 * middleware: every invocation of this middleware is one HTTP attempt the
 * retry chain dispatched, and returning a rejected promise (instead of
 * throwing) lets the production decider run for real.
 */
trait MakesFailingHttpTransport
{
    /** @var list<string> Path of every attempt dispatched by the retry chain. */
    protected array $dispatchedPaths = [];

    /** @var list<string> Full URI of every attempt dispatched by the retry chain. */
    protected array $dispatchedUrls = [];

    /**
     * Reject the given paths with a transport failure on every attempt and
     * count all dispatched attempts. Every other path still passes through to
     * the Laravel stub/transport, so callers must Http::fake() them —
     * stray requests are prevented to guarantee no test ever reaches the
     * network.
     */
    protected function failTransportOnPaths(string ...$paths): void
    {
        Http::preventStrayRequests();

        Http::globalMiddleware(function (callable $handler) use ($paths) {
            return function (RequestInterface $request, array $options) use ($handler, $paths) {
                $path = $request->getUri()->getPath();
                $this->dispatchedPaths[] = $path;
                $this->dispatchedUrls[] = (string) $request->getUri();

                if (! in_array($path, $paths, true)) {
                    return $handler($request, $options);
                }

                // Async rejection: handed to Guzzle's RetryMiddleware as a
                // rejected promise, exactly like a real curl timeout.
                return Create::rejectionFor(new ConnectException(
                    'cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received '
                    .'(see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for '.$request->getUri(),
                    $request,
                ));
            };
        });
    }

    protected function attemptsFor(string $path): int
    {
        return count(array_filter($this->dispatchedPaths, fn (string $dispatched) => $dispatched === $path));
    }

    protected function attemptsForUrl(string $url): int
    {
        return count(array_filter($this->dispatchedUrls, fn (string $dispatched) => $dispatched === $url));
    }
}
