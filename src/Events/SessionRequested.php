<?php

declare(strict_types=1);

namespace FancyCowork\Events;

/** A visitor asked for a session; it waits in `requested` until someone authorised activates it. */
final class SessionRequested extends CoworkEventBase {}
