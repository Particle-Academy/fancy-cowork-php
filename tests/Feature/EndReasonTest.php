<?php

declare(strict_types=1);

use FancyCowork\Console\PruneCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * The re-sync says WHY a session ended, so a client (and the approved lab check)
 * can tell a starter who left from an empty room from an explicit end, without
 * reading the audit events: session.endReason is 'ended' | 'starter-left' |
 * 'empty', and null while the session is live.
 */

function endReasonOf(string $id, string $who): mixed
{
    $sync = http('GET', "/cowork/sessions/{$id}", null, $who);
    expect($sync['status'])->toBe(200);

    // A present null must read as null: `??` would turn it into the sentinel.
    return array_key_exists('endReason', $sync['body']['session']) ? $sync['body']['session']['endReason'] : 'missing';
}

it('is null while the session is live', function (): void {
    coworkApp();
    bindHost(['a', 'b']);
    $id = liveSession();

    expect(endReasonOf($id, 'b'))->toBeNull();
});

it('says ended after an explicit end', function (): void {
    coworkApp();
    bindHost(['a', 'b']);
    $id = liveSession();
    expect(http('POST', "/cowork/sessions/{$id}/end", null, 'a')['status'])->toBe(200);

    expect(endReasonOf($id, 'b'))->toBe('ended');
});

it('says starter-left when the starter leaves', function (): void {
    coworkApp();
    bindHost(['a', 'b']);
    $id = liveSession();
    http('POST', "/cowork/sessions/{$id}/leave", null, 'a');

    expect(endReasonOf($id, 'b'))->toBe('starter-left');
});

it('says starter-left when the starter expires (the browser closed)', function (): void {
    coworkApp(['fancy-cowork.presence.idle_ms' => 10_000]);
    bindHost(['a', 'b']);
    $id = liveSession();
    tick(10_000);
    http('POST', "/cowork/sessions/{$id}/heartbeat", null, 'b');

    expect(endReasonOf($id, 'b'))->toBe('starter-left');
});

it('says empty when a starterless session stays empty for empty_ms', function (): void {
    coworkApp(['fancy-cowork.presence.end_when_starter_leaves' => false, 'fancy-cowork.presence.empty_ms' => 60_000]);
    bindHost(['a', 'b']);
    $id = liveSession();
    http('POST', "/cowork/sessions/{$id}/leave", null, 'a');
    http('POST', "/cowork/sessions/{$id}/leave", null, 'b');
    tick(60_000);
    $prune = app(PruneCommand::class);
    $prune->setLaravel(app());
    expect($prune->run(new ArrayInput([]), new BufferedOutput))->toBe(0);

    expect(endReasonOf($id, 'a'))->toBe('empty');
});

it('keeps the reason across a store round trip', function (): void {
    coworkApp();
    bindHost(['a', 'b']);
    $id = liveSession();
    http('POST', "/cowork/sessions/{$id}/end", null, 'a');

    expect(app(FancyCowork\Store\SessionStore::class)->read($id)['endReason'])->toBe('ended');
    // Stored, not inferred: the read path falls back to 'ended' for rows that predate the column.
    expect(Illuminate\Support\Facades\DB::table('cowork_sessions')->where('id', $id)->value('end_reason'))->toBe('ended');
});

it('stores starter-left in the row, not only in the answer', function (): void {
    coworkApp();
    bindHost(['a', 'b']);
    $id = liveSession();
    http('POST', "/cowork/sessions/{$id}/leave", null, 'a');

    expect(Illuminate\Support\Facades\DB::table('cowork_sessions')->where('id', $id)->value('end_reason'))->toBe('starter-left');
});
