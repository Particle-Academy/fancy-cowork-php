<?php

declare(strict_types=1);

namespace FancyCowork;

use FancyCowork\Audit\AuditRecorder;
use FancyCowork\Broadcasting\PusherBroadcaster;
use FancyCowork\Broadcasting\PusherHttpClient;
use FancyCowork\Console\DoctorCommand;
use FancyCowork\Console\PruneCommand;
use FancyCowork\Contracts\CoworkEvent;
use FancyCowork\Store\SessionStore;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Registers the manager, the routes, the migrations, `cowork:doctor`, the
 * channel callbacks and (only when asked) the audit table recorder. It binds
 * NO host contract: until the host does, everything refuses.
 */
final class CoworkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/fancy-cowork.php', 'fancy-cowork');

        $this->app->singleton(SessionStore::class, fn ($app): SessionStore => new SessionStore($app['db'], $app['config']->get('fancy-cowork.connection')));
        $this->app->singleton(CoworkManager::class, fn ($app): CoworkManager => new CoworkManager($app, $app->make(SessionStore::class), $app['events'], $app['config']));
        $this->app->singleton(GuestTokens::class, fn ($app): GuestTokens => GuestTokens::fromAppKey(
            (string) $app['config']->get('app.key'),
            (int) $app['config']->get('fancy-cowork.guest.ttl', 7200),
            (string) $app['config']->get('fancy-cowork.guest.header', 'X-Cowork-Guest'),
        ));
    }

    public function boot(): void
    {
        $config = $this->app['config'];

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        if ($config->get('fancy-cowork.audit.table')) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations/audit');
            $this->app['events']->listen(CoworkEvent::class, fn (CoworkEvent $event) => (new AuditRecorder($this->app['db'], $config->get('fancy-cowork.connection')))->handle($event));
        }

        if ($config->get('fancy-cowork.routes', true)) {
            Route::prefix((string) $config->get('fancy-cowork.prefix', 'cowork'))
                ->middleware((array) $config->get('fancy-cowork.middleware', ['web']))
                ->group(__DIR__.'/../routes/cowork.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([DoctorCommand::class, PruneCommand::class]);
            $this->publishes([__DIR__.'/../config/fancy-cowork.php' => $this->app->configPath('fancy-cowork.php')], 'fancy-cowork-config');
            $this->publishes([__DIR__.'/../database/migrations' => $this->app->databasePath('migrations')], 'fancy-cowork-migrations');
        }

        $this->registerPusherDriver();
        $this->registerChannels();
    }

    /**
     * The `fancy-pusher` broadcast driver: any Pusher-protocol server (Genie's
     * WebSockets service, Sockudo, Reverb, Pusher) through our own HTTP-API client,
     * so a host needs neither pusher/pusher-php-server nor laravel/reverb. Registered
     * before the channel callbacks resolve the default connection.
     */
    private function registerPusherDriver(): void
    {
        $extend = function (BroadcastManager $manager): void {
            $manager->extend('fancy-pusher', fn ($app, array $config): PusherBroadcaster => new PusherBroadcaster(
                PusherHttpClient::fromConfig(
                    fn (): HttpFactory => $app->make(HttpFactory::class),
                    $config,
                    null,
                    (array) $app['config']->get('fancy-cowork.publish', []),
                ),
            ));
        };
        if ($this->app->resolved(BroadcastManager::class)) {
            $extend($this->app->make(BroadcastManager::class));
        } elseif ($this->app->bound(BroadcastManager::class)) {
            $this->app->afterResolving(BroadcastManager::class, $extend);
        }
    }

    /**
     * For hosts on Laravel's own `/broadcasting/auth`. The callbacks ignore the
     * guard's user and ask the host's `identify` about the current request.
     */
    private function registerChannels(): void
    {
        if (! $this->app['config']->get('fancy-cowork.channels', true) || ! $this->app->bound(BroadcastManager::class)) {
            return;
        }
        try {
            $broadcaster = $this->app->make(BroadcastManager::class)->connection();
            $cowork = fn (): CoworkManager => $this->app->make(CoworkManager::class);
            $request = fn (): Request => $this->app->make('request');
            $broadcaster->channel('cowork.session.{id}', fn ($user, string $id) => $cowork()->channelMember($request(), "presence-cowork.session.{$id}"));
            $broadcaster->channel('cowork.participant.{ref}', fn ($user, string $ref) => $cowork()->channelMember($request(), "private-cowork.participant.{$ref}"));
        } catch (Throwable) {
            // No usable broadcaster: POST {prefix}/auth still answers, and cowork:doctor names the driver.
        }
    }
}
