<?php

declare(strict_types=1);

namespace FancyCowork\Events;

/** A participant left; any view it held went back to its owner. */
final class PeerLeft extends CoworkEventBase {}
