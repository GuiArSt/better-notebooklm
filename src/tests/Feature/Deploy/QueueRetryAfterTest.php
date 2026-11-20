<?php

namespace Tests\Feature\Deploy;

use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\TestCase;

/** A queued job still running when retry_after passes is run a second time by another worker: retry_after must outlast every job's own timeout. */
class QueueRetryAfterTest extends TestCase
{
    public function test_retry_after_is_longer_than_every_jobs_timeout_and_the_workers(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $longest = 0;
        $jobs = 0;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen(app_path()) + 1));
            if (! class_exists($class) || ! is_subclass_of($class, ShouldQueue::class)) {
                continue;
            }
            $jobs++;
            $timeout = (new \ReflectionClass($class))->getDefaultProperties()['timeout'] ?? 60;
            $longest = max($longest, (int) $timeout);
            $this->assertGreaterThan((int) $timeout, $retryAfter, "$class runs up to $timeout s; retry_after is $retryAfter");
        }

        $this->assertGreaterThanOrEqual(4, $jobs, 'the scan found the app\'s jobs');
        $this->assertGreaterThanOrEqual(900, $longest, 'sanity: the slowest job is the 900 s source embedding');

        // The worker's own limit, as the demo compose starts it.
        preg_match('/--timeout=(\d+)/', (string) file_get_contents(base_path('../deploy/compose.demo.yaml')), $m);
        $this->assertGreaterThan((int) $m[1], $retryAfter, 'retry_after must outlast the worker --timeout too');
    }
}
