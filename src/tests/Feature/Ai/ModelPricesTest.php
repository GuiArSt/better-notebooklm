<?php

namespace Tests\Feature\Ai;

use App\Ai\Pricing;
use App\Ai\Router;
use App\Models\User;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Image;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\ImageUsage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\ImageResponse;
use Tests\TestCase;

/** D-049: Mistral Large for free-tier slides, and the rows its price and the 2.6 image model need. */
class ModelPricesTest extends TestCase
{
    use RefreshDatabase;

    public function test_mistral_large_is_priced_at_70_cents_in_and_2_10_out_per_million(): void
    {
        $rate = app(Pricing::class)->rate('mistral-large-latest');

        $this->assertSame(['input' => 700_000, 'output' => 2_100_000], $rate);
        $this->assertSame(2_800_000, app(Pricing::class)->cost('mistral-large-latest', 1_000_000, 1_000_000));
    }

    public function test_eleven_v4_is_priced_like_v3_so_a_v4_call_never_falls_back_to_the_default_speech_rate(): void
    {
        $speech = config('pricing.speech');

        $this->assertSame(80_000_000, $speech['eleven_v4']);
        $this->assertSame($speech['eleven_v3'], $speech['eleven_v4']);
        $this->assertNotSame(config('pricing.speech_default'), $speech['eleven_v4'], 'not the default rate');
    }

    public function test_free_slides_use_mistral_large_and_chat_stays_on_small(): void
    {
        $this->assertSame('mistral', config('models.slides.free.provider'));
        $this->assertSame('mistral-large-latest', config('models.slides.free.model'));
        $this->assertSame('mistral-small-latest', config('models.chat.free.model'), 'only slides changed');
        $this->assertSame('claude-sonnet-5-5', config('models.slides.pro.model'));
    }

    public function test_the_icons_and_infographics_default_to_the_26_image_model_and_the_env_still_overrides_it(): void
    {
        $this->assertSame('gpt-image-2.6-flare', config('images.model'));
        $this->assertNotNull(config('pricing.images')[config('images.model')]['low']['1024x1024'], 'the default model is priced');
        $this->assertStringContainsString("env('IMAGE_MODEL')", (string) file_get_contents(config_path('images.php')));
    }

    public function test_the_26_image_model_has_the_same_rows_as_25_and_can_be_used(): void
    {
        $rows = config('pricing.images');
        $this->assertSame($rows['gpt-image-2.5-flare'], $rows['gpt-image-2.6-flare']);

        Langfuse::fake();
        $png = (function () {
            $im = imagecreatetruecolor(8, 8);
            ob_start();
            imagepng($im);

            return (string) ob_get_clean();
        })();
        Image::fake([fn () => new ImageResponse(collect([new GeneratedImage(base64_encode($png), 'image/png')]), new ImageUsage, new Meta('openai', 'm'))]);

        app(Router::class)->image(User::factory()->create(), 'x', null, 'infographic', '1536x1024', 'medium', null, 'gpt-image-2.6-flare');

        Image::assertGenerated(fn ($p) => $p->model === 'gpt-image-2.6-flare');
    }
}
