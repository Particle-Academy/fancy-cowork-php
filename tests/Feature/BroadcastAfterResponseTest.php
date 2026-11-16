<?php

declare(strict_types=1);

use FancyCowork\Tests\Support\CaptureBroadcaster;
use Illuminate\Http\Request;

/*
 * A consumer's /follow and /join POSTs hung for over 10 and 20 seconds: their broadcaster
 * is synchronous and their socket server was answering 429, so each request waited on
 * its broadcast while holding a PHP worker, with only 4 workers to go round. The browser
 * meanwhile held its co-work queue on the follow POST and applied nothing.
 *
 * The row lock is never held across a broadcast (it runs after the transaction). But the
 * broadcast still ran INSIDE the request. Now it runs after the response is sent (the
 * kernel's terminate), so a slow socket server costs the broadcast, never the caller's
 * request; and a join that changes nothing (the same person again) broadcasts nothing.
 */

/** Dispatch like a server would, WITHOUT the kernel's terminate: what the caller waits for. */
function dispatchOnly(string $method, string $uri, ?array $body, string $who): int
{
    $request = Request::create($uri, $method, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_WHO' => $who], $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
    app()->instance('request', $request);

    return app('router')->dispatch($request)->getStatusCode();
}

it('answers a join BEFORE it broadcasts, and broadcasts once the response is sent', function (): void {
    coworkApp();
    bindHost(['a', 'b']);
    $started = http('POST', '/cowork/sessions', ['mode' => 'start', 'route' => '/'], 'a');
    $id = $started['body']['session']['id'];
    CaptureBroadcaster::$sent = [];

    expect(dispatchOnly('POST', "/cowork/sessions/{$id}/join", ['route' => '/'], 'b'))->toBe(200);
    expect(CaptureBroadcaster::$sent)->toBe([]); // the caller has its answer; nothing sent yet

    cowork()->flushBroadcasts(); // the kernel's terminate
    expect(array_column(CaptureBroadcaster::$sent, 'event'))->toBe(['cowork.session']);
});

it('answers a follow before it broadcasts', function (): void {
    coworkApp();
    bindHost(['a', 'b']);
    $id = liveSession();
    CaptureBroadcaster::$sent = [];

    expect(dispatchOnly('POST', "/cowork/sessions/{$id}/follow", ['target' => 'a'], 'b'))->toBe(200);
    expect(CaptureBroadcaster::$sent)->toBe([]);
    cowork()->flushBroadcasts();
    expect(CaptureBroadcaster::$sent)->not->toBe([]);
});

it('a join by someone already joined is answered and broadcasts nothing', function (): void {
    coworkApp();
    bindHost(['a', 'b']);
    $id = liveSession();
    CaptureBroadcaster::$sent = [];

    $again = http('POST', "/cowork/sessions/{$id}/join", ['route' => '/'], 'b');
    expect($again['status'])->toBe(200);
    expect($again['body']['session']['participants'])->toHaveCount(2);
    expect(array_column(CaptureBroadcaster::$sent, 'event'))->not->toContain('cowork.session');
});

it('flushing twice sends each broadcast once', function (): void {
    coworkApp();
    bindHost(['a', 'b']);
    $id = liveSession();
    CaptureBroadcaster::$sent = [];
    dispatchOnly('POST', "/cowork/sessions/{$id}/follow", ['target' => 'a'], 'b');
    cowork()->flushBroadcasts();
    cowork()->flushBroadcasts();
    expect(CaptureBroadcaster::$sent)->toHaveCount(1);
});

it('sends at once when broadcast_after_response is off', function (): void {
    coworkApp(['fancy-cowork.broadcast_after_response' => false]);
    bindHost(['a', 'b']);
    $id = liveSession();
    CaptureBroadcaster::$sent = [];
    dispatchOnly('POST', "/cowork/sessions/{$id}/follow", ['target' => 'a'], 'b');
    expect(CaptureBroadcaster::$sent)->not->toBe([]);
});
