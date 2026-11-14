<?php

declare(strict_types=1);

namespace FancyCowork\Engine;

/**
 * Every named refusal. The fixture-pinned ones are shared, letter for letter,
 * with fancy-cowork-js and the agent-integrations client engine.
 */
final class Reasons
{
    public const string UNCONFIGURED_IDENTITY = 'cowork.unconfigured.identity';

    public const string UNCONFIGURED_CONFIG = 'cowork.unconfigured.config';

    public const string UNCONFIGURED_MEMBERS = 'cowork.unconfigured.members';

    public const string UNCONFIGURED_AUTHORIZE = 'cowork.unconfigured.authorize';

    public const string INVALID = 'cowork.invalid.intent';

    public const string ENDED = 'cowork.session.ended';

    public const string NOT_LIVE = 'cowork.session.not-live';

    public const string NOT_REQUESTED = 'cowork.session.not-requested';

    public const string MEMBERSHIP = 'cowork.denied.membership';

    public const string AUTHORIZE = 'cowork.denied.authorize';

    public const string SIZE = 'cowork.denied.size';

    public const string ORIGIN = 'cowork.denied.origin';

    public const string MASKED = 'cowork.denied.masked';

    public const string VIEW_ONLY = 'cowork.denied.view-only';

    public const string LIMIT = 'cowork.denied.limit';

    public const string STALE = 'cowork.denied.stale';

    public const string CONTROL = 'cowork.denied.control';

    public const string CONTROL_MODE = 'cowork.denied.control-mode';

    public const string RATE = 'cowork.denied.rate';

    public const string FULL = 'cowork.denied.full';

    public const string CONSENT_PENDING = 'cowork.consent.pending';

    public const string NOT_SUBJECT = 'cowork.consent.not-subject';

    public const string MIDDLEWARE = 'cowork.denied.middleware';

    // Server-level, outside the fixtures (plan section 11).
    public const string UNIDENTIFIED = 'cowork.unidentified';

    public const string UNKNOWN_SESSION = 'cowork.session.unknown';

    public const string HOST_ERROR = 'cowork.host.error';

    public const string CHANNEL = 'cowork.denied.channel';

    /** @return array{allow: false, reason: string, status: int, blockedUntil?: int} */
    public static function refuse(string $reason, int $status, ?int $blockedUntil = null): array
    {
        $decision = ['allow' => false, 'reason' => $reason, 'status' => $status];
        if ($blockedUntil !== null) {
            $decision['blockedUntil'] = $blockedUntil;
        }

        return $decision;
    }

    /** @return array{allow: true} */
    public static function allow(): array
    {
        return ['allow' => true];
    }
}
