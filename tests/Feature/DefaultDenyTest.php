<?php

declare(strict_types=1);

use FancyCowork\Participant;

beforeEach(function (): void {
    coworkApp();
});

dataset('every route', [
    'start' => ['POST', '/cowork/sessions', ['mode' => 'start']],
    'request' => ['POST', '/cowork/sessions', ['mode' => 'request']],
    'sync' => ['GET', '/cowork/sessions/s1', null],
    'activate' => ['POST', '/cowork/sessions/s1/activate', []],
    'join' => ['POST', '/cowork/sessions/s1/join', []],
    'consent' => ['POST', '/cowork/sessions/s1/consent', []],
    'leave' => ['POST', '/cowork/sessions/s1/leave', []],
    'end' => ['POST', '/cowork/sessions/s1/end', []],
    'follow' => ['POST', '/cowork/sessions/s1/follow', ['target' => null]],
    'intent' => ['POST', '/cowork/sessions/s1/intents', ['intent' => ['kind' => 'scroll', 'y' => 1], 'view' => 'a', 'revision' => 0]],
    'channel auth' => ['POST', '/cowork/auth', ['socket_id' => '1.2', 'channel_name' => 'presence-cowork.session.s1']],
]);

it('refuses every route with the identity reason when nothing is bound', function (string $method, string $uri, ?array $body): void {
    $response = http($method, $uri, $body, 'a');
    expect($response['status'])->toBe(403)
        ->and($response['body'])->toBe(['reason' => 'cowork.unconfigured.identity']);
})->with('every route');

it('refuses a start with the next unbound contract as each one is bound', function (): void {
    $start = fn () => http('POST', '/cowork/sessions', ['mode' => 'start'], 'a')['body']['reason'] ?? null;

    cowork()->identify(fn (): Participant => new Participant('a'));
    expect($start())->toBe('cowork.unconfigured.config');

    cowork()->config(fn (): array => []);
    expect($start())->toBe('cowork.unconfigured.members');

    cowork()->members(fn (): bool => true);
    expect($start())->toBe('cowork.unconfigured.authorize');

    cowork()->authorize(fn (): bool => true);
    expect(http('POST', '/cowork/sessions', ['mode' => 'start'], 'a')['status'])->toBe(201);
});

it('treats an answer that is truthy but not true as a refusal', function (): void {
    cowork()->identify(fn (): Participant => new Participant('a'))
        ->config(fn (): array => [])
        ->members(fn (): string => 'yes')
        ->authorize(fn (): bool => true);
    expect(http('POST', '/cowork/sessions', ['mode' => 'start'], 'a')['body'])->toBe(['reason' => 'cowork.denied.membership']);

    cowork()->members(fn (): bool => true)->authorize(fn (): int => 1);
    expect(http('POST', '/cowork/sessions', ['mode' => 'start'], 'a')['body'])->toBe(['reason' => 'cowork.denied.authorize']);
});

it('answers 401 when identify is bound and names nobody', function (): void {
    cowork()->identify(fn (): ?Participant => null);
    $response = http('POST', '/cowork/sessions', ['mode' => 'start'], 'a');
    expect($response['status'])->toBe(401)->and($response['body'])->toBe(['reason' => 'cowork.unidentified']);
});

it('answers 500 and writes nothing when a host resolver throws', function (): void {
    cowork()->identify(fn (): Participant => new Participant('a'))
        ->config(fn (): array => [])
        ->members(fn (): bool => throw new RuntimeException('host database down'))
        ->authorize(fn (): bool => true);
    $response = http('POST', '/cowork/sessions', ['mode' => 'start'], 'a');
    expect($response['status'])->toBe(500)
        ->and($response['body'])->toBe(['reason' => 'cowork.host.error'])
        ->and(app('db')->table('cowork_sessions')->count())->toBe(0);
});
