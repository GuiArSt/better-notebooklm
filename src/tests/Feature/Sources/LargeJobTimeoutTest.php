<?php

namespace Tests\Feature\Sources;

use App\Sources\BuildOverview;
use App\Sources\EmbedSource;
use Tests\TestCase;

/** A job that makes slow model calls must be allowed to finish, and must end before the queue would hand it to a second worker (retry_after). */
class LargeJobTimeoutTest extends TestCase
{
    public function test_the_overview_job_has_room_for_a_slow_call_and_its_language_retry(): void
    {
        // One AI call may take 300 s, and a wrong language asks once more.
        $this->assertSame(600, (new BuildOverview(1))->timeout);
    }

    public function test_every_source_job_ends_before_the_queue_would_hand_it_out_again(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');

        foreach ([new BuildOverview(1), new EmbedSource(1)] as $job) {
            $this->assertLessThan($retryAfter, $job->timeout, $job::class.' must time out before retry_after, or it is delivered twice');
        }
    }
}
