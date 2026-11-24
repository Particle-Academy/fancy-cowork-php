<?php

declare(strict_types=1);

namespace FancyCowork\Broadcasting;

/**
 * A broadcast the package could not deliver: the socket service kept refusing it
 * (or could not be reached) past `fancy-cowork.publish`'s retry budget. The change
 * behind it is committed; clients that missed a session change re-sync with GET,
 * while a missed cursor or activity is simply stale until that peer's next one.
 *
 * Dispatched on Laravel's event bus, after the response, alongside a warning in
 * the log. Listen for it to count, alert or retry on a queue. It names the
 * channel and the event, never the payload: an intent's value is not in it.
 *
 * Not one of the eight audit events ({@see \FancyCowork\Contracts\CoworkEvent}):
 * it is about delivery, not about the session.
 */
final readonly class BroadcastFailed
{
    public function __construct(
        /** The channel it was for, e.g. `presence-cowork.session.{id}`. */
        public string $channel,
        /** The broadcast's name, e.g. `cowork.intent`. */
        public string $event,
        /** The session id the channel names, when it names one. */
        public ?string $session,
        /** Why: the broadcaster's message for its own refusal, else the exception's class. */
        public string $reason,
    ) {}
}
