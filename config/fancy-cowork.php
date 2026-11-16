<?php

declare(strict_types=1);

/*
 * fancy-cowork. Nothing here allows anything: identity, membership, policy and
 * each session's config come only from resolvers the host binds on the Cowork
 * facade (see the README). Until they are bound every route refuses with a
 * named reason, and `php artisan cowork:doctor` lists what is missing.
 */
return [

    // Route prefix and the middleware the host puts in front of every route
    // (its own session, CSRF, auth). The package adds none of its own.
    'prefix' => 'cowork',
    'middleware' => ['web'],

    // Set false to register no routes and drive CoworkManager yourself.
    'routes' => true,

    // Database connection for the cowork_* tables; null is the default one.
    'connection' => null,

    // Server routing: broadcast relayed intents and session changes on
    // presence-cowork.session.{id}. Uses the default broadcaster.
    'broadcast' => true,

    // Send those broadcasts AFTER the response (the kernel's terminate), so a
    // synchronous broadcaster talking to a slow or rate-limited socket server
    // never holds the caller's request or a PHP worker's answer. A long-running
    // non-HTTP process (a queue worker) calls Cowork::flushBroadcasts() itself,
    // or sets this to false to send in place.
    'broadcast_after_response' => true,

    // Register cowork.session.{id} / cowork.participant.{ref} with Laravel's
    // own /broadcasting/auth too (for hosts whose participants are signed in).
    // POST {prefix}/auth works for everyone, signed-in or not.
    'channels' => true,

    'audit' => [
        // OFF by default: audit is events only. When true, every event is also
        // written to cowork_audit (publish and run the audit migration first).
        'table' => false,
    ],

    // Live presence and expiry. A closed browser never says goodbye: a
    // participant not seen (a heartbeat, default every 30 s from the client, or
    // any request of theirs) for idle_ms is removed; when the starter is gone the
    // session ends (end_when_starter_leaves); a session with nobody in it for
    // empty_ms ends. Applied on every read and write, and by `cowork:prune`.
    'presence' => [
        'idle_ms' => 120_000,
        'empty_ms' => 600_000,
        'end_when_starter_leaves' => true,
    ],

    'guest' => [
        // Optional signed guest tokens (FancyCowork\GuestTokens). The host may
        // ignore them entirely. Lifetime in seconds, and the request header the
        // resolver reads.
        'ttl' => 7200,
        'header' => 'X-Cowork-Guest',
    ],
];
