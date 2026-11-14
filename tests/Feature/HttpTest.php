<?php

declare(strict_types=1);

use FancyCowork\Contracts\CoworkEvent;
use FancyCowork\Events\ControlChanged;
use FancyCowork\Events\IntentRelayed;
use FancyCowork\Events\PeerJoined;
use FancyCowork\Events\SessionEnded;
use FancyCowork\Events\SessionRequested;
use FancyCowork\Tests\Support\CaptureBroadcaster;

beforeEach(function (): void {
    coworkApp();
    $this->events = [];
    app('events')->listen(CoworkEvent::class, function (CoworkEvent $event): void {
        $this->events[] = $event;
    });
});

it('starts a live session, stores opaque refs only, and re-syncs it', function (): void {
    bindHost();
    $id = liveSession();

    $sync = http('GET', "/cowork/sessions/{$id}", null, 'b');
    expect($sync['status'])->toBe(200)
        ->and($sync['body']['session']['state'])->toBe('live')
        ->and(array_column($sync['body']['session']['participants'], 'ref'))->toBe(['a', 'b'])
        ->and($sync['headers']->get('Cache-Control'))->toContain('no-store');

    $rows = app('db')->table('cowork_participants')->where('session_id', $id)->get();
    expect($rows->pluck('ref')->all())->toBe(['a', 'b']);
});

it('refuses a re-sync to someone members() does not admit', function (): void {
    bindHost(['a', 'b']);
    $id = liveSession();
    expect(http('GET', "/cowork/sessions/{$id}", null, 'z')['body'])->toBe(['reason' => 'cowork.denied.membership']);
    expect(http('GET', '/cowork/sessions/nope', null, 'a')['status'])->toBe(404);
});

it('relays an allowed intent to everyone else on the presence channel, excluding the sender socket', function (): void {
    bindHost();
    $id = liveSession();
    CaptureBroadcaster::$sent = [];

    $response = http('POST', "/cowork/sessions/{$id}/intents", [
        'intent' => ['kind' => 'field', 'handle' => 'name', 'value' => 'Ada'], 'view' => 'a', 'revision' => 0,
    ], 'a', ['X-Socket-ID' => '123.456']);

    expect($response['status'])->toBe(202)->and($response['body'])->toHaveKeys(['id', 'revision']);
    expect(CaptureBroadcaster::$sent)->toHaveCount(1);
    $sent = CaptureBroadcaster::$sent[0];
    expect($sent['channels'])->toBe(["presence-cowork.session.{$id}"])
        ->and($sent['event'])->toBe('cowork.intent')
        ->and($sent['payload']['socket'])->toBe('123.456')
        ->and($sent['payload']['from'])->toBe('a')
        ->and($sent['payload']['intent'])->toBe(['kind' => 'field', 'handle' => 'name', 'value' => 'Ada']);
});

it('stamps the sender from identify and never from the body', function (): void {
    bindHost();
    $id = liveSession();
    CaptureBroadcaster::$sent = [];
    http('POST', "/cowork/sessions/{$id}/intents", ['intent' => ['kind' => 'scroll', 'y' => 1], 'view' => 'b', 'revision' => 0, 'from' => 'a'], 'b');
    expect(CaptureBroadcaster::$sent[0]['payload']['from'])->toBe('b');
});

it('returns the pipeline refusals with their status and reason', function (): void {
    bindHost(config: ['consent' => 'none', 'control' => ['mode' => 'free'], 'limits' => ['mask' => ['card.*']]]);
    $id = liveSession();
    $post = fn (array $body, string $who = 'a') => http('POST', "/cowork/sessions/{$id}/intents", $body, $who);

    $big = $post(['intent' => ['kind' => 'field', 'handle' => 'h', 'value' => str_repeat('x', 9000)], 'view' => 'a', 'revision' => 0]);
    expect([$big['status'], $big['body']])->toBe([413, ['reason' => 'cowork.denied.size']]);

    $masked = $post(['intent' => ['kind' => 'field', 'handle' => 'card.number', 'value' => '4242'], 'view' => 'a', 'revision' => 0]);
    expect([$masked['status'], $masked['body']])->toBe([403, ['reason' => 'cowork.denied.masked']]);

    $origin = $post(['intent' => ['kind' => 'visit', 'url' => 'https://evil.example/'], 'view' => 'a', 'revision' => 0]);
    expect([$origin['status'], $origin['body']])->toBe([422, ['reason' => 'cowork.denied.origin']]);

    $invalid = $post(['intent' => ['kind' => 'teleport'], 'view' => 'a', 'revision' => 0]);
    expect([$invalid['status'], $invalid['body']])->toBe([422, ['reason' => 'cowork.invalid.intent']]);

    for ($i = 0; $i < 10; $i++) {
        expect($post(['intent' => ['kind' => 'scroll', 'y' => $i], 'view' => 'a', 'revision' => 0])['status'])->toBe(202);
    }
    $rate = $post(['intent' => ['kind' => 'scroll', 'y' => 11], 'view' => 'a', 'revision' => 0]);
    expect([$rate['status'], $rate['body']])->toBe([429, ['reason' => 'cowork.denied.rate']]);
});

