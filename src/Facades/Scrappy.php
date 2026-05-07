<?php

declare(strict_types=1);

namespace Scrappy\Facades;

use Illuminate\Support\Facades\Facade;
use Scrappy\Resources\Jobs;
use Scrappy\Resources\Me;
use Scrappy\Resources\Webhooks;

/**
 * Laravel facade for the Scrappy SDK.
 *
 * @method static Jobs jobs()
 * @method static Me me()
 * @method static Webhooks webhooks()
 *
 * @see \Scrappy\Scrappy
 */
class Scrappy extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'scrappy';
    }
}
