<?php

declare(strict_types=1);

use FancyCowork\Broadcasting\PusherBroadcaster;
use FancyCowork\Broadcasting\PusherHttpClient;
use FancyCowork\Broadcasting\PusherProtocol;
use FancyCowork\PusherSignature;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;

/*
 * fancy-cowork publishes to ANY Pusher-protocol server (Genie's WebSockets,
 * Sockudo, Reverb, Pusher) with its own small client over Laravel's HTTP client:
 * no pusher/pusher-php-server, no laravel/reverb. Hosts point their existing
 * Pusher-style connection at the `fancy-pusher` driver.
 */

/** A broadcasting connection the way a host's config/broadcasting.php has it. */
function pusherConnection(array $options = []): array
{
    return [
        'driver' => 'fancy-pusher',
        'key' => 'app-key',
        'secret' => 'app-secret',
        'app_id' => 'app-id',
        'options' => ['host' => '127.0.0.1', 'port' => 6001, 'scheme' => 'http', ...$options],
    ];
}

/** @return array{0: HttpFactory, 1: list<ClientRequest>} */
function fakeHttp(int $status = 200): array
{
    $http = new HttpFactory;
    $http->fake(['*' => $http->response('{}', $status)]);
    app()->instance(HttpFactory::class, $http);

    return [$http, []];
}

/** @return list<ClientRequest> */
function sentRequests(HttpFactory $http): array
{
    return $http->recorded()->map(fn (array $pair): ClientRequest => $pair[0])->values()->all();
}

it('publishes an event as one HMAC-signed POST to /apps/{id}/events', function (): void {
    coworkApp(['broadcasting.default' => 'pusherish', 'broadcasting.connections.pusherish' => pusherConnection()]);
    [$http] = fakeHttp();

    app(BroadcastManager::class)->connection()->broadcast(['presence-cowork.session.s1'], 'cowork.intent', ['from' => 'a', 'socket' => '123.456']);

    $requests = sentRequests($http);
    expect($requests)->toHaveCount(1);
    $request = $requests[0];
    $url = parse_url($request->url());
    parse_str($url['query'], $query);
    expect($request->method())->toBe('POST')
        ->and("{$url['scheme']}://{$url['host']}:{$url['port']}{$url['path']}")->toBe('http://127.0.0.1:6001/apps/app-id/events')
        ->and($request->header('Content-Type'))->toBe(['application/json']);

    $body = $request->body();
    expect(json_decode($body, true))->toBe([
        'name' => 'cowork.intent',
        'channels' => ['presence-cowork.session.s1'],
        'data' => '{"from":"a"}',
        'socket_id' => '123.456',
    ]);
    // The server's check, reproduced: the signature covers this exact body.
    $expected = PusherProtocol::eventQuery('app-key', 'app-secret', '/apps/app-id/events', $body, (int) $query['auth_timestamp']);
    expect($query)->toBe($expected);
});

it('uses https and the default port when the connection says TLS', function (): void {
    coworkApp(['broadcasting.default' => 'pusherish', 'broadcasting.connections.pusherish' => pusherConnection(['scheme' => 'https', 'port' => 443, 'useTLS' => true, 'host' => 'ws.example.com'])]);
    [$http] = fakeHttp();

    app(BroadcastManager::class)->connection()->broadcast(['private-cowork.participant.a'], 'cowork.activated', ['session' => 's1']);

    // 443 is https' default, so the client's normalised URL leaves it out.
    expect(sentRequests($http)[0]->url())->toStartWith('https://ws.example.com/apps/app-id/events?');
});

it('refuses, with no request, a payload over the protocol limit of 10 KB', function (): void {
    coworkApp(['broadcasting.default' => 'pusherish', 'broadcasting.connections.pusherish' => pusherConnection()]);
    [$http] = fakeHttp();

    expect(fn () => app(BroadcastManager::class)->connection()->broadcast(['presence-x'], 'e', ['v' => str_repeat('x', 10300)]))
        ->toThrow(BroadcastException::class);
    expect(sentRequests($http))->toBe([]);
});

it('sends at most 100 channels per request, as the protocol allows', function (): void {
    coworkApp(['broadcasting.default' => 'pusherish', 'broadcasting.connections.pusherish' => pusherConnection()]);
    [$http] = fakeHttp();
    $channels = array_map(fn (int $i): string => "private-c{$i}", range(1, 150));

    app(BroadcastManager::class)->connection()->broadcast($channels, 'e', ['n' => 1]);

    $counts = array_map(fn (ClientRequest $r): int => count(json_decode($r->body(), true)['channels']), sentRequests($http));
    expect($counts)->toBe([100, 50]);
});

