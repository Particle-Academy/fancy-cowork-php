<?php

declare(strict_types=1);

use FancyCowork\Console\PruneCommand;
use FancyCowork\Contracts\CoworkEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * Two fixes from MOIC's real two-browser check (2026-11-15):
 *
 * 1. One ref, one identity. Their admin, viewing as a user, identified as that
 *    user's ref; both browsers became ONE participant and nothing mirrored. A
 *    second identity on a live ref is now refused, loudly, with a named reason.
 * 2. Live presence and expiry. A closed browser left its participant (and the
 *    session) live forever. A heartbeat keeps a participant; one not seen for
 *    presence.idle_ms is removed, and a session whose starter is gone, or that
 *    has been empty for presence.empty_ms, ends.
 */

beforeEach(function (): void {
    bootRecording($this);
});

/** Boot the app (with config) and record every event it dispatches into $test->events. */
function bootRecording(object $test, array $config = []): void
{
    coworkApp($config);
    $test->events = [];
    app('events')->listen(CoworkEvent::class, function (CoworkEvent $event) use ($test): void {
        $test->events[] = $event->toArray();
    });
}

function startAs(string $who, array $headers = []): string
{
    $started = http('POST', '/cowork/sessions', ['mode' => 'start', 'route' => '/dashboard'], $who, $headers);
    expect($started['status'])->toBe(201);

    return $started['body']['session']['id'];
}

/** @return array<string, array<string, mixed>> participants of a re-sync, by ref */
function syncedParticipants(string $id, string $who = 'a'): array
{
    $sync = http('GET', "/cowork/sessions/{$id}", null, $who);
    expect($sync['status'])->toBe(200);

    return collect($sync['body']['session']['participants'])->keyBy('ref')->all();
}

it('refuses a second identity on a live ref through the real endpoints, and admits the right shape', function (): void {
    bindHost(['user:1', 'admin:7']);
    $id = startAs('user:1');

    // The admin, viewing as the user, identifying as the USER's ref: refused, never merged.
    $join = http('POST', "/cowork/sessions/{$id}/join", ['route' => '/dashboard'], 'user:1', ['X-Acting-For' => 'admin:7']);
    expect($join['status'])->toBe(409)->and($join['body']['reason'])->toBe('cowork.denied.ref-in-use');
    $intent = http('POST', "/cowork/sessions/{$id}/intents", ['intent' => ['kind' => 'field', 'handle' => 'composer', 'value' => 'x'], 'view' => 'user:1', 'revision' => 0], 'user:1', ['X-Acting-For' => 'admin:7']);
    expect($intent['status'])->toBe(409)->and($intent['body']['reason'])->toBe('cowork.denied.ref-in-use');

    // The right shape: ref is the person at the keyboard, actingFor the account.
    expect(http('POST', "/cowork/sessions/{$id}/join", ['route' => '/dashboard'], 'admin:7', ['X-Acting-For' => 'user:1'])['status'])->toBe(200);
    expect(array_keys(syncedParticipants($id, 'user:1')))->toBe(['user:1', 'admin:7']);
    // The same person in a second tab is still allowed.
    expect(http('POST', "/cowork/sessions/{$id}/join", ['route' => '/dashboard'], 'user:1')['status'])->toBe(200);
});

it('answers a heartbeat 204 for a participant, refuses anyone else, and stamps lastSeen', function (): void {
    bindHost(['a', 'b', 'c']);
    $id = liveSession();
    tick(5_000);

    expect(http('POST', "/cowork/sessions/{$id}/heartbeat", null, 'a')['status'])->toBe(204);
    $c = http('POST', "/cowork/sessions/{$id}/heartbeat", null, 'c');
    expect($c['status'])->toBe(403)->and($c['body']['reason'])->toBe('cowork.denied.membership');
    $other = http('POST', "/cowork/sessions/{$id}/heartbeat", null, 'a', ['X-Acting-For' => 'someone-else']);
    expect($other['status'])->toBe(409)->and($other['body']['reason'])->toBe('cowork.denied.ref-in-use');
    expect(http('POST', '/cowork/sessions/01BADBADBADBADBADBADBADBAD/heartbeat', null, 'a')['status'])->toBe(404);

    // Read through b, whose sync touches only b: a's lastSeen comes from the store.
    $heartbeatAt = TestClock::$now;
    tick(1_000);
    $participants = syncedParticipants($id, 'b');
    expect($participants['a']['lastSeen'])->toBe($heartbeatAt)
        ->and($participants['a']['online'])->toBeTrue()
        ->and($participants['b']['lastSeen'])->toBe(TestClock::$now);
});

it('never lets another identity keep a ref alive', function (): void {
    bindHost(['a', 'b']);
    $id = liveSession();
    $joinedAt = TestClock::$now;
    tick(5_000);

    expect(http('POST', "/cowork/sessions/{$id}/heartbeat", null, 'a', ['X-Acting-For' => 'someone-else'])['status'])->toBe(409);
    expect(syncedParticipants($id, 'b')['a']['lastSeen'])->toBe($joinedAt);
});

it('commits the sweep even when the operation that ran it is refused', function (): void {
    bootRecording($this, ['fancy-cowork.presence.idle_ms' => 10_000]);
    bindHost(['a', 'b']);
    $id = liveSession();
    tick(10_000);

    // An invalid intent is refused, but the sweep it ran (b expired) is committed.
    expect(http('POST', "/cowork/sessions/{$id}/intents", ['intent' => ['kind' => 'nonsense'], 'view' => 'a', 'revision' => 0], 'a')['status'])->toBe(422);
    expect(app('db')->table('cowork_participants')->where('session_id', $id)->where('ref', 'b')->whereNull('left_at')->count())->toBe(0);
    syncedParticipants($id);
    expect(collect($this->events)->where('type', 'PeerLeft')->where('ref', 'b')->count())->toBe(1);
});

