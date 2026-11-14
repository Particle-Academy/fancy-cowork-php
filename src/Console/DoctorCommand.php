<?php

declare(strict_types=1);

namespace FancyCowork\Console;

use FancyCowork\CoworkManager;
use Illuminate\Console\Command;

/**
 * Lists every host contract, bound or not, plus the broadcaster and the
 * middleware stack. Exits non-zero while anything is unbound, because an
 * unbound contract refuses everything and a fresh install should say why.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'cowork:doctor';

    protected $description = 'Show which fancy-cowork host contracts are bound; fail while any is unbound.';

    public function handle(CoworkManager $cowork): int
    {
        $unbound = 0;
        foreach ($cowork->contracts() as $contract => $bound) {
            $this->line(sprintf('  %-10s %s', $contract, $bound ? 'bound' : 'UNBOUND -- every request refuses with cowork.unconfigured.'.($contract === 'identify' ? 'identity' : $contract)));
            $unbound += $bound ? 0 : 1;
        }
        $driver = (string) config('broadcasting.default', 'null');
        $this->line(sprintf('  %-10s %s', 'broadcast', $driver.($driver === 'null' || $driver === 'log' ? ' (nothing reaches a browser; server routing needs a Pusher-protocol server, e.g. driver fancy-pusher)' : '')));
        $this->line(sprintf('  %-10s %s', 'stack', implode(', ', $cowork->stackNames())));
        $this->line('  Direct routing also needs client events enabled on the socket server.');

        if ($unbound > 0) {
            $this->error("{$unbound} contract(s) unbound. Bind them on the Cowork facade; see the README.");

            return self::FAILURE;
        }
        $this->info('All host contracts are bound.');

        return self::SUCCESS;
    }
}