it('drops a malformed socket id instead of passing it on', function (): void {
    coworkApp(['broadcasting.default' => 'pusherish', 'broadcasting.connections.pusherish' => pusherConnection()]);
    [$http] = fakeHttp();

    app(BroadcastManager::class)->connection()->broadcast(['presence-x'], 'e', ['n' => 1, 'socket' => 'not a socket']);

    expect(json_decode(sentRequests($http)[0]->body(), true))->not->toHaveKey('socket_id');
});

it('raises a BroadcastException on a non-2xx answer, without the secret in it', function (): void {
    coworkApp(['broadcasting.default' => 'pusherish', 'broadcasting.connections.pusherish' => pusherConnection()]);
    fakeHttp(401);

    try {
        app(BroadcastManager::class)->connection()->broadcast(['presence-x'], 'e', ['n' => 1]);
        $this->fail('expected a BroadcastException');
    } catch (BroadcastException $e) {
        expect($e->getMessage())->toContain('401')->not->toContain('app-secret');
    }
});

it('reuses ONE handler for every publish, so connections and TLS sessions stay warm', function (): void {
    coworkApp();
    $built = 0;
    $calls = [];
    $handler = function ($request, array $options) use (&$calls) {
        $calls[] = (string) $request->getUri()->getPath();

        return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(200, [], '{}'));
    };
    $client = PusherHttpClient::fromConfig(fn () => new HttpFactory, pusherConnection(), function () use (&$built, $handler) {
        $built++;

        return $handler;
    });

    $client->trigger(['presence-x'], 'one', ['n' => 1]);
    $client->trigger(['presence-x'], 'two', ['n' => 2]);
    $client->trigger(array_map(fn (int $i): string => "private-c{$i}", range(1, 150)), 'three', ['n' => 3]);

    // Four requests (the last one is two chunks), ONE handler built for all of them.
    expect($calls)->toBe(array_fill(0, 4, '/apps/app-id/events'))
        ->and($built)->toBe(1);
});

it('still lets Http::fake intercept while the handler is reused', function (): void {
    coworkApp(['broadcasting.default' => 'pusherish', 'broadcasting.connections.pusherish' => pusherConnection()]);
    [$http] = fakeHttp();
    $broadcaster = app(BroadcastManager::class)->connection();

    $broadcaster->broadcast(['presence-x'], 'one', ['n' => 1]);
    $broadcaster->broadcast(['presence-x'], 'two', ['n' => 2]);

    expect(array_map(fn (ClientRequest $r): string => json_decode($r->body(), true)['name'], sentRequests($http)))->toBe(['one', 'two']);
});

it('names a missing setting without echoing any value', function (): void {
    expect(fn () => PusherHttpClient::fromConfig(fn () => new HttpFactory, ['key' => 'k', 'app_id' => '1', 'options' => ['host' => 'h']]))
        ->toThrow(InvalidArgumentException::class, 'secret');
});

it('relays a cowork intent through the driver end to end', function (): void {
    coworkApp(['broadcasting.default' => 'pusherish', 'broadcasting.connections.pusherish' => pusherConnection()]);
    [$http] = fakeHttp();
    bindHost();
    $id = liveSession();
    $before = count(sentRequests($http));

    $response = http('POST', "/cowork/sessions/{$id}/intents", ['intent' => ['kind' => 'scroll', 'y' => 3], 'view' => 'a', 'revision' => 0], 'a', ['X-Socket-ID' => '1.2']);

    expect($response['status'])->toBe(202);
    $relayed = array_slice(sentRequests($http), $before);
    $events = array_map(fn (ClientRequest $r): array => json_decode($r->body(), true), $relayed);
    expect(array_column($events, 'name'))->toContain('cowork.intent');
    $intent = $events[array_search('cowork.intent', array_column($events, 'name'), true)];
    expect($intent['channels'])->toBe(["presence-cowork.session.{$id}"])
        ->and($intent['socket_id'])->toBe('1.2')
        ->and(json_decode($intent['data'], true)['from'])->toBe('a');
});

