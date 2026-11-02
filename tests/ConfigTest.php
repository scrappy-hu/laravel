<?php

declare(strict_types=1);

namespace Scrappy\Tests;

use Orchestra\Testbench\TestCase;
use Scrappy\Facades\Scrappy;
use Scrappy\ScrappyServiceProvider;

/**
 * The package config reads every key from the environment. The other
 * tests set scrappy.webhook_secret directly, so they would not notice the
 * key going missing from config/scrappy.php (it once sat inside an
 * unclosed comment block).
 */
final class ConfigTest extends TestCase
{
    private const SECRET = 'cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34';

    protected function setUp(): void
    {
        $this->setEnv('SCRAPPY_WEBHOOK_SECRET', self::SECRET);
        $this->setEnv('SCRAPPY_API_KEY', 'sk_live_test');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->setEnv('SCRAPPY_WEBHOOK_SECRET', null);
        $this->setEnv('SCRAPPY_API_KEY', null);
    }

    protected function getPackageProviders($app): array
    {
        return [ScrappyServiceProvider::class];
    }

    public function test_webhook_secret_is_read_from_the_environment(): void
    {
        $this->assertSame(self::SECRET, config('scrappy.webhook_secret'));
    }

    public function test_every_documented_key_is_defined(): void
    {
        $config = require __DIR__.'/../config/scrappy.php';

        $this->assertSame(
            ['api_key', 'timeout', 'webhook_secret', 'replay_window_seconds'],
            array_keys($config),
        );
    }

    public function test_verify_works_with_only_the_env_secret(): void
    {
        $body = '{"event":"webhook.test"}';
        $t = time();
        $header = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, self::SECRET);

        $this->assertTrue(Scrappy::webhooks()->verify($body, $header));
    }

    private function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }

        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
