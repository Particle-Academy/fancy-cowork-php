<?php

declare(strict_types=1);

use FancyCowork\Broadcasting\PusherProtocol;
use FancyCowork\PusherSignature;

/*
 * The Pusher protocol's own worked examples (pusher.com docs, "Generating
 * authentication signatures" for the HTTP API, and the channel-auth reference).
 * These are the vectors that REPRODUCE from their stated inputs; both were
 * checked against an independent HMAC before being written here. The
 * presence-channel example on that page does not reproduce from the
 * channel_data it shows (twelve serialisations tried), so it is not pinned.
 */
const PUSHER_DOC_KEY = '278d425bdf160c739803';
const PUSHER_DOC_SECRET = '7ad3773142a6692b25b8';

it('signs the HTTP API events request exactly as the protocol documents', function (): void {
    $body = '{"name":"foo","channels":["project-3"],"data":"{\"some\":\"data\"}"}';

    $query = PusherProtocol::eventQuery(PUSHER_DOC_KEY, PUSHER_DOC_SECRET, '/apps/3/events', $body, 1353088179);

    expect($query)->toBe([
        'auth_key' => PUSHER_DOC_KEY,
        'auth_timestamp' => '1353088179',
        'auth_version' => '1.0',
        'body_md5' => 'ec365a775a4cd0599faeb73354201b6f',
        'auth_signature' => 'da454824c97ba181a32ccc17a72625ba02771f50b50e1e7430e47a1f3f457e6c',
    ]);
});

it('signs a private channel the way the protocol documents', function (): void {
    expect(PusherSignature::sign(PUSHER_DOC_KEY, PUSHER_DOC_SECRET, '1234.1234', 'private-foobar'))
        ->toBe(['auth' => PUSHER_DOC_KEY.':58df8b0c36d6982b82c3ecf6b4662e34fe8c25bba48f5369f135bf843651c3a4']);
});

it('signs a presence channel over socket, channel and the exact channel_data string', function (): void {
    $data = '{"user_id":"a","user_info":{"ref":"a"}}';
    $expected = hash_hmac('sha256', "1234.1234:presence-room:{$data}", PUSHER_DOC_SECRET);

    expect(PusherSignature::sign(PUSHER_DOC_KEY, PUSHER_DOC_SECRET, '1234.1234', 'presence-room', $data))
        ->toBe(['auth' => PUSHER_DOC_KEY.':'.$expected, 'channel_data' => $data]);
});

it('builds the string to sign from the method, the path and the parameters sorted by name', function (): void {
    $signature = PusherProtocol::signRequest('post', '/apps/1/events', ['b' => '2', 'a' => '1', 'auth_key' => 'k'], 's');

    expect($signature)->toBe(hash_hmac('sha256', "POST\n/apps/1/events\na=1&auth_key=k&b=2", 's'));
});

it('never signs over an auth_signature it was handed', function (): void {
    $with = PusherProtocol::signRequest('POST', '/p', ['a' => '1', 'auth_signature' => 'forged'], 's');
    $without = PusherProtocol::signRequest('POST', '/p', ['a' => '1'], 's');

    expect($with)->toBe($without);
});
