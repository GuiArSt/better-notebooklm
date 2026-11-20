<?php

namespace Tests\Feature\Studio;

use App\Ai\Language;
use App\Studio\Podcast;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Release 3 (the owner, listening on prod): the two hosts are never two voices of one gender, on every path, format and language. */
class PodcastHostVoicesTest extends TestCase
{
    /** @return array<string, array{string, string, string|null, string}> */
    public static function everyCombination(): array
    {
        $cases = [];
        foreach (['elevenlabs', 'mistral'] as $provider) {
            foreach ([...array_keys(Language::NAMES), 'auto'] as $language) {
                foreach ([null, 'dez', 'alistair', 'varga', 'rosa'] as $persona) {
                    foreach (['deep_dive', 'brief', 'critique', 'debate'] as $format) {
                        $cases["$provider $language ".($persona ?? 'no persona')." $format"] = [$provider, $language, $persona, $format];
                    }
                }
            }
        }

        return $cases;
    }

    /** @dataProvider everyCombination */
    #[DataProvider('everyCombination')]
    public function test_every_format_and_language_has_one_female_and_one_male_host(string $provider, string $language, ?string $persona, string $format): void
    {
        $options = ['format' => $format] + ($persona === null ? [] : ['persona' => $persona]);

        $hosts = Podcast::hosts($provider, ['language' => $language, 'options' => $options]);

        $this->assertEqualsCanonicalizing(['female', 'male'], [$hosts['A']['gender'], $hosts['B']['gender']]);
        $this->assertNotSame($hosts['A']['voice'], $hosts['B']['voice']);
        $this->assertNotSame('', $hosts['A']['voice']);
    }

    public function test_without_a_persona_host_a_is_female_and_host_b_male_on_both_paths(): void
    {
        foreach (['elevenlabs' => ['elevenlabs.voices.a', 'elevenlabs.voices.b'], 'mistral' => ['models.voices.a', 'models.voices.b']] as $provider => [$a, $b]) {
            $hosts = Podcast::hosts($provider, []);

            $this->assertSame(['female', config($a)], [$hosts['A']['gender'], $hosts['A']['voice']]);
            $this->assertSame(['male', config($b)], [$hosts['B']['gender'], $hosts['B']['voice']]);
        }
    }

    public function test_a_persona_is_paired_with_the_other_gender_and_its_own_voice_decides(): void
    {
        foreach (['dez' => 'male', 'alistair' => 'male', 'varga' => 'female', 'rosa' => 'female'] as $persona => $gender) {
            $hosts = Podcast::hosts('elevenlabs', ['options' => ['persona' => $persona]]);

            $this->assertSame($gender, config("podcast.personas.$persona.gender"));
            $this->assertSame([$gender, config("elevenlabs.persona_voices.$persona")], [$hosts['B']['gender'], $hosts['B']['voice']]);
            $this->assertSame($gender === 'male' ? 'female' : 'male', $hosts['A']['gender']);
            $this->assertSame(config($gender === 'male' ? 'elevenlabs.voices.a' : 'elevenlabs.voices.b'), $hosts['A']['voice'], 'host A is the pool voice of the other gender');
        }
    }

    public function test_the_two_women_of_the_old_pairing_are_gone(): void
    {
        // Rosa and Varga are women: host A was Matilda, a woman. Now host A is the pool's man.
        foreach (['rosa', 'varga'] as $persona) {
            $this->assertSame(config('elevenlabs.voices.b'), Podcast::voices('elevenlabs', ['options' => ['persona' => $persona]])['A']);
        }
    }

    public function test_a_persona_on_mistral_keeps_its_gender_and_the_other_host_the_other(): void
    {
        $hosts = Podcast::hosts('mistral', ['options' => ['persona' => 'rosa']]);

        $this->assertSame(['male', 'female'], [$hosts['A']['gender'], $hosts['B']['gender']]);
        $this->assertSame([config('models.voices.b'), config('models.voices.a')], [$hosts['A']['voice'], $hosts['B']['voice']]);
    }

    public function test_a_language_may_have_its_own_pair_and_a_missing_gender_falls_back_to_the_default_one(): void
    {
        config(['podcast.host_voices.elevenlabs.fr' => ['female' => 'fr-woman', 'male' => 'fr-man']]);
        $this->assertSame(['A' => 'fr-woman', 'B' => 'fr-man'], Podcast::voices('elevenlabs', ['language' => 'fr']));
        $this->assertSame(['A' => config('elevenlabs.voices.a'), 'B' => config('elevenlabs.voices.b')], Podcast::voices('elevenlabs', ['language' => 'de']));

        config(['podcast.host_voices.elevenlabs.fr' => ['female' => 'fr-woman', 'male' => '']]);
        $this->assertSame(config('elevenlabs.voices.b'), Podcast::voices('elevenlabs', ['language' => 'fr'])['B']);
    }

    public function test_a_pool_without_one_gender_fails_loudly(): void
    {
        config(['models.voices.b' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No male host voice for mistral in en');
        Podcast::hosts('mistral', ['language' => 'en']);
    }

    public function test_two_identical_voices_fail_loudly(): void
    {
        config(['elevenlabs.voices.a' => 'same', 'elevenlabs.voices.b' => 'same']);

        $this->expectException(RuntimeException::class);
        Podcast::hosts('elevenlabs', []);
    }

    public function test_the_configured_personas_all_have_a_gender_and_a_voice_that_is_not_a_pool_voice(): void
    {
        foreach (Podcast::personas() as $persona) {
            $this->assertContains(config("podcast.personas.$persona.gender"), ['female', 'male']);
            $this->assertNotContains(config("elevenlabs.persona_voices.$persona"), [config('elevenlabs.voices.a'), config('elevenlabs.voices.b')]);
        }
        $genders = array_column(config('podcast.personas'), 'gender');
        $this->assertContains('female', $genders);
        $this->assertContains('male', $genders);
    }

    public function test_the_speech_block_records_the_provider_model_and_both_voices(): void
    {
        $block = Podcast::speech('elevenlabs', ['options' => ['persona' => 'varga']]);

        $this->assertSame(['provider' => 'elevenlabs', 'model' => config('elevenlabs.model'), 'voices' => ['A' => config('elevenlabs.voices.b'), 'B' => config('elevenlabs.persona_voices.varga')]], $block);
        $this->assertSame(config('models.speech.provider'), Podcast::speech('mistral', [])['provider']);
    }
}
