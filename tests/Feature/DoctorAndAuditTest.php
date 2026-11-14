<?php

declare(strict_types=1);

use FancyCowork\Console\DoctorCommand;
use FancyCowork\Participant;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function runDoctor(): array
{
    $command = app(DoctorCommand::class);
    $command->setLaravel(app());
    $output = new BufferedOutput;
    $code = $command->run(new ArrayInput([]), $output);

    return [$code, $output->fetch()];
}

it('lists every unbound contract and fails while any is unbound', function (): void {
    coworkApp();
    [$code, $text] = runDoctor();
    expect($code)->toBe(1);
    foreach (['identify', 'members', 'authorize', 'config'] as $contract) {
        expect($text)->toMatch("/{$contract}\\s+UNBOUND/");
    }

    cowork()->identify(fn (): ?Participant => null)->members(fn (): bool => false);
    [$code, $text] = runDoctor();
    expect($code)->toBe(1)
        ->and($text)->toMatch('/identify\s+bound/')
        ->and($text)->toMatch('/authorize\s+UNBOUND/');
});

it('passes once everything is bound, and names the broadcaster and the stack', function (): void {
    coworkApp();
    bindHost();
    [$code, $text] = runDoctor();
    expect($code)->toBe(0)
        ->and($text)->toContain('capture')
        ->and($text)->toContain('membership, authorize, payloadSize');
});

it('writes no audit row unless the audit table is turned on', function (): void {
    coworkApp();
    bindHost();
    liveSession();
    expect(app('db')->getSchemaBuilder()->hasTable('cowork_audit'))->toBeFalse();
});

it('records every event in cowork_audit when the flag is on, never a value', function (): void {
    coworkApp(['fancy-cowork.audit.table' => true]);
    bindHost();
    $id = liveSession();
    http('POST', "/cowork/sessions/{$id}/intents", ['intent' => ['kind' => 'field', 'handle' => 'h', 'value' => 'secret-value'], 'view' => 'a', 'revision' => 0], 'a', ['X-Acting-For' => 'u-1']);

    $rows = app('db')->table('cowork_audit')->orderBy('id')->get();
    expect($rows->pluck('type')->all())->toBe(['PeerJoined', 'PeerJoined', 'IntentRelayed'])
        ->and($rows[2]->acting_for)->toBe('u-1')
        ->and($rows[2]->session_id)->toBe($id)
        ->and(json_encode($rows))->not->toContain('secret-value');
});
