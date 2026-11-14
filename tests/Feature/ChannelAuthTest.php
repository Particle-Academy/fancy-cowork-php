<?php

declare(strict_types=1);

beforeEach(function (): void {
    coworkApp();
});

function channelAuth(string $channel, ?string $who, string $socket = '1234.5678'): array
{
    return http('POST', '/cowork/auth', ['socket_id' => $socket, 'channel_name' => $channel], $who);
}

it('admits a live member to the session presence channel, with a member payload from the server', function (): void {
    bindHost();
    $id = liveSession();
    $channel = "presence-cowork.session.{$id}";
    $response = channelAuth($channel, 'b');

    expect($response['status'])->toBe(200);
    $data = $response['body']['channel_data'];
    expect(json_decode($data, true))->toBe(['user_id' => 'b', 'user_info' => ['ref' => 'b', 'label' => 'B', 'view' => 'b', 'route' => '/']]);
    $expected = 'app-key:'.hash_hmac('sha256', "1234.5678:{$channel}:{$data}", 'app-secret');
    expect($response['body']['auth'])->toBe($expected);
});

it('refuses the presence channel to anyone members() does not admit, or who has not joined', function (): void {
    bindHost(['a', 'b', 'c']);
    $id = liveSession();
    expect(channelAuth("presence-cowork.session.{$id}", 'z')['body'])->toBe(['reason' => 'cowork.denied.membership']);
    expect(channelAuth("presence-cowork.session.{$id}", 'c')['body'])->toBe(['reason' => 'cowork.denied.membership']);

    cowork()->members(fn ($s, $p): bool => $p->ref === 'a');
    expect(channelAuth("presence-cowork.session.{$id}", 'b')['body'])->toBe(['reason' => 'cowork.denied.membership']);
});

it('refuses the presence channel of an ended session', function (): void {
    bindHost();
    $id = liveSession();
    http('POST', "/cowork/sessions/{$id}/end", [], 'a');
    expect(channelAuth("presence-cowork.session.{$id}", 'a')['status'])->toBe(410);
});

it('admits only the participant itself to its private channel, signed', function (): void {
    bindHost();
    $mine = channelAuth('private-cowork.participant.a', 'a');
    expect($mine['status'])->toBe(200)
        ->and($mine['body']['auth'])->toBe('app-key:'.hash_hmac('sha256', '1234.5678:private-cowork.participant.a', 'app-secret'));
    expect(channelAuth('private-cowork.participant.b', 'a')['body'])->toBe(['reason' => 'cowork.denied.channel']);
});

it('refuses any other channel and a malformed socket id', function (): void {
    bindHost();
    expect(channelAuth('private-orders.1', 'a')['body'])->toBe(['reason' => 'cowork.denied.channel']);
    expect(channelAuth('private-cowork.participant.a', 'a', 'not-a-socket')['status'])->toBe(422);
});

it('signs nothing when the broadcaster has no key and secret', function (): void {
    config()->set('broadcasting.default', 'null');
    bindHost();
    $response = channelAuth('private-cowork.participant.a', 'a');
    expect([$response['status'], $response['body']])->toBe([503, ['reason' => 'cowork.unconfigured.broadcasting']]);
});

it('registers the channels with Laravel broadcasting for hosts on /broadcasting/auth', function (): void {
    bindHost();
    $id = liveSession();
    $channels = app('Illuminate\Broadcasting\BroadcastManager')->connection()->getChannels();
    expect($channels->keys()->all())->toContain('cowork.session.{id}')->toContain('cowork.participant.{ref}');

    $request = Illuminate\Http\Request::create('/broadcasting/auth', 'POST', [], [], [], ['HTTP_X_WHO' => 'b']);
    app()->instance('request', $request);
    $callback = $channels->get('cowork.session.{id}');
    expect($callback(null, $id))->toBe(['ref' => 'b', 'label' => 'B', 'view' => 'b', 'route' => '/']);
    $request->headers->set('X-Who', 'z');
    expect($callback(null, $id))->toBeFalse();
});
