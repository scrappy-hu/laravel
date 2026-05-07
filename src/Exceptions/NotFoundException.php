<?php

declare(strict_types=1);

namespace Scrappy\Exceptions;

/** 404 — resource (job, etc.) not found, or not owned by this api key. */
class NotFoundException extends ScrappyException
{
}
