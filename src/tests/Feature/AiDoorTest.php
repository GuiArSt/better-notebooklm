<?php

namespace Tests\Feature;

use App\Ai\Agents\RouterAgent;
use App\Ai\Exceptions\CapExceeded;
use App\Ai\Router;
use App\Models\UsageEvent;
use App\Models\User;
use Axyr\Langfuse\Dto\GenerationBody;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

class AiDoorTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['email' => 'secret.person@example.test']);
    }

    public function test_a_call_writes_one_ledger_row_with_a_cost_and_no_hold_is_left(): void
    {
        Langfuse::fake();
        RouterAgent::fake(['Hello there.']);
        $user = $this->user();

        $result = app(Router::class)->text($user, 'helper.title', ['text' => 'a long text'], 'hi', kind: 'helper');

        $this->assertSame('Hello there.', $result->text);
        $this->assertSame(1, UsageEvent::count());
        $event = UsageEvent::first();
        $this->assertSame($user->id, $event->user_id);
        $this->assertSame('mistral', $event->provider);
        $this->assertSame('chat', $event->kind);
        $this->assertSame('app', $event->key_owner);
        $this->assertGreaterThan(0, $event->cost_micro_usd);
        $this->assertSame($result->costMicroUsd, $event->cost_micro_usd);
        $this->assertNotNull($event->langfuse_trace_id);
        $this->assertSame(0, DB::table('ledger_reservations')->count());
    }

    public function test_a_call_over_the_user_cap_is_refused_before_the_provider_is_called(): void
    {
        Langfuse::fake();
        RouterAgent::fake(['never seen']);
        config(['ledger.user_caps.free' => 10]);

        try {
            app(Router::class)->text($this->user(), 'helper.title', ['text' => str_repeat('x', 3000)], 'hi', kind: 'helper');
            $this->fail('expected CapExceeded');
        } catch (CapExceeded $e) {
            $this->assertSame('user', $e->scope);
        }

        RouterAgent::assertNeverPrompted();
        $this->assertSame(0, UsageEvent::count());
        $this->assertSame(0, DB::table('ledger_reservations')->count());
        Langfuse::assertNothingSent();
    }

    public function test_the_global_cap_stops_every_user(): void
    {
        Langfuse::fake();
        RouterAgent::fake(['x']);
        config(['ledger.global_cap' => 5]);

        $this->expectException(CapExceeded::class);
        try {
            app(Router::class)->text($this->user(), 'helper.title', ['text' => 'a'], 'hi', kind: 'helper');
        } catch (CapExceeded $e) {
            $this->assertSame('global', $e->scope);
            RouterAgent::assertNeverPrompted();
            throw $e;
        }
    }

    public function test_a_failed_call_releases_its_hold_and_spends_nothing(): void
    {
        Langfuse::fake();
        RouterAgent::fake(fn () => throw new \RuntimeException('provider down'));

        try {
            app(Router::class)->text($this->user(), 'helper.title', ['text' => 'a'], 'hi', kind: 'helper');
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, UsageEvent::count());
        $this->assertSame(0, DB::table('ledger_reservations')->count());
    }

    public function test_a_call_on_the_users_own_key_is_logged_at_zero_and_ignores_caps(): void
    {
        Langfuse::fake();
        RouterAgent::fake(['ok']);
        config(['ledger.user_caps.free' => 0, 'ledger.global_cap' => 0]);

        app(Router::class)->text($this->user(), 'helper.title', ['text' => 'a'], 'hi', kind: 'helper', keyOwner: 'user');

        $event = UsageEvent::first();
        $this->assertSame(0, $event->cost_micro_usd);
        $this->assertSame('user', $event->key_owner);
    }

    public function test_the_span_carries_model_tokens_prompt_and_user_id_but_no_email_or_key(): void
    {
        $fake = Langfuse::fake();
        config(['ai.providers.mistral.key' => 'sk-test-super-secret-key']);
        RouterAgent::fake(['Hello there.']);
        $user = $this->user();

        app(Router::class)->text($user, 'chat.answer', ['sources' => '[P1] cats'], 'what?', notebookId: 7);

        $fake->assertGenerationEnded('chat.answer');
        $fake->assertObservationHas('chat.answer', function ($observation) use ($user): bool {
            $body = $observation->body;
            if (! $body instanceof GenerationBody) {
                return false;
            }

            return $body->model === config('models.chat.free.model')
                && $body->usage->input > 0
                && $body->metadata['prompt_name'] === 'chat.answer'
                && array_key_exists('prompt_version', $body->metadata)
                && $observation->context->body()->userId === (string) $user->id
                && $observation->context->body()->sessionId === 'notebook-7';
        });

        $payload = json_encode($fake->exported());
        $this->assertStringNotContainsString('secret.person@example.test', $payload);
        $this->assertStringNotContainsString('sk-test-super-secret-key', $payload);
    }

    public function test_embeddings_go_through_the_door_with_the_fixed_model(): void
    {
        Langfuse::fake();
        Embeddings::fake();
        $user = $this->user();

        $out = app(Router::class)->embed($user, ['one', 'two']);

        $this->assertCount(2, $out['vectors']);
        $event = UsageEvent::first();
        $this->assertSame('embed', $event->kind);
        $this->assertSame(config('models.embedding.model'), $event->model);
        $this->assertSame('app', $event->key_owner);
    }

    public function test_the_embedding_dimension_in_config_matches_a_real_vector(): void
    {
        // A real mistral-embed vector is 1024 numbers (measured live; see the step-2 report).
        // This pins the migration's column to the configured dimension.
        $this->assertSame(1024, config('embeddings.dimensions'));
        $type = DB::selectOne("select format_type(atttypid, atttypmod) as t from pg_attribute where attrelid = 'passages'::regclass and attname = 'embedding'");
        $this->assertSame('vector('.config('embeddings.dimensions').')', $type->t);
    }

    public function test_usage_events_are_append_only(): void
    {
        Langfuse::fake();
        RouterAgent::fake(['ok']);
        app(Router::class)->text($this->user(), 'helper.title', ['text' => 'a'], 'hi', kind: 'helper');

        $this->expectException(\LogicException::class);
        UsageEvent::first()->update(['cost_micro_usd' => 0]);
    }

    public function test_tracing_fails_open_when_langfuse_throws(): void
    {
        Langfuse::shouldReceive('trace')->andThrow(new \RuntimeException('langfuse down'));
        Langfuse::shouldReceive('prompt')->andThrow(new \RuntimeException('langfuse down'));
        RouterAgent::fake(['Still answered.']);
        $user = $this->user();

        $result = app(Router::class)->text($user, 'helper.title', ['text' => 'a'], 'hi', kind: 'helper');

        $this->assertSame('Still answered.', $result->text);
        $this->assertNull($result->traceId);
        $this->assertSame(1, UsageEvent::count());
        $this->assertSame(0, DB::table('ledger_reservations')->count());
    }

    public function test_the_sweep_deletes_stale_holds_and_keeps_fresh_ones(): void
    {
        $user = $this->user();
        DB::table('ledger_reservations')->insert([
            ['user_id' => $user->id, 'amount_micro_usd' => 100, 'created_at' => now()->subMinutes(20)],
            ['user_id' => $user->id, 'amount_micro_usd' => 100, 'created_at' => now()->subMinutes(2)],
        ]);

        $this->artisan('ledger:sweep')->assertSuccessful();

        $this->assertSame(1, DB::table('ledger_reservations')->count());
        $this->artisan('schedule:list')->expectsOutputToContain('ledger:sweep');
    }

    public function test_a_stream_is_traced_and_settled_and_a_dropped_reader_leaves_no_hold(): void
    {
        Langfuse::fake();
        RouterAgent::fake(['one two three four']);
        $user = $this->user();

        $stream = app(Router::class)->stream($user, 'helper.title', ['text' => 'a'], 'hi', kind: 'helper');
        $text = '';
        foreach ($stream as $delta) {
            $text .= $delta;
        }
        $this->assertSame('one two three four', $text);
        $this->assertSame('one two three four', $stream->getReturn()->text);
        $this->assertSame(1, UsageEvent::count());
        $this->assertSame(0, DB::table('ledger_reservations')->count());

        // The reader goes away after the first piece.
        RouterAgent::fake(['five six seven']);
        $dropped = app(Router::class)->stream($user, 'helper.title', ['text' => 'a'], 'hi', kind: 'helper');
        $dropped->current();
        $this->assertSame(1, DB::table('ledger_reservations')->count());
        unset($dropped);
        gc_collect_cycles();

        $this->assertSame(2, UsageEvent::count());
        $this->assertSame(0, DB::table('ledger_reservations')->count());
    }
}
