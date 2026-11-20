<?php

namespace Tests\Feature\Deploy;

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** The box's disk must never fill with container logs: every service of the production compose caps its own (json-file, 10 MB x 3), demo override included. */
class LogCapTest extends TestCase
{
    private const CAP = ['driver' => 'json-file', 'options' => ['max-size' => '10m', 'max-file' => '3']];

    public function test_every_service_in_compose_yaml_names_the_log_cap(): void
    {
        $compose = Yaml::parse((string) file_get_contents(base_path('../deploy/compose.yaml')));

        $this->assertNotEmpty($compose['services']);
        foreach ($compose['services'] as $name => $service) {
            $this->assertEquals(self::CAP, $service['logging'] ?? null, "service `$name` has no log cap");
        }
    }

    public function test_the_demo_override_does_not_replace_or_remove_it(): void
    {
        $this->assertStringNotContainsString('logging', (string) file_get_contents(base_path('../deploy/compose.demo.yaml')), 'the demo override must inherit the cap');
    }

    public function test_the_rendered_config_carries_it_on_every_service_alone_and_with_the_demo_override(): void
    {
        $docker = (new Process(['docker', 'compose', 'version']))->setTimeout(20);
        try {
            $docker->run();
        } catch (\Throwable) {
            $this->markTestSkipped('docker is not available here');
        }
        if (! $docker->isSuccessful()) {
            $this->markTestSkipped('docker compose is not available here');
        }

        // compose.yaml names `env_file: .env`, which is untracked and absent on a clean checkout (and never read here): the
        // two files are copied to a temp folder next to an empty .env, and the variables it requires are given inline.
        $dir = sys_get_temp_dir().'/logcap-'.bin2hex(random_bytes(6));
        mkdir($dir);
        try {
            foreach (['compose.yaml', 'compose.demo.yaml'] as $file) {
                copy(base_path('../deploy/'.$file), "$dir/$file");
            }
            touch("$dir/.env");
            $env = ['IMAGE_TAG' => 'x', 'DB_PASSWORD' => 'x', 'CLOUDFLARE_TUNNEL_TOKEN' => 'x'];

            foreach ([['-f', 'compose.yaml', '--profile', 'tunnel'], ['-f', 'compose.yaml', '-f', 'compose.demo.yaml']] as $args) {
                $p = new Process(['docker', 'compose', ...$args, 'config', '--format', 'json'], $dir, $env);
                $p->setTimeout(60)->run();
                $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());

                foreach (json_decode($p->getOutput(), true)['services'] as $name => $service) {
                    $this->assertEquals(self::CAP, $service['logging'] ?? null, "service `$name` (".implode(' ', $args).') has no log cap');
                }
            }
        } finally {
            foreach (['compose.yaml', 'compose.demo.yaml', '.env'] as $file) {
                @unlink("$dir/$file");
            }
            @rmdir($dir);
        }
    }
}