it('answers Laravel channel auth with the protocol signature', function (): void {
    coworkApp(['broadcasting.default' => 'pusherish', 'broadcasting.connections.pusherish' => pusherConnection()]);
    $broadcaster = app(BroadcastManager::class)->connection();
    expect($broadcaster)->toBeInstanceOf(PusherBroadcaster::class);

    $private = Request::create('/broadcasting/auth', 'POST', ['channel_name' => 'private-cowork.participant.a', 'socket_id' => '1.2']);
    expect($broadcaster->validAuthenticationResponse($private, true))->toBe(PusherSignature::sign('app-key', 'app-secret', '1.2', 'private-cowork.participant.a'));

    $presence = Request::create('/broadcasting/auth', 'POST', ['channel_name' => 'presence-cowork.session.s1', 'socket_id' => '1.2']);
    $answer = $broadcaster->validAuthenticationResponse($presence, ['id' => 'a', 'ref' => 'a', 'label' => 'A']);
    $channelData = json_encode(['user_id' => 'a', 'user_info' => ['id' => 'a', 'ref' => 'a', 'label' => 'A']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    expect($answer)->toBe(PusherSignature::sign('app-key', 'app-secret', '1.2', 'presence-cowork.session.s1', $channelData));
});

/*
 * Genie's WebSockets service rate-limits its events API (31 of 164 sends refused
 * in a fast burst) and reads a publish body as Latin-1. So the client retries a
 * 429 after Retry-After, bounded, and sends ASCII-only JSON.
 */

/** A client over a faked factory, with a sleeper that records instead of waiting. */
function retryingClient(HttpFactory $http, array &$slept): PusherHttpClient
{
    return new PusherHttpClient(
        fn (): HttpFactory => $http,
        'app-id', 'app-key', 'app-secret', 'http://127.0.0.1:6001',
        sleep: function (int $ms) use (&$slept): void {
            $slept[] = $ms;
        },
    );
}

it('retries a 429 after Retry-After seconds and then succeeds', function (): void {
    coworkApp();
    $http = new HttpFactory;
    $http->fakeSequence()->push('{}', 429, ['Retry-After' => '1'])->push('{}', 200);
    $slept = [];

    retryingClient($http, $slept)->trigger(['presence-x'], 'e', ['n' => 1]);

    expect($slept)->toBe([1000])->and($http->recorded())->toHaveCount(2);
});

it('reads an HTTP-date Retry-After', function (): void {
    coworkApp();
    $http = new HttpFactory;
    $http->fakeSequence()->push('{}', 429, ['Retry-After' => gmdate('D, d M Y H:i:s', time() - 5).' GMT'])->push('{}', 200);
    $slept = [];

    retryingClient($http, $slept)->trigger(['presence-x'], 'e', ['n' => 1]);

    expect($slept)->toBe([0])->and($http->recorded())->toHaveCount(2);
});

it('gives up after two retries with a BroadcastException', function (): void {
    coworkApp();
    $http = new HttpFactory;
    $http->fakeSequence()->push('{}', 429, ['Retry-After' => '0'])->push('{}', 429, ['Retry-After' => '0'])->push('{}', 429, ['Retry-After' => '0'])->push('{}', 200);
    $slept = [];

    $client = retryingClient($http, $slept); // built outside: an arrow fn captures $slept by value
    expect(fn () => $client->trigger(['presence-x'], 'e', ['n' => 1]))
        ->toThrow(BroadcastException::class, '429');
    expect($http->recorded())->toHaveCount(3);
});

it('never waits more than 3 s in total: a long Retry-After is not waited out', function (): void {
    coworkApp();
    $http = new HttpFactory;
    $http->fakeSequence()->push('{}', 429, ['Retry-After' => '2'])->push('{}', 429, ['Retry-After' => '2'])->push('{}', 200);
    $slept = [];

    $client = retryingClient($http, $slept); // built outside: an arrow fn captures $slept by value
    expect(fn () => $client->trigger(['presence-x'], 'e', ['n' => 1]))
        ->toThrow(BroadcastException::class);
    expect(array_sum($slept))->toBeLessThanOrEqual(3000)->and($slept)->toBe([2000]);
});

it('does not retry other refusals', function (): void {
    coworkApp();
    $http = new HttpFactory;
    $http->fakeSequence()->push('{}', 401)->push('{}', 200);
    $slept = [];

    $client = retryingClient($http, $slept); // built outside: an arrow fn captures $slept by value
    expect(fn () => $client->trigger(['presence-x'], 'e', ['n' => 1]))->toThrow(BroadcastException::class, '401');
    expect($slept)->toBe([])->and($http->recorded())->toHaveCount(1);
});

it('sends an ASCII-only body, with body_md5 over exactly those bytes', function (): void {
    coworkApp(['broadcasting.default' => 'pusherish', 'broadcasting.connections.pusherish' => pusherConnection()]);
    [$http] = fakeHttp();
    $text = 'caf'."\u{00E9}\u{00A0}".'ok';

    app(BroadcastManager::class)->connection()->broadcast(['presence-x'], 'e', ['text' => $text]);

    $request = sentRequests($http)[0];
    $body = $request->body();
    expect(preg_match('/[^\x00-\x7F]/', $body))->toBe(0);
    expect(json_decode(json_decode($body, true)['data'], true)['text'])->toBe($text);
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
    expect($query['body_md5'])->toBe(md5($body));
});
