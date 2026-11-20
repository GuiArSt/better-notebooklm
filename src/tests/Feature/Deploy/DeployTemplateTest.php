<?php

namespace Tests\Feature\Deploy;

use App\Models\Invitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Argus D-3 and D-022: the production template must hold what the app needs; invitations are handed out by the owner, not mailed. */
class DeployTemplateTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> the names and values written in deploy/.env.example */
    private function template(): array
    {
        $values = [];
        foreach (file(base_path('../deploy/.env.example'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line[0] !== '#' && str_contains($line, '=')) {
                [$name, $value] = explode('=', $line, 2);
                $values[$name] = trim($value, "\"'");
            }
        }

        return $values;
    }

    public function test_the_template_sends_no_mail_and_names_the_rest_of_what_the_app_needs(): void
    {
        $t = $this->template();

        // D-022: no mail service. The mailer is `log`; there is nothing of a mail provider left in the template.
        $this->assertSame('log', $t['MAIL_MAILER']);
        foreach (['MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'RESEND_API_KEY'] as $name) {
            $this->assertArrayNotHasKey($name, $t, "$name has no place in the template any more");
        }
        $this->assertStringNotContainsStringIgnoringCase('resend', (string) file_get_contents(base_path('../deploy/.env.example')));

        foreach (['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_REDIRECT_URI', 'IMAGE_TAG', 'CLOUDFLARE_TUNNEL_TOKEN', 'ELEVENLABS_API_KEY'] as $name) {
            $this->assertArrayHasKey($name, $t, "$name is missing from deploy/.env.example");
        }
    }

    public function test_an_invitation_is_made_with_the_templates_mailer_and_its_link_is_printed_for_the_owner_to_send(): void
    {
        config(['mail.default' => $this->template()['MAIL_MAILER']]);
        $this->assertSame('log', config('mail.default'));

        $this->artisan('invite', ['email' => 'tester@example.test', 'role' => 'reviewer'])
            ->expectsOutputToContain('Send them this link')
            ->expectsOutputToContain('/invitation/')
            ->assertSuccessful();

        $this->assertSame(1, Invitation::where('email', 'tester@example.test')->count());
    }

    public function test_the_readme_has_no_mail_provider_step_and_tells_the_owner_how_to_invite(): void
    {
        $readme = (string) file_get_contents(base_path('../deploy/README-demo.md'));

        $this->assertStringNotContainsStringIgnoringCase('resend', $readme);
        $this->assertStringNotContainsString('MAIL_PASSWORD', $readme);
        $this->assertStringContainsString('php artisan invite <email> reviewer', $readme);
        $this->assertStringContainsString('prints the link', $readme);
    }

    public function test_an_empty_elevenlabs_voice_in_the_environment_means_the_default_not_nothing(): void
    {
        foreach (['ELEVENLABS_VOICE_A', 'ELEVENLABS_VOICE_B', 'ELEVENLABS_MODEL'] as $name) {
            putenv("$name=");
            $_ENV[$name] = $_SERVER[$name] = '';
        }

        try {
            $config = require config_path('elevenlabs.php');
        } finally {
            foreach (['ELEVENLABS_VOICE_A', 'ELEVENLABS_VOICE_B', 'ELEVENLABS_MODEL'] as $name) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            }
        }

        $this->assertSame('XrExE9yKIg1WjnnlVkGX', $config['voices']['a']);
        $this->assertSame('iP95p4xoKVk53GoZ742B', $config['voices']['b']);
        $this->assertSame('eleven_v4', $config['model']);
    }

    public function test_the_image_context_leaves_out_dev_data_and_secrets_but_keeps_what_the_build_needs(): void
    {
        $ignore = file_get_contents(base_path('../deploy/Dockerfile.dockerignore'));

        foreach (['src/storage/app', 'src/database/*.sqlite', 'src/tests', 'src/.phpunit.result.cache', 'src/.env', 'src/node_modules'] as $path) {
            $this->assertStringContainsString($path, $ignore, "$path must stay out of the image");
        }
        $this->assertStringContainsString('!src/.env.example', $ignore, 'the build copies .env.example to run artisan');
    }

    public function test_the_database_takes_its_password_from_db_password_when_postgres_password_is_not_set(): void
    {
        // Found when bringing dev up: an env file written for the app has DB_*; the database container must not demand a second copy.
        $compose = (string) file_get_contents(base_path('../deploy/compose.yaml'));

        $this->assertStringContainsString('POSTGRES_PASSWORD: ${POSTGRES_PASSWORD:-${DB_PASSWORD:?', $compose);
        $this->assertStringContainsString('POSTGRES_USER: ${POSTGRES_USER:-${DB_USERNAME:-app}}', $compose);
        $this->assertStringContainsString('POSTGRES_DB: ${POSTGRES_DB:-${DB_DATABASE:-application_test}}', $compose);
    }

    /** Found by the demo seed: a root worker wrote the audio into a private directory the web process (www-data) could not read: 404. */
    public function test_the_workers_run_as_www_data_like_the_web_process_so_the_audio_they_write_can_be_served(): void
    {
        $compose = (string) file_get_contents(base_path('../deploy/compose.yaml'));

        foreach (['worker-default', 'worker-media', 'scheduler'] as $service) {
            $this->assertMatchesRegularExpression('/^  '.$service.':\n    <<: \*app\n    user: www-data/m', $compose, "$service must run as www-data");
        }
        $this->assertStringContainsString('chown -R www-data:www-data storage', (string) file_get_contents(base_path('../deploy/entrypoint.sh')));
    }
}
