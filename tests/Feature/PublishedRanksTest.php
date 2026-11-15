<?php

declare(strict_types=1);

/*
 * The server is the authority on ranked control, so the re-sync publishes what a
 * browser needs to hold its person without knowing anyone else's role: each
 * participant's effective rank, the scope and the idle window (fixture
 * `expectRanked`). Nothing is published for a mode that is not ranked.
 */

function syncOf(string $id, string $who): array
{
    $sync = http('GET', "/cowork/sessions/{$id}", null, $who);
    expect($sync['status'])->toBe(200);

    return $sync['body']['session'];
}

it('publishes each participant\'s effective rank, the scope and idleMs under ranked control', function (): void {
    coworkApp();
    bindHost(['a', 'b'], ['consent' => 'none', 'control' => ['mode' => 'ranked', 'scope' => 'session', 'rank' => ['a' => 2], 'idleMs' => 2500]]);
    $id = liveSession();

    $session = syncOf($id, 'b');
    expect($session['control'])->toMatchArray(['mode' => 'ranked', 'scope' => 'session', 'idleMs' => 2500]);
    $ranks = array_column($session['participants'], 'rank', 'ref');
    expect($ranks)->toBe(['a' => 2, 'b' => 0]);
});

it('publishes the defaults when the config leaves scope and idleMs unset', function (): void {
    coworkApp();
    bindHost(['a', 'b'], ['consent' => 'none', 'control' => ['mode' => 'ranked', 'rank' => fn (string $ref): int => $ref === 'b' ? 1 : 3]]);
    $id = liveSession();

    $session = syncOf($id, 'a');
    expect($session['control'])->toMatchArray(['mode' => 'ranked', 'scope' => 'view', 'idleMs' => 3000]);
    expect(array_column($session['participants'], 'rank', 'ref'))->toBe(['a' => 3, 'b' => 1]);
});

it('publishes no rank, scope or idleMs for a mode that is not ranked', function (): void {
    coworkApp();
    bindHost(['a', 'b']);
    $id = liveSession();

    $session = syncOf($id, 'a');
    expect($session['control'])->not->toHaveKeys(['scope', 'idleMs']);
    foreach ($session['participants'] as $p) {
        expect($p)->not->toHaveKey('rank');
    }
});
