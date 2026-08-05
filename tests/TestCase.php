<?php

namespace Tests;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    public function createApplication()
    {
        $app = parent::createApplication();

        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql.database', 'vovremya_test');
        $app['config']->set('broadcasting.default', 'null');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');

        $app['db']->purge();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }
}
