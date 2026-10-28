<?php

declare(strict_types=1);

namespace Scrappy;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Scrappy\Http\Controllers\WebhookController;

/**
 * Auto-registered by Laravel via the package-discovery hook in
 * composer.json. Binds a singleton `Scrappy` instance configured
 * from `config/scrappy.php`, so the Facade can return the same
 * client across the request lifecycle.
 *
 * Publishes the default config to the host app:
 *     php artisan vendor:publish --tag=scrappy-config
 */
class ScrappyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/scrappy.php', 'scrappy');

        $this->app->singleton(Scrappy::class, function (Application $app): Scrappy {
            $config = $app['config']->get('scrappy');

            // Base URL is intentionally NOT pulled from config — the
            // SDK always points at api.scrappy.hu in Laravel mode.
            // Plain-PHP integrations can still override via the
            // constructor (used in tests + the unlikely self-hosted
            // Scrappy edge case), but consumers shouldn't be able to
            // misconfigure their way into the wrong server via .env.
            return new Scrappy(
                apiKey: (string) ($config['api_key'] ?? ''),
                timeout: (int) ($config['timeout'] ?? 30),
                replayWindowSeconds: (int) ($config['replay_window_seconds'] ?? 300),
                webhookSecret: isset($config['webhook_secret']) && $config['webhook_secret'] !== ''
                    ? (string) $config['webhook_secret']
                    : null,
            );
        });

        // Lower-case alias for `app('scrappy')` shorthand — matches the
        // facade accessor below.
        $this->app->alias(Scrappy::class, 'scrappy');
    }

    public function boot(): void
    {
        /*
         * Route::scrappyWebhook('scrappy/webhook') registers the webhook
         * receiver: it verifies the signature (401 if it doesn't match),
         * answers 200 at once and dispatches JobCompleted / JobFailed /
         * WebhookTestReceived. Outside the CSRF check, since Scrappy
         * signs instead. Named `scrappy.webhook`.
         */
        Route::macro('scrappyWebhook', function (string $uri = 'scrappy/webhook') {
            return Route::post($uri, WebhookController::class)
                ->name('scrappy.webhook')
                ->withoutMiddleware([
                    'Illuminate\\Foundation\\Http\\Middleware\\VerifyCsrfToken',
                    'Illuminate\\Foundation\\Http\\Middleware\\ValidateCsrfToken',
                    'App\\Http\\Middleware\\VerifyCsrfToken',
                ]);
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/scrappy.php' => config_path('scrappy.php'),
            ], 'scrappy-config');
        }
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [Scrappy::class, 'scrappy'];
    }
}