it('returns blockedUntil for a ranked control refusal', function (): void {
    bindHost(config: ['consent' => 'none', 'control' => ['mode' => 'ranked', 'rank' => fn (string $ref): int => $ref === 'a' ? 10 : 1, 'idleMs' => 3000]]);
    $id = liveSession();
    expect(http('POST', "/cowork/sessions/{$id}/intents", ['intent' => ['kind' => 'field', 'handle' => 'h', 'value' => 1], 'view' => 'b', 'revision' => 0], 'a')['status'])->toBe(202);
    tick(1000);
    $blocked = http('POST', "/cowork/sessions/{$id}/intents", ['intent' => ['kind' => 'field', 'handle' => 'h', 'value' => 2], 'view' => 'b', 'revision' => 0], 'b');
    expect($blocked['status'])->toBe(409)
        ->and($blocked['body'])->toBe(['reason' => 'cowork.denied.control', 'blockedUntil' => 1_003_000]);
});

it('keeps baton control across requests, because control state is stored', function (): void {
    bindHost(config: ['consent' => 'none', 'control' => ['mode' => 'baton']]);
    $id = liveSession();
    $post = fn (array $body, string $who) => http('POST', "/cowork/sessions/{$id}/intents", $body, $who);
    expect($post(['intent' => ['kind' => 'field', 'handle' => 'h', 'value' => 1], 'view' => 'b', 'revision' => 0], 'a')['status'])->toBe(409);
    tick(10);
    expect($post(['intent' => ['kind' => 'control', 'op' => 'grant', 'target' => 'a'], 'view' => 'b'], 'b')['status'])->toBe(202);
    tick(10);
    expect($post(['intent' => ['kind' => 'field', 'handle' => 'h', 'value' => 1], 'view' => 'b', 'revision' => 0], 'a')['status'])->toBe(202);
    expect(collect($this->events)->first(fn ($e) => $e instanceof ControlChanged)?->holder)->toBe('a');
});

it('carries actingFor on every event when identify supplies it', function (): void {
    bindHost();
    $started = http('POST', '/cowork/sessions', ['mode' => 'request', 'route' => '/'], 'a', ['X-Acting-For' => 'u-42']);
    $id = $started['body']['session']['id'];
    http('POST', "/cowork/sessions/{$id}/activate", [], 'b');
    http('POST', "/cowork/sessions/{$id}/intents", ['intent' => ['kind' => 'scroll', 'y' => 1], 'view' => 'a', 'revision' => 0], 'a', ['X-Acting-For' => 'u-42']);
    http('POST', "/cowork/sessions/{$id}/end", [], 'a', ['X-Acting-For' => 'u-42']);

    $byA = collect($this->events)->filter(fn (CoworkEvent $e) => $e->ref === 'a');
    expect($byA->map(fn ($e) => $e::class)->values()->all())
        ->toBe([SessionRequested::class, PeerJoined::class, IntentRelayed::class, SessionEnded::class]);
    expect($byA->every(fn (CoworkEvent $e) => $e->actingFor === 'u-42'))->toBeTrue();
    expect(collect($this->events)->filter(fn (CoworkEvent $e) => $e->ref === 'b')->every(fn (CoworkEvent $e) => $e->actingFor === null))->toBeTrue();
    expect(app('db')->table('cowork_participants')->where('ref', 'a')->value('acting_for'))->toBe('u-42');
});

it('never puts an intent value or payload in an audit event', function (): void {
    bindHost();
    $id = liveSession();
    http('POST', "/cowork/sessions/{$id}/intents", ['intent' => ['kind' => 'field', 'handle' => 'h', 'value' => 'secret-value'], 'view' => 'a', 'revision' => 0], 'a');
    $relayed = collect($this->events)->first(fn ($e) => $e instanceof IntentRelayed);
    expect(json_encode($relayed->toArray()))->not->toContain('secret-value');
});

it('blocks joins until the subject consents when consent is accept', function (): void {
    bindHost(config: ['consent' => 'accept', 'control' => ['mode' => 'free']]);
    $id = http('POST', '/cowork/sessions', ['mode' => 'start'], 'a')['body']['session']['id'];
    $join = http('POST', "/cowork/sessions/{$id}/join", [], 'b');
    expect([$join['status'], $join['body']])->toBe([403, ['reason' => 'cowork.consent.pending']]);
    expect(http('POST', "/cowork/sessions/{$id}/consent", [], 'b')['body'])->toBe(['reason' => 'cowork.consent.not-subject']);
    expect(http('POST', "/cowork/sessions/{$id}/consent", [], 'a')['status'])->toBe(200);
    expect(http('POST', "/cowork/sessions/{$id}/join", [], 'b')['status'])->toBe(200);
});

