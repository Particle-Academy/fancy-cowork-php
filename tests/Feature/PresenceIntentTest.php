<?php

declare(strict_types=1);

use FancyCowork\Tests\Support\CaptureBroadcaster;

/*
 * Server-routed presence: cursors and field activity posted to the intent
 * endpoint, for socket servers that do not stamp a client event's sender
 * (Sockudo, including Genie's WebSockets service). The server stamps the
 * authenticated sender and broadcasts to everyone but the sender's socket.
 */

beforeEach(function (): void {
    coworkApp();
});

it('broadcasts a posted cursor with the sender stamped from identify, never from the body', function (): void {
    bindHost();
    $id = liveSession();
    CaptureBroadcaster::$sent = [];

    $response = http('POST', "/cowork/sessions/{$id}/intents", [
        'intent' => ['kind' => 'cursor', 'route' => '/doc', 'anchor' => 'title', 'fx' => 0.5, 'fy' => 0.25],
        'from' => 'a',
    ], 'b', ['X-Socket-ID' => '7.8']);

    expect($response['status'])->toBe(202);
    expect(CaptureBroadcaster::$sent)->toHaveCount(1);
    $sent = CaptureBroadcaster::$sent[0];
    expect($sent['channels'])->toBe(["presence-cowork.session.{$id}"])
        ->and($sent['event'])->toBe('cowork.intent')
        ->and($sent['payload']['from'])->toBe('b')
        ->and($sent['payload']['socket'])->toBe('7.8')
        ->and($sent['payload']['intent'])->toBe(['kind' => 'cursor', 'route' => '/doc', 'anchor' => 'title', 'fx' => 0.5, 'fy' => 0.25]);
});

it('broadcasts field activity, which carries a handle and never a value', function (): void {
    bindHost(config: ['consent' => 'none', 'control' => ['mode' => 'free'], 'limits' => ['mask' => ['card.*']]]);
    $id = liveSession();
    CaptureBroadcaster::$sent = [];

    $response = http('POST', "/cowork/sessions/{$id}/intents", ['intent' => ['kind' => 'activity', 'handle' => 'card.number', 'active' => true]], 'a');

    expect($response['status'])->toBe(202);
    expect(CaptureBroadcaster::$sent[0]['payload']['intent'])->toBe(['kind' => 'activity', 'handle' => 'card.number', 'active' => true]);
});

it('refuses the 11th cursor in a second with 429, and broadcasts none of the refused', function (): void {
    bindHost();
    $id = liveSession();
    CaptureBroadcaster::$sent = [];
    $cursor = ['intent' => ['kind' => 'cursor', 'route' => '/', 'x' => 1, 'y' => 1]];

    for ($i = 0; $i < 10; $i++) {
        expect(http('POST', "/cowork/sessions/{$id}/intents", $cursor, 'a')['status'])->toBe(202);
        tick(1);
    }
    $eleventh = http('POST', "/cowork/sessions/{$id}/intents", $cursor, 'a');

    expect($eleventh['status'])->toBe(429)->and($eleventh['body']['reason'])->toBe('cowork.denied.rate');
    expect(CaptureBroadcaster::$sent)->toHaveCount(10);
});

it('never spends the intent budget on cursors', function (): void {
    bindHost();
    $id = liveSession();
    $cursor = ['intent' => ['kind' => 'cursor', 'route' => '/', 'x' => 1, 'y' => 1]];
    for ($i = 0; $i < 10; $i++) {
        http('POST', "/cowork/sessions/{$id}/intents", $cursor, 'a');
        tick(1);
    }

    // The cursor bucket is full; the ordinary budget is untouched, so ten intents still land.
    for ($i = 0; $i < 10; $i++) {
        $scroll = http('POST', "/cowork/sessions/{$id}/intents", ['intent' => ['kind' => 'scroll', 'y' => $i], 'view' => 'a', 'revision' => 0], 'a');
        expect($scroll['status'])->toBe(202);
        tick(1);
    }
});

it('honours the host cursor rate', function (): void {
    bindHost(config: ['consent' => 'none', 'control' => ['mode' => 'free'], 'rate' => ['cursorsPerSecond' => 2]]);
    $id = liveSession();
    $cursor = ['intent' => ['kind' => 'cursor', 'route' => '/', 'x' => 1, 'y' => 1]];

    expect(http('POST', "/cowork/sessions/{$id}/intents", $cursor, 'a')['status'])->toBe(202);
    expect(http('POST', "/cowork/sessions/{$id}/intents", $cursor, 'a')['status'])->toBe(202);
    expect(http('POST', "/cowork/sessions/{$id}/intents", $cursor, 'a')['status'])->toBe(429);
});

it('refuses a malformed cursor with 422 before anything else', function (): void {
    bindHost();
    $id = liveSession();

    $response = http('POST', "/cowork/sessions/{$id}/intents", ['intent' => ['kind' => 'cursor', 'route' => 'doc', 'x' => 1, 'y' => 1]], 'a');

    expect($response['status'])->toBe(422)->and($response['body']['reason'])->toBe('cowork.invalid.intent');
});
