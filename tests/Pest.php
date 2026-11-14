<?php

declare(strict_types=1);

use FancyCowork\CoworkManager;
use FancyCowork\Participant;
use FancyCowork\Tests\Support\TestApp;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

// Every test boots its own kernel; drop it afterwards so no container, facade
// or in-memory database leaks into the next test.
uses()->afterEach(function (): void {
    Facade::clearResolvedInstances();
    Container::setInstance(null);
})->in(__DIR__.'/Feature');

/** @param  array<string, mixed>  $config */
function coworkApp(array $config = []): Application
{
    return TestApp::boot($config);
}

function cowork(): CoworkManager
{
    return app(CoworkManager::class);
}

/** The clock every manager in a test reads; tests move it with tick(). */
final class TestClock
{
    public static int $now = 1_000_000;
}

function tick(int $ms): void
{
    TestClock::$now += $ms;
}

/**
 * A host wired the way most tests want: the request's `X-Who` header names the
 * participant (`X-Acting-For` adds the second ref), a fixed member list, an
 * authorize that allows everything, and a free, no-consent config. Nothing in
 * here is a model: refs are strings.
 *
 * @param  list<string>  $members
 * @param  array<string, mixed>  $config
 */
function bindHost(array $members = ['a', 'b', 'c'], array $config = ['consent' => 'none', 'control' => ['mode' => 'free']]): CoworkManager
{
    TestClock::$now = 1_000_000;

    return cowork()
        ->clock(fn (): int => TestClock::$now)
        ->identify(function (Request $request): ?Participant {
            $who = $request->header('X-Who');
            if (! is_string($who) || $who === '') {
                return null;
            }
            $actingFor = $request->header('X-Acting-For');

            return new Participant($who, label: strtoupper($who), actingFor: is_string($actingFor) && $actingFor !== '' ? $actingFor : null);
        })
        ->members(fn ($session, Participant $p): bool => in_array($p->ref, $members, true))
        ->authorize(fn (): bool => true)
        ->config(fn (): array => $config);
}

/**
 * One request through the real router.
 *
 * @param  array<string, mixed>|null  $body
 * @param  array<string, string>  $headers
 * @return array{status: int, body: array<string, mixed>, headers: \Symfony\Component\HttpFoundation\ResponseHeaderBag}
 */
function http(string $method, string $uri, ?array $body = null, ?string $who = null, array $headers = []): array
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if ($who !== null) {
        $server['HTTP_X_WHO'] = $who;
    }
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $request = Request::create($uri, $method, [], [], [], $server, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
    app()->instance('request', $request);
    $response = app('router')->dispatch($request);

    return [
        'status' => $response->getStatusCode(),
        'body' => json_decode((string) $response->getContent(), true) ?? [],
        'headers' => $response->headers,
    ];
}

/** Start a live session as `a`, join `b`, and return its id. */
function liveSession(): string
{
    $started = http('POST', '/cowork/sessions', ['mode' => 'start', 'route' => '/'], 'a');
    expect($started['status'])->toBe(201);
    $id = $started['body']['session']['id'];
    expect(http('POST', "/cowork/sessions/{$id}/join", ['route' => '/'], 'b')['status'])->toBe(200);

    return $id;
}
