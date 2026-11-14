<?php

declare(strict_types=1);

use FancyCowork\GuestTokens;
use FancyCowork\Participant;
use Illuminate\Http\Request;

beforeEach(function (): void {
    coworkApp(['fancy-cowork.guest.ttl' => 600]);
    $this->tokens = app(GuestTokens::class);
});

it('issues a token that verifies to its guest id until it expires', function (): void {
    $token = $this->tokens->issue('visitor-1', now: 1000);
    expect($this->tokens->verify($token, now: 1000))->toBe('visitor-1')
        ->and($this->tokens->verify($token, now: 1599))->toBe('visitor-1')
        ->and($this->tokens->verify($token, now: 1600))->toBeNull();
});

it('issues a random id when none is given', function (): void {
    $a = $this->tokens->verify($this->tokens->issue(now: 1), now: 1);
    $b = $this->tokens->verify($this->tokens->issue(now: 1), now: 1);
    expect($a)->toMatch('/^[A-Za-z0-9_-]{22,}$/')->and($a)->not->toBe($b);
});

it('refuses a tampered, truncated or foreign token', function (): void {
    $token = $this->tokens->issue('visitor-1', now: 1000);
    [$version, $payload, $signature] = explode('.', $token);
    $forged = rtrim(strtr(base64_encode(json_encode(['sub' => 'visitor-2', 'exp' => 9_999_999_999])), '+/', '-_'), '=');

    expect($this->tokens->verify("{$version}.{$forged}.{$signature}", now: 1000))->toBeNull()
        ->and($this->tokens->verify("{$version}.{$payload}", now: 1000))->toBeNull()
        ->and($this->tokens->verify("v2.{$payload}.{$signature}", now: 1000))->toBeNull()
        ->and($this->tokens->verify('', now: 1000))->toBeNull();

    $other = new GuestTokens(str_repeat('z', 32), 600);
    expect($other->verify($token, now: 1000))->toBeNull();
});

it('is an identify resolver the host may bind, reading a header', function (): void {
    $resolver = $this->tokens->resolver();
    $token = $this->tokens->issue('visitor-1');
    $request = Request::create('/', 'GET', [], [], [], ['HTTP_X_COWORK_GUEST' => $token]);
    $participant = $resolver($request);
    expect($participant)->toBeInstanceOf(Participant::class)
        ->and($participant->ref)->toBe('guest:visitor-1');
    expect($resolver(Request::create('/')))->toBeNull();
});

it('lets the host fall back to its own identify for a signed-in request', function (): void {
    $resolver = $this->tokens->resolver(fallback: fn (Request $r): ?Participant => $r->header('X-Who') ? new Participant('member:'.$r->header('X-Who')) : null);
    expect($resolver(Request::create('/', 'GET', [], [], [], ['HTTP_X_WHO' => 'ada']))->ref)->toBe('member:ada');
});
