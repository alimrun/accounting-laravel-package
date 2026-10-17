<?php

namespace Alimrun\Ledger\Support;

use Illuminate\Container\Container;

/**
 * Resolves the dispatcher on every call, so Event::fake() in an
 * application's tests also catches ledger events.
 */
final class Events
{
    public static function dispatch(object $event): void
    {
        Container::getInstance()->make('events')->dispatch($event);
    }
}
