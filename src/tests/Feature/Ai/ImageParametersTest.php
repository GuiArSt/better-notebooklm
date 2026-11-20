<?php

namespace Tests\Feature\Ai;

use App\Ai\Exceptions\CapExceeded;
use App\Ai\Exceptions\UnpricedModel;
use App\Ai\Router;
use App\Models\UsageEvent;
use App\Models\User;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Image;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\ImageUsage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\ImageResponse;
use Tests\TestCase;

/** D-045: Router::image takes the icon's settings by default and an infographic's own on request; the guards are the same for both. */
class ImageParametersTest extends TestCase
{
    use RefreshDatabase;

    private function png(): string
    {
        $im = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private function fakeImage(): void
    {
        Image::fake([fn () => new ImageResponse(collect([new GeneratedImage(base64_encode($this->png()), 'image/png')]), new ImageUsage, new Meta('openai', 'm'))]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Langfuse::fake();
    }

    public function test_the_defaults_are_the_icons_settings(): void
    {
        $this->fakeImage();
        $user = User::factory()->create();

        app(Router::class)->image($user, 'Sourdough');

        Image::assertGenerated(fn ($p) => $p->size === '1024x1024' && $p->quality === 'low' && str_contains($p->prompt, 'flat line icon') && str_contains($p->prompt, 'Subject: Sourdough'));
        $row = UsageEvent::where('kind', 'image')->sole();
        $this->assertSame(6_000, $row->cost_micro_usd);
        $this->assertStringContainsString('notebook.icon', json_encode(Langfuse::getFacadeRoot()->exported()));
    }

    public function test_an_infographic_passes_its_own_name_size_quality_style_and_model(): void
    {
        $this->fakeImage();
        $user = User::factory()->create();

        app(Router::class)->image($user, 'The secret Orchid plan', 5, 'infographic', '1536x1024', 'medium', 'clean editorial infographic, flat colours');

        Image::assertGenerated(fn ($p) => $p->size === '1536x1024' && $p->quality === 'medium' && str_contains($p->prompt, 'clean editorial infographic') && ! str_contains($p->prompt, 'flat line icon'));
        $row = UsageEvent::where('kind', 'image')->sole();
        $this->assertSame(35_000, $row->cost_micro_usd, 'the medium rectangle is priced in config/pricing.php');
        $this->assertSame(5, $row->notebook_id);
        $trace = json_encode(Langfuse::getFacadeRoot()->exported());
        $this->assertStringContainsString('infographic', $trace);
        $this->assertStringNotContainsString('Orchid', $trace, 'the prompt is never traced');
    }

    public function test_the_other_priced_rows_are_read_from_config(): void
    {
        $prices = ['1024x1024' => ['low' => 6_000, 'medium' => 23_000], '1536x1024' => ['low' => 9_000, 'medium' => 35_000], '1024x1536' => ['low' => 9_000, 'medium' => 35_000]];
        foreach ($prices as $size => $byQuality) {
            foreach ($byQuality as $quality => $cost) {
                $this->assertSame($cost, config('pricing.images')['gpt-image-2.5-flare'][$quality][$size], "$quality $size");
            }
        }
    }

    public function test_an_unpriced_size_quality_or_model_is_refused_before_the_provider_is_called(): void
    {
        Image::fake();
        $user = User::factory()->create();

        foreach ([['1792x1024', 'low', null], ['1024x1024', 'high', null], ['1024x1024', 'low', 'some-other-model']] as [$size, $quality, $model]) {
            try {
                app(Router::class)->image($user, 'x', null, 'infographic', $size, $quality, null, $model);
                $this->fail("$size $quality should have been refused");
            } catch (UnpricedModel) {
                $this->addToAssertionCount(1);
            }
        }
        Image::assertNothingGenerated();
        $this->assertSame(0, UsageEvent::where('kind', 'image')->count());
    }

    public function test_icons_and_infographics_share_one_daily_budget(): void
    {
        $this->fakeImage();
        config(['images.daily_cap_micro_usd' => 40_000]);
        $user = User::factory()->create();

        app(Router::class)->image($user, 'icon'); // 6,000 spent today
        $this->expectException(CapExceeded::class);
        app(Router::class)->image($user, 'big', null, 'infographic', '1536x1024', 'medium'); // 35,000 more would pass 40,000
    }

    public function test_the_default_daily_cap_is_five_dollars(): void
    {
        $this->assertSame(5_000_000, (int) (require config_path('images.php'))['daily_cap_micro_usd']);
    }

    public function test_a_failed_infographic_writes_a_zero_cost_row_naming_only_the_class(): void
    {
        Image::fake([fn () => throw new \RuntimeException('provider said no to the Orchid plan')]);
        $user = User::factory()->create();

        try {
            app(Router::class)->image($user, 'The Orchid plan', 5, 'infographic', '1024x1536', 'low');
            $this->fail('should have thrown');
        } catch (\RuntimeException) {
        }

        $row = UsageEvent::where('kind', 'image')->sole();
        $this->assertSame(0, $row->cost_micro_usd);
        $this->assertSame('RuntimeException', $row->units['error']);
        $this->assertStringNotContainsString('Orchid', json_encode($row->toArray()));
        $this->assertSame(0, \DB::table('ledger_reservations')->count());
    }
}
