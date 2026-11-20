<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** D-043: the browser gets the Sentry settings from the server, through the shared Inertia props. */
class SharedSentryPropsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sentry_settings_are_shared_with_every_page(): void
    {
        config(['sentry.enabled' => true, 'sentry.dsn' => 'https://key@example.ingest.sentry.io/1', 'sentry.environment' => 'staging', 'sentry.release' => 'abc123']);

        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('sentry.enabled', true)
            ->where('sentry.dsn', 'https://key@example.ingest.sentry.io/1')
            ->where('sentry.environment', 'staging')
            ->where('sentry.release', 'abc123'));
    }
}
