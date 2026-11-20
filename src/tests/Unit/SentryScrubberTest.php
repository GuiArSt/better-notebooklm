<?php

namespace Tests\Unit;

use App\Support\Sentry\Scrubber;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Options;
use Sentry\Serializer\PayloadSerializer;
use Sentry\UserDataBag;

class SentryScrubberTest extends TestCase
{
    public function test_it_strips_text_and_keeps_the_class_and_the_user_id(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'method' => 'POST',
            'url' => 'https://app.test/notebooks/7/chat?q=a fake prompt',
            'data' => ['message' => 'a fake prompt about my divorce'],
            'cookies' => ['laravel_session' => 'secret'],
            'headers' => ['Authorization' => 'Bearer x'],
        ]);
        $event->setUser(new UserDataBag('42', 'jane@example.com', '10.0.0.1', 'jane'));
        $event->setMessage('a fake prompt about my divorce');
        $event->setExceptions([new ExceptionDataBag(new RuntimeException('a fake prompt, jane@example.com'))]);
        $event->setExtra(['title' => 'My private notebook']);

        $out = (new PayloadSerializer(new Options))->serialize(Scrubber::event($event));

        $this->assertStringContainsString('RuntimeException', $out);
        $this->assertStringContainsString('"42"', $out);
        $this->assertStringContainsString('/notebooks/7/chat"', stripslashes($out));
        foreach (['fake prompt', 'jane', 'divorce', 'secret', 'Bearer', '10.0.0.1', 'private notebook'] as $leak) {
            $this->assertStringNotContainsString($leak, $out);
        }
    }
}
