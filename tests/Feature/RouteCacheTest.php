<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/*
 * Production caches the routes (php artisan optimize in deploy/deploy.sh); a route name used twice makes
 * that fail, and every deploy with it.
 */

test('every route name is used once, so the routes can be cached', function () {
    $names = collect(Router::getRoutes()->getRoutes())
        ->map(fn (Route $route) => $route->getName())
        ->filter();

    expect($names->duplicates()->values()->all())->toBe([]);
});