it('admits at once when consent is none', function (): void {
    bindHost(config: ['consent' => 'none']);
    $id = http('POST', '/cowork/sessions', ['mode' => 'start'], 'a')['body']['session']['id'];
    expect(http('POST', "/cowork/sessions/{$id}/join", [], 'b')['status'])->toBe(200);
});

it('refuses everything on an ended session with 410', function (): void {
    bindHost();
    $id = liveSession();
    expect(http('POST', "/cowork/sessions/{$id}/end", [], 'a')['status'])->toBe(200);
    foreach (['join' => 'c', 'consent' => 'a', 'end' => 'a'] as $op => $who) {
        expect(http('POST', "/cowork/sessions/{$id}/{$op}", [], $who)['status'])->toBe(410);
    }
    $intent = http('POST', "/cowork/sessions/{$id}/intents", ['intent' => ['kind' => 'scroll', 'y' => 1], 'view' => 'a', 'revision' => 0], 'a');
    expect([$intent['status'], $intent['body']])->toBe([410, ['reason' => 'cowork.session.ended']]);
});

it('rate limits starts to five a minute per starter', function (): void {
    bindHost();
    for ($i = 0; $i < 5; $i++) {
        expect(http('POST', '/cowork/sessions', ['mode' => 'start'], 'a')['status'])->toBe(201);
    }
    $sixth = http('POST', '/cowork/sessions', ['mode' => 'start'], 'a');
    expect([$sixth['status'], $sixth['body']])->toBe([429, ['reason' => 'cowork.denied.rate']]);
    expect(http('POST', '/cowork/sessions', ['mode' => 'start'], 'b')['status'])->toBe(201);
    tick(60_001);
    expect(http('POST', '/cowork/sessions', ['mode' => 'start'], 'a')['status'])->toBe(201);
});

it('asks start or request as the ability for a new session', function (): void {
    bindHost();
    $asked = [];
    cowork()->authorize(function ($p, string $ability) use (&$asked): bool {
        $asked[] = $ability;

        return $ability === 'request';
    });
    expect(http('POST', '/cowork/sessions', ['mode' => 'start'], 'a')['body'])->toBe(['reason' => 'cowork.denied.authorize']);
    expect(http('POST', '/cowork/sessions', ['mode' => 'request'], 'a')['status'])->toBe(201);
    expect(http('POST', '/cowork/sessions', ['mode' => 'teleport'], 'a')['status'])->toBe(422);
    expect($asked)->toBe(['start', 'request']);
});

it('follows a peer and stops following', function (): void {
    bindHost();
    $id = liveSession();
    $follow = http('POST', "/cowork/sessions/{$id}/follow", ['target' => 'a'], 'b');
    expect($follow['status'])->toBe(200);
    $b = collect($follow['body']['session']['participants'])->firstWhere('ref', 'b');
    expect($b['follows'])->toBe('a');
    $stop = http('POST', "/cowork/sessions/{$id}/follow", ['target' => null], 'b');
    expect(collect($stop['body']['session']['participants'])->firstWhere('ref', 'b')['follows'])->toBeNull();
});

it('records a leave and lets the same ref join again as a new row', function (): void {
    bindHost();
    $id = liveSession();
    expect(http('POST', "/cowork/sessions/{$id}/leave", [], 'b')['status'])->toBe(200);
    expect(http('POST', "/cowork/sessions/{$id}/join", [], 'b')['status'])->toBe(200);
    $rows = app('db')->table('cowork_participants')->where('session_id', $id)->where('ref', 'b')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->left_at)->not->toBeNull()
        ->and($rows[1]->left_at)->toBeNull();
});

it('runs host middleware, by class name too, in the order the host gives', function (): void {
    bindHost();
    cowork()->middleware(fn (array $defaults): array => [\FancyCowork\Tests\Support\RefuseAppMiddleware::class, ...$defaults]);
    $id = liveSession();
    $app = http('POST', "/cowork/sessions/{$id}/intents", ['intent' => ['kind' => 'app', 'topic' => 'note', 'payload' => 1]], 'a');
    expect([$app['status'], $app['body']])->toBe([403, ['reason' => 'host.app-closed']]);
    expect(http('POST', "/cowork/sessions/{$id}/intents", ['intent' => ['kind' => 'scroll', 'y' => 1], 'view' => 'a', 'revision' => 0], 'a')['status'])->toBe(202);
});

it('fails fast on an unknown middleware name', function (): void {
    bindHost();
    expect(fn () => cowork()->middleware(['membership', 'allowEverything']))->toThrow(InvalidArgumentException::class);
});
