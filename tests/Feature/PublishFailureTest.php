<?php

declare(strict_types=1);

use FancyCowork\Broadcasting\BroadcastFailed;
use FancyCowork\Broadcasting\PusherHttpClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\AbstractLogger;

/*
 * Lab test 87 (cowork-laravel-v2) lost one cursor move in 20, twice, with every
 * intent answered 202. Whatever the socket service did to a publish, nobody could
 * tell: CoworkManager::publish caught the exception and dropped it with no log and
 * no signal, after the response had gone. And the client gave up on anything but a
 * 429 at once, so one 503 or one refused connection lost the event outright.
 *
 * Now a refusal the service can recover from (429, 5xx, a connection that fails) is
 * retried with back-off inside a bounded budget the app configures, and a publish
 * that still fails is LOGGED and announced as a BroadcastFailed event.
 */

/** A PSR logger that keeps what it was told. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $lines = [];

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->lines[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}

/**
 * A socket service that answers 429 until a virtual clock passes the end of its
 * window, then 200. The client's sleeps move that clock; nothing really waits.
 *
 * @return array{0: HttpFactory, 1: Closure(int): void, 2: Closure(): int}
 */
function rateLimitedFor(int $windowMs): array
{
    $now = 0;
    $http = new HttpFactory;
    $http->fake(function () use (&$now, $windowMs, $http) {
        return $now < $windowMs ? $http->response('{}', 429) : $http->response('{}', 200);
    });
    $sleep = function (int $ms) use (&$now): void {
        $now += $ms;
    };

    return [$http, $sleep, function () use (&$now): int {
        return $now;
    }];
}

/** @param  array<string, mixed>  $publish  the fancy-cowork.publish settings */
function clientWith(HttpFactory $http, Closure $sleep, array $publish = []): PusherHttpClient
{
    return PusherHttpClient::fromConfig(fn (): HttpFactory => $http, [
        'key' => 'app-key', 'secret' => 'app-secret', 'app_id' => 'app-id',
        'options' => ['host' => '127.0.0.1', 'port' => 6001, 'scheme' => 'http'],
    ], null, $publish, $sleep);
}

it('rides out a 429 window shorter than the retry budget, with no Retry-After', function (): void {
    coworkApp();
    [$http, $sleep, $clock] = rateLimitedFor(600);

    clientWith($http, $sleep)->trigger(['presence-x'], 'cowork.intent', ['n' => 1]);

    // Back-off 250, then 500: delivered once the window closed, well inside 3 s.
    expect($clock())->toBe(750)->and($http->recorded())->toHaveCount(3);
});

it('retries a 503 and a refused connection, not only a 429', function (): void {
    coworkApp();
    $http = new HttpFactory;
    $calls = 0;
    $http->fake(function () use (&$calls, $http) {
        $calls++;
        if ($calls === 1) {
            throw new ConnectionException('cURL error 7: Failed to connect');
        }

        return $calls === 2 ? $http->response('{}', 503) : $http->response('{}', 200);
    });
    $slept = [];

    clientWith($http, function (int $ms) use (&$slept): void {
        $slept[] = $ms;
    })->trigger(['presence-x'], 'cowork.intent', ['n' => 1]);

    expect($calls)->toBe(3)->and($slept)->toBe([250, 500]);
});

it('takes its retry count and budget from fancy-cowork.publish', function (): void {
    coworkApp();
    [$http, $sleep, $clock] = rateLimitedFor(3_500);

    // The defaults (2 retries, 3 s) cannot outlast a 3.5 s window...
    expect(fn () => clientWith($http, $sleep)->trigger(['presence-x'], 'e', ['n' => 1]))
        ->toThrow(Illuminate\Broadcasting\BroadcastException::class, '429');

    // ...an app that allows more can.
    [$http, $sleep, $clock] = rateLimitedFor(3_500);
    clientWith($http, $sleep, ['retries' => 5, 'retry_budget_ms' => 10_000, 'backoff_ms' => 250])
        ->trigger(['presence-x'], 'e', ['n' => 1]);
    expect($clock())->toBeGreaterThanOrEqual(3_500)->toBeLessThanOrEqual(10_000);
});

it('never waits past the budget, whatever the service asks', function (): void {
    coworkApp();
    $http = new HttpFactory;
    $http->fake(fn () => $http->response('{}', 429, ['Retry-After' => '30']));
    $slept = [];

    $client = clientWith($http, function (int $ms) use (&$slept): void {
        $slept[] = $ms;
    }, ['retries' => 10, 'retry_budget_ms' => 2_000]);
    expect(fn () => $client->trigger(['presence-x'], 'e', ['n' => 1]))->toThrow(Illuminate\Broadcasting\BroadcastException::class);

    // A 30 s Retry-After is not waited out: it gives up rather than hold the worker.
    expect(array_sum($slept))->toBeLessThanOrEqual(2_000);
});

it('reports a broadcast it could not deliver: a log line and a BroadcastFailed event, never silence', function (): void {
    coworkApp([
        'broadcasting.default' => 'pusherish',
        'broadcasting.connections.pusherish' => ['driver' => 'fancy-pusher', 'key' => 'app-key', 'secret' => 'app-secret', 'app_id' => 'app-id',
            'options' => ['host' => '127.0.0.1', 'port' => 6001, 'scheme' => 'http']],
        // No retries, so the test never sleeps: the service refuses, and that is final.
        'fancy-cowork.publish' => ['retries' => 0],
    ]);
    $http = new HttpFactory;
    $http->fake(['*' => $http->response('{}', 200)]);
    app()->instance(HttpFactory::class, $http);
    $logger = new RecordingLogger;
    app()->instance('log', $logger);
    bindHost();
    $id = liveSession();

    // From here the socket service is rate-limiting.
    $http = new HttpFactory;
    $http->fake(['*' => $http->response('{}', 429)]);
    app()->instance(HttpFactory::class, $http);
    $failed = [];
    app('events')->listen(BroadcastFailed::class, function (BroadcastFailed $e) use (&$failed): void {
        $failed[] = $e;
    });

    $response = http('POST', "/cowork/sessions/{$id}/intents", ['intent' => ['kind' => 'cursor', 'route' => '/', 'anchor' => 'title', 'fx' => 0.25, 'fy' => 0.5], 'view' => 'a', 'revision' => 0], 'a');
    cowork()->flushBroadcasts(); // the kernel's terminate, where the publish happens

    expect($response['status'])->toBe(202);
    expect($failed)->toHaveCount(1);
    expect($failed[0]->event)->toBe('cowork.intent')
        ->and($failed[0]->channel)->toBe("presence-cowork.session.{$id}")
        ->and($failed[0]->session)->toBe($id)
        ->and($failed[0]->reason)->toContain('429');

    $warnings = array_values(array_filter($logger->lines, fn (array $l): bool => $l['level'] === 'warning'));
    expect($warnings)->toHaveCount(1);
    expect($warnings[0]['message'])->toContain('cowork.intent')->toContain('429');
    // Never the intent's value, never the secret.
    $logged = json_encode($warnings[0]);
    expect($logged)->not->toContain('0.25')->not->toContain('app-secret');
});
