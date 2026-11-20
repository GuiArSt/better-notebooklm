<?php

namespace Tests\Feature;

use App\Models\Notebook;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * R1-1: a client that hangs up mid-answer must still be charged for what was streamed, and must leave no hold.
 * This is a real abort: a real `php -S` server, a real socket closed by the client, the real controller and kernel.
 * (A test that `unset`s the generator proves nothing about this: PHP stops a script whose client has gone.)
 */
class StreamAbortTest extends TestCase
{
    use RefreshDatabase;

    private ?User $user = null;

    /** The server is a separate process: it cannot see a transaction that is never committed. */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        $this->user?->delete(); // cascades to the notebook, source, passages, usage events and holds
        parent::tearDown();
    }

    public function test_a_client_that_hangs_up_mid_answer_is_still_charged_and_leaves_no_hold(): void
    {
        $this->user = User::factory()->create();
        $notebook = Notebook::create(['user_id' => $this->user->id, 'title' => 'T']);
        $source = $notebook->sources()->create(['title' => 'S', 'content' => 'A fact worth asking about.', 'status' => 'ready']);
        $source->passages()->create(['notebook_id' => $notebook->id, 'content' => 'A fact worth asking about.', 'embedding' => array_fill(0, (int) config('embeddings.dimensions'), 0.1)]);

        $port = random_int(20_000, 40_000);
        $server = Process::env($this->childEnv())->start(['php', '-S', "127.0.0.1:$port", __DIR__.'/../Support/stream_abort_server.php']);

        try {
            $socket = $this->connect($port);
            fwrite($socket, "GET /?u={$this->user->id}&n={$notebook->id} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
            $received = '';
            while (strlen($received) < 2_000 && ! feof($socket)) {
                $received .= (string) fread($socket, 1_024);
            }
            $this->assertStringContainsString('data: ', $received, 'the answer started streaming');
            fclose($socket); // the client hangs up

            $event = $this->waitFor(fn () => UsageEvent::where('user_id', $this->user->id)->where('kind', 'chat')->first());
        } finally {
            $server->stop();
        }

        $this->assertNotNull($event, 'the abandoned call was settled into the ledger');
        $this->assertGreaterThan(0, $event->cost_micro_usd);
        $this->assertGreaterThan(0, $event->units['output_tokens']);
        $this->assertSame(0, DB::table('ledger_reservations')->where('user_id', $this->user->id)->count(), 'no hold is left for the sweep to forgive');
    }

    /** @return resource */
    private function connect(int $port)
    {
        for ($i = 0; $i < 50; $i++) {
            $socket = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $error, 1);
            if ($socket !== false) {
                return $socket;
            }
            usleep(100_000);
        }
        $this->fail('the test server did not start');
    }

    private function waitFor(callable $probe): mixed
    {
        for ($i = 0; $i < 100; $i++) {
            if (($value = $probe()) !== null) {
                return $value;
            }
            usleep(100_000);
        }

        return null;
    }

    /** @return array<string, string> */
    private function childEnv(): array
    {
        return [
            'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) config('database.connections.pgsql.host'),
            'DB_PORT' => (string) config('database.connections.pgsql.port'), 'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
            'DB_USERNAME' => (string) config('database.connections.pgsql.username'), 'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BCRYPT_ROUNDS' => '4',
            'PHP_CLI_SERVER_WORKERS' => '1',
        ];
    }
}
