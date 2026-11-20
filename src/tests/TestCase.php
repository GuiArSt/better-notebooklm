<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Events\RouteMatched;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * With RECORD_ROUTES=<file> set, every route a test reaches is appended to that file as "METHOD uri": the endpoint
     * matrix (scripts/route_matrix.py) reads it. Nothing changes when the variable is not set.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $file = getenv('RECORD_ROUTES');
        if ($file) {
            $this->app['events']->listen(RouteMatched::class, function (RouteMatched $e) use ($file) {
                file_put_contents($file, $e->request->method().' '.$e->route->uri()."\n", FILE_APPEND | LOCK_EX);
            });
        }
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
