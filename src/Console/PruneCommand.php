<?php

declare(strict_types=1);

namespace FancyCowork\Console;

use FancyCowork\CoworkManager;
use FancyCowork\Store\SessionStore;
use Illuminate\Console\Command;

/**
 * `cowork:prune`: run the presence sweep over every open session, for the
 * sessions no request touches (everyone closed their browser). Each request
 * already runs the same sweep on its own session, so this is the backstop:
 * schedule it, e.g. every minute.
 *
 *   $schedule->command('cowork:prune')->everyMinute();
 */
final class PruneCommand extends Command
{
    protected $signature = 'cowork:prune';

    protected $description = 'Expire idle co-work participants and end abandoned sessions (presence.idle_ms, presence.empty_ms, presence.end_when_starter_leaves).';

    public function handle(CoworkManager $cowork, SessionStore $store): int
    {
        $ids = $store->openSessionIds();
        $expired = 0;
        $ended = 0;
        foreach ($ids as $id) {
            $counts = $cowork->prune($id);
            $expired += $counts['expired'];
            $ended += $counts['ended'] ? 1 : 0;
        }
        $this->info(sprintf('%d participant(s) expired, %d session(s) ended, %d open session(s) checked.', $expired, $ended, count($ids)));

        return self::SUCCESS;
    }
}
