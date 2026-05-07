<?php

declare(strict_types=1);

namespace Scrappy;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

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

            return new Scrappy(
                apiKey: (string) ($config['api_key'] ?? ''),
                baseUrl: (string) ($config['base_url'] ?? 'https://api.scrappy.hu'),
                timeout: (int) ($config['timeout'] ?? 30),
                replayWindowSeconds: (int) ($config['replay_window_seconds'] ?? 300),
            );
        });

        // Lower-case alias for `app('scrappy')` shorthand — matches the
        // facade accessor below.
        $this->app->alias(Scrappy::class, 'scrappy');
    }

    public function boot(): void
    {
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
