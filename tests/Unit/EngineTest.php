<?php

declare(strict_types=1);

use FancyCowork\Engine\Config;
use FancyCowork\Engine\Engine;
use FancyCowork\Engine\EngineHost;
use FancyCowork\Engine\SessionState;

/*
 * Engine behaviour the shared fixtures do not pin, each one found by a
 * mutation in tests/sabotage.php surviving the suite.
 */

function engineHost(array $config = [], ?Closure $members = null, ?Closure $authorize = null, ?Closure $predicate = null): EngineHost
{
    return new EngineHost(
        identity: true,
        config: Config::resolve(['control' => ['mode' => 'free'], ...$config]),
        members: $members ?? fn (string $ref): bool => true,
        authorize: $authorize ?? fn (): bool => true,
        predicate: $predicate,
    );
}

function twoPeers(): array
{
    return SessionState::create('s1', 'live', 'a', [['ref' => 'a', 'route' => '/'], ['ref' => 'b', 'route' => '/']]);
}

function scrollInto(EngineHost $host, string $from, string $view, array $state = []): array
{
    return Engine::evaluateIntent($host, $state ?: twoPeers(), [
        'from' => $from, 'envelope' => ['view' => $view, 'revision' => 0], 'intent' => ['kind' => 'scroll', 'y' => 1], 'now' => 0,
    ])->decision;
}

it('admits a member only on a literal true, on intents too', function (): void {
    $host = engineHost(members: fn (): string => 'yes');
    expect(scrollInto($host, 'a', 'a'))->toBe(['allow' => false, 'reason' => 'cowork.denied.membership', 'status' => 403]);
});

it('allows an ability only on true or allow => true, on joins and intents', function (): void {
    $truthy = engineHost(authorize: fn (): int => 1);
    $state = SessionState::create('s1', 'live', 'a', [['ref' => 'a']]);
    expect(Engine::evaluateJoin($truthy, $state, ['from' => 'b', 'now' => 0])->decision['reason'])->toBe('cowork.denied.authorize');
    expect(scrollInto($truthy, 'a', 'a')['reason'])->toBe('cowork.denied.authorize');

    $shaped = engineHost(authorize: fn (): array => ['allow' => true]);
    expect(scrollInto($shaped, 'a', 'a'))->toBe(['allow' => true]);
    $reasoned = engineHost(authorize: fn (): array => ['allow' => false, 'reason' => 'host.off-hours']);
    expect(scrollInto($reasoned, 'a', 'a'))->toBe(['allow' => false, 'reason' => 'host.off-hours', 'status' => 403]);
});

it('asks view:<view> even for the sender\'s own view', function (): void {
    $asked = [];
    $host = engineHost(authorize: function (string $ref, string $ability) use (&$asked): bool {
        $asked[] = $ability;

        return $ability !== 'view:a';
    });
    expect(scrollInto($host, 'a', 'a')['reason'])->toBe('cowork.denied.authorize');
    expect($asked)->toBe(['intent:scroll', 'view:a']);
});

it('fails closed on a when naming a predicate the host never defined', function (): void {
    $deny = engineHost(['limits' => ['deny' => [['when' => 'nobodyDefinedThis']]]], predicate: fn (): ?bool => null);
    expect(scrollInto($deny, 'a', 'a')['reason'])->toBe('cowork.denied.limit');

    $allow = engineHost(['limits' => ['allow' => [['when' => 'nobodyDefinedThis']]]], predicate: fn (): ?bool => null);
    expect(scrollInto($allow, 'a', 'a')['reason'])->toBe('cowork.denied.limit');

    $noPredicates = engineHost(['limits' => ['deny' => [['when' => 'x']]]]);
    expect(scrollInto($noPredicates, 'a', 'a')['reason'])->toBe('cowork.denied.limit');
});

it('names the sender, not the view owner, on IntentRelayed', function (): void {
    // b joined acting for u-9, so its intents carry that same identity.
    $state = SessionState::create('s1', 'live', 'a', [['ref' => 'a', 'route' => '/'], ['ref' => 'b', 'route' => '/', 'actingFor' => 'u-9']]);
    $result = Engine::evaluateIntent(engineHost(), $state, [
        'from' => 'b', 'actingFor' => 'u-9', 'envelope' => ['view' => 'a', 'revision' => 0, 'id' => 'i1'],
        'intent' => ['kind' => 'field', 'handle' => 'h', 'value' => 'secret'], 'now' => 5,
    ]);
    expect($result->events)->toBe([
        ['type' => 'IntentRelayed', 'session' => 's1', 'ref' => 'b', 'actingFor' => 'u-9', 'id' => 'i1', 'kind' => 'field', 'view' => 'a', 'at' => 5],
    ]);
});

it('returns the very state it was given on a refusal, and a new one on an allow', function (): void {
    $state = twoPeers();
    $refused = Engine::evaluateIntent(engineHost(), $state, ['from' => 'z', 'envelope' => ['view' => 'a', 'revision' => 0], 'intent' => ['kind' => 'scroll', 'y' => 1], 'now' => 0]);
    expect($refused->state)->toBe($state)->and($refused->events)->toBe([]);
    $allowed = Engine::evaluateIntent(engineHost(), $state, ['from' => 'a', 'envelope' => ['view' => 'a', 'revision' => 0], 'intent' => ['kind' => 'scroll', 'y' => 1], 'now' => 0]);
    expect($allowed->state['revision'])->toBe(1)->and($state['revision'])->toBe(0);
});

it('rejects an unknown middleware name', function (): void {
    $host = new EngineHost(true, Config::resolve(), fn (): bool => true, fn (): bool => true, null, ['membership', 'allowAll']);
    expect(fn () => scrollInto($host, 'a', 'a'))->toThrow(InvalidArgumentException::class);
});

it('refuses with cowork.denied.middleware when a host middleware answers nonsense', function (): void {
    $host = new EngineHost(true, Config::resolve(['control' => ['mode' => 'free']]), fn (): bool => true, fn (): bool => true, null, [fn (): string => 'ok']);
    expect(scrollInto($host, 'a', 'a'))->toBe(['allow' => false, 'reason' => 'cowork.denied.middleware', 'status' => 403]);
});
