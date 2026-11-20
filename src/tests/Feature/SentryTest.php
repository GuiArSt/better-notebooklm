<?php

namespace Tests\Feature;

use App\Support\Sentry\Scrubber;
use Sentry\SentrySdk;
use Tests\TestCase;

class SentryTest extends TestCase
{
    public function test_it_is_off_without_a_dsn_and_the_app_answers_as_before(): void
    {
        $this->assertNull(config('sentry.dsn'));
        $this->assertFalse(config('sentry.enabled'));
        $this->assertFalse(config('sentry.send_default_pii'));
        $this->assertSame(0, config('sentry.traces_sample_rate'));
        $this->assertNull(SentrySdk::getCurrentHub()->getClient()?->getOptions()->getDsn());
        $this->get('/up')->assertOk();
    }

    public function test_every_event_goes_through_the_scrubber(): void
    {
        $this->assertSame([Scrubber::class, 'event'], config('sentry.before_send'));
    }
}
