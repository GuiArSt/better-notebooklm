<?php

namespace Tests\Feature;

use App\Ai\SafeLog;
use App\Support\Sentry\Scrubber;
use RuntimeException;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Tests\TestCase;

class SentrySafeLogTest extends TestCase
{
    /** @return array{0: TransportInterface, 1: object} */
    private function fakeTransport(): array
    {
        $sent = new class
        {
            /** @var list<Event> */
            public array $events = [];
        };
        $transport = new class($sent) implements TransportInterface
        {
            public function __construct(private object $sent) {}

            public function send(Event $event): Result
            {
                $this->sent->events[] = $event;

                return new Result(ResultStatus::success());
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };

        return [$transport, $sent];
    }

    public function test_a_handled_failure_sends_one_event_with_the_class_and_no_message(): void
    {
        [$transport, $sent] = $this->fakeTransport();
        $client = ClientBuilder::create([
            'dsn' => 'https://key@o0.ingest.de.sentry.io/1',
            'before_send' => [Scrubber::class, 'event'],
        ])->setTransport($transport)->getClient();
        SentrySdk::setCurrentHub(new Hub($client));

        SafeLog::failure('Podcast', new RuntimeException('a fake prompt, jane@example.com'), ['notebook' => 7]);

        $this->assertCount(1, $sent->events);
        $exception = $sent->events[0]->getExceptions()[0];
        $this->assertSame(RuntimeException::class, $exception->getType());
        $this->assertSame('', $exception->getValue());
        foreach ($exception->getStacktrace()->getFrames() as $frame) {
            $this->assertSame([], $frame->getVars());
        }
    }

    public function test_without_a_dsn_the_failure_is_logged_and_nothing_leaves(): void
    {
        SentrySdk::setCurrentHub(new Hub);

        SafeLog::failure('Podcast', new RuntimeException('x'));

        $this->assertNull(SentrySdk::getCurrentHub()->getClient());
        $this->assertNull(config('sentry.dsn'));
    }

    protected function tearDown(): void
    {
        SentrySdk::setCurrentHub(new Hub);
        parent::tearDown();
    }
}
