<?php

namespace Tests\Feature;

use App\Ai\Agents\RouterAgent;
use App\Ai\Ledger;
use App\Ai\Router;
use App\Models\UsageEvent;
use App\Models\User;
use App\Studio\StudioGenerator;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A pro user whose pro_until has passed is free: the free cap, the free model, Mistral's voice (R2A-3). */
class LapsedProTest extends TestCase
{
    use RefreshDatabase;

    private function lapsed(): User
    {
        return User::factory()->create(['tier' => 'pro', 'pro_until' => now()->subDay()]);
    }

    private function current(): User
    {
        return User::factory()->create(['tier' => 'pro', 'pro_until' => now()->addWeek()]);
    }

    public function test_the_ledger_gives_a_lapsed_pro_user_the_free_cap(): void
    {
        $ledger = app(Ledger::class);

        $this->assertSame((int) config('ledger.user_caps.free'), $ledger->capFor($this->lapsed()));
        $this->assertSame((int) config('ledger.user_caps.pro'), $ledger->capFor($this->current()));
    }

    public function test_the_router_gives_a_lapsed_pro_user_the_free_model(): void
    {
        Langfuse::fake();
        RouterAgent::fake(fn () => 'ok');

        app(Router::class)->text($this->lapsed(), 'helper.title', [], 'lapsed', kind: 'chat');
        app(Router::class)->text($this->current(), 'helper.title', [], 'current', kind: 'chat');

        $this->assertSame(config('models.chat.free.model'), UsageEvent::orderBy('id')->first()->model);
        $this->assertSame(config('models.chat.pro.model'), UsageEvent::orderBy('id')->skip(1)->first()->model);
    }

    public function test_a_lapsed_pro_user_does_not_hear_elevenlabs(): void
    {
        config(['elevenlabs.key' => 'xi-test']);
        $generator = app(StudioGenerator::class);

        $this->assertFalse($generator->usesElevenLabs($this->lapsed()));
        $this->assertTrue($generator->usesElevenLabs($this->current()));
    }
}