it('removes a participant not seen for idle_ms on the next read, as PeerLeft with reason expired', function (): void {
    bootRecording($this, ['fancy-cowork.presence.idle_ms' => 10_000]);
    bindHost(['a', 'b']);
    $id = liveSession();

    tick(9_999);
    http('POST', "/cowork/sessions/{$id}/heartbeat", null, 'a');
    expect(array_keys(syncedParticipants($id)))->toBe(['a', 'b']); // b: 9 999 ms, still here

    tick(1);
    expect(array_keys(syncedParticipants($id)))->toBe(['a']);
    $left = collect($this->events)->firstWhere('type', 'PeerLeft');
    expect($left)->toMatchArray(['ref' => 'b', 'reason' => 'expired']);
});

it('expires lazily on a write too: an idle peer is gone before the next intent is relayed', function (): void {
    bootRecording($this, ['fancy-cowork.presence.idle_ms' => 10_000]);
    bindHost(['a', 'b']);
    $id = liveSession();
    tick(10_000);
    http('POST', "/cowork/sessions/{$id}/heartbeat", null, 'a');

    $intent = http('POST', "/cowork/sessions/{$id}/intents", ['intent' => ['kind' => 'scroll', 'y' => 1], 'view' => 'b', 'revision' => 0], 'a');
    expect($intent['status'])->toBe(403)->and($intent['body']['reason'])->toBe('cowork.denied.membership');
});

it('ends the session when its starter leaves, and not when another participant does', function (): void {
    bindHost(['a', 'b', 'c']);
    $id = liveSession();
    expect(http('POST', "/cowork/sessions/{$id}/join", ['route' => '/'], 'c')['status'])->toBe(200);

    expect(http('POST', "/cowork/sessions/{$id}/leave", null, 'c')['status'])->toBe(200);
    expect(http('GET', "/cowork/sessions/{$id}", null, 'a')['body']['session']['state'])->toBe('live');

    expect(http('POST', "/cowork/sessions/{$id}/leave", null, 'a')['status'])->toBe(200);
    $after = http('POST', "/cowork/sessions/{$id}/intents", ['intent' => ['kind' => 'scroll', 'y' => 1], 'view' => 'b', 'revision' => 0], 'b');
    expect($after['status'])->toBe(410)->and($after['body']['reason'])->toBe('cowork.session.ended');
    expect(collect($this->events)->firstWhere('type', 'SessionEnded'))->toMatchArray(['ref' => 'a', 'reason' => 'starter-left']);
});

it('ends the session when its starter EXPIRES (the browser closed)', function (): void {
    bootRecording($this, ['fancy-cowork.presence.idle_ms' => 10_000]);
    bindHost(['a', 'b']);
    $id = liveSession();
    tick(10_000);
    http('POST', "/cowork/sessions/{$id}/heartbeat", null, 'b'); // b is alive; a closed the tab

    expect(http('GET', "/cowork/sessions/{$id}", null, 'b')['body']['session']['state'])->toBe('ended');
});

it('keeps a starterless session when the host says so, and ends it once empty for empty_ms', function (): void {
    bootRecording($this, ['fancy-cowork.presence.end_when_starter_leaves' => false, 'fancy-cowork.presence.empty_ms' => 60_000]);
    bindHost(['a', 'b']);
    $id = liveSession();
    http('POST', "/cowork/sessions/{$id}/leave", null, 'a');
    expect(http('GET', "/cowork/sessions/{$id}", null, 'b')['body']['session']['state'])->toBe('live');
    http('POST', "/cowork/sessions/{$id}/leave", null, 'b');

    tick(59_999);
    expect(runPrune()['ended'])->toBe(0);
    tick(1);
    $prune = runPrune();
    expect($prune['code'])->toBe(0)->and($prune['ended'])->toBe(1)->and($prune['output'])->toContain('1 session(s) ended');
    expect(collect($this->events)->firstWhere('type', 'SessionEnded'))->toMatchArray(['reason' => 'empty']);
});

it('cowork:prune expires idle participants in every live session without a request', function (): void {
    bootRecording($this, ['fancy-cowork.presence.idle_ms' => 10_000]);
    bindHost(['a', 'b']);
    $id = liveSession();
    tick(10_000);

    $prune = runPrune();
    expect($prune['code'])->toBe(0)->and($prune['expired'])->toBe(2)->and($prune['ended'])->toBe(1);
    expect(app('db')->table('cowork_sessions')->where('id', $id)->value('state'))->toBe('ended');
});

it('ships secure defaults that the host can change', function (): void {
    expect(config('fancy-cowork.presence'))->toBe(['idle_ms' => 120_000, 'empty_ms' => 600_000, 'end_when_starter_leaves' => true]);
});

/** @return array{code: int, output: string, expired: int, ended: int} */
function runPrune(): array
{
    $command = app(PruneCommand::class);
    $command->setLaravel(app());
    $output = new BufferedOutput;
    $code = $command->run(new ArrayInput([]), $output);
    $text = $output->fetch();
    preg_match('/(\d+) participant\(s\) expired/', $text, $expired);
    preg_match('/(\d+) session\(s\) ended/', $text, $ended);

    return ['code' => $code, 'output' => $text, 'expired' => (int) ($expired[1] ?? -1), 'ended' => (int) ($ended[1] ?? -1)];
}
