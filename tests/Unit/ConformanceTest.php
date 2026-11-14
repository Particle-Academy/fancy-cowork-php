<?php

declare(strict_types=1);

use FancyCowork\Engine\Config;
use FancyCowork\Engine\Engine;
use FancyCowork\Engine\Pipeline;
use FancyCowork\Engine\SessionState;
use FancyCowork\Tests\Support\Fixtures;

it('is the pinned fixture file, byte for byte', function (): void {
    expect(hash('sha256', Fixtures::raw()))->toBe(Fixtures::SHA256);
});

it('names the contract this leg implements', function (): void {
    expect(Fixtures::all()['contract'])->toBe('fancy-cowork/v1');
});

it('pins the same default stack and config this leg ships', function (): void {
    $defaults = Fixtures::all()['defaults'];
    expect($defaults['middleware'])->toBe(Pipeline::DEFAULT_MIDDLEWARE);
    expect(Config::resolve([]))->toEqual($defaults['config']);
});

$cases = Fixtures::all()['cases'];
foreach ($cases as $case) {
    it($case['name'], function () use ($case): void {
        $host = Fixtures::host($case);
        $state = Fixtures::state($case);
        foreach (Fixtures::steps($case) as $i => $step) {
            $now = $step['at'] ?? 0;
            $from = $step['from'] ?? '';
            if ($step['op'] === 'expectView') {
                $view = $state['participants'][$step['view']] ?? null;
                expect(['step' => $i, 'route' => $view['route'] ?? null, 'revision' => $view['revision'] ?? null])
                    ->toBe(['step' => $i, 'route' => $step['route'], 'revision' => $step['revision']]);

                continue;
            }
            if ($step['op'] === 'expectHolder') {
                $control = SessionState::holderOf($state, $step['view']);
                expect(['step' => $i, 'holder' => $control['holder']])->toBe(['step' => $i, 'holder' => $step['holder']]);
                if (isset($step['requests'])) {
                    expect(['step' => $i, 'requests' => $control['requests']])->toBe(['step' => $i, 'requests' => $step['requests']]);
                }

                continue;
            }
            $op = ['from' => $from, 'now' => $now];
            $result = match ($step['op']) {
                'intent' => Engine::evaluateIntent($host, $state, [
                    'from' => $from,
                    'envelope' => $step['envelope'] ?? [],
                    'intent' => Fixtures::expand($step['intent']),
                    'now' => $now,
                ]),
                'join' => Engine::evaluateJoin($host, $state, $op),
                'activate' => Engine::evaluateActivate($host, $state, $op),
                'consent' => Engine::evaluateConsent($host, $state, $op),
                'end' => Engine::evaluateEnd($host, $state, $op),
                'leave' => Engine::evaluateLeave($host, $state, $op),
            };
            expect(['step' => $i, 'op' => $step['op'], 'at' => $now, 'decision' => $result->decision])
                ->toEqual(['step' => $i, 'op' => $step['op'], 'at' => $now, 'decision' => $step['expect']]);
            $state = $result->state;
        }
    });
}
