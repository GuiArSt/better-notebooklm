<?php

namespace Tests\Feature\Deploy;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** D-055: dev-up.sh cleans up after a successful deploy. The tag selection is tested here with fake image lists (no Docker, nothing deleted). */
class DevUpPruneTest extends TestCase
{
    /**
     * @param  list<string>  $refs  newest first, as `docker images` lists them
     * @param  list<string>  $inUse
     * @return list<string> what would be removed
     */
    private function select(array $refs, array $inUse = []): array
    {
        $result = Process::env(['DEV_UP_IN_USE' => implode(' ', $inUse)])->input(implode("\n", $refs)."\n")
            ->run(['bash', base_path('../deploy/dev-up.sh'), '--select-prune']);

        $this->assertSame(0, $result->exitCode(), $result->errorOutput());

        return array_values(array_filter(explode("\n", trim($result->output()))));
    }

    /** @return list<string> a pair of refs per tag */
    private function pairs(string ...$tags): array
    {
        return array_merge(...array_map(fn ($t) => ["application-test/app:$t", "application-test/media:$t"], $tags));
    }

    public function test_it_keeps_the_newest_three_tags_for_both_images_and_removes_the_older_ones(): void
    {
        $remove = $this->select($this->pairs('dev-e', 'dev-d', 'dev-c', 'dev-b', 'dev-a'));

        $this->assertEqualsCanonicalizing(['application-test/app:dev-b', 'application-test/media:dev-b', 'application-test/app:dev-a', 'application-test/media:dev-a'], $remove);
    }

    public function test_fewer_than_four_tags_removes_nothing(): void
    {
        $this->assertSame([], $this->select($this->pairs('dev-c', 'dev-b', 'dev-a')));
        $this->assertSame([], $this->select([]));
    }

    public function test_an_image_a_container_still_uses_is_never_removed_even_when_old(): void
    {
        $remove = $this->select($this->pairs('dev-e', 'dev-d', 'dev-c', 'dev-b', 'dev-a'), ['application-test/app:dev-a', 'application-test/media:dev-b']);

        $this->assertEqualsCanonicalizing(['application-test/media:dev-a', 'application-test/app:dev-b'], $remove);
    }

    public function test_087b607_and_0732205_are_never_removed_under_any_prefix_and_do_not_count_as_a_kept_tag(): void
    {
        $refs = array_merge(
            $this->pairs('dev-d', 'dev-c', 'dev-b'),
            ['application-test/app:087b607', 'application-test/media:0732205', 'application-test/app:dev-087b607', 'application-test/media:dev-0732205'],
            $this->pairs('dev-a'),
        );

        $remove = $this->select($refs);

        $this->assertEqualsCanonicalizing(['application-test/app:dev-a', 'application-test/media:dev-a'], $remove, 'the protected tags neither go nor use up one of the three');
    }

    public function test_only_the_two_application_images_with_a_dev_prefix_are_ever_candidates(): void
    {
        $refs = array_merge(
            $this->pairs('dev-d', 'dev-c', 'dev-b', 'dev-a'),
            ['pgvector/pgvector:pg17', 'cloudflare/cloudflared:2026.11.0', 'atlas-vendor:latest', 'application-test/other:dev-a', 'application-test/app:latest', 'application-test/app:v1', 'gotenberg/gotenberg:<none>'],
        );

        $remove = $this->select($refs);

        $this->assertEqualsCanonicalizing(['application-test/app:dev-a', 'application-test/media:dev-a'], $remove);
    }

    public function test_a_tag_with_only_one_of_the_two_images_still_counts_as_one_tag(): void
    {
        $remove = $this->select(['application-test/app:dev-d', 'application-test/app:dev-c', 'application-test/media:dev-c', 'application-test/app:dev-b', 'application-test/app:dev-a', 'application-test/media:dev-a']);

        $this->assertEqualsCanonicalizing(['application-test/app:dev-a', 'application-test/media:dev-a'], $remove);
    }

    public function test_the_cleanup_runs_only_after_a_successful_deploy_and_can_be_skipped(): void
    {
        $script = (string) file_get_contents(base_path('../deploy/dev-up.sh'));

        $up = strpos($script, 'Dev is up: images');
        $cleanup = strrpos($script, 'docker builder prune -f'); // the call at the end, not the mention in the header
        $migrate = strrpos($script, 'php artisan migrate --force');

        $this->assertNotFalse($cleanup);
        $this->assertLessThan($cleanup, $migrate, 'after the migrations, so after /up answered');
        $this->assertLessThan($cleanup, $up, 'after the deploy is announced');
        $this->assertStringContainsString('--no-prune', $script);
        $this->assertStringContainsString('Never fails the deploy', $script);
        $this->assertSame(0, Process::run(['bash', '-n', base_path('../deploy/dev-up.sh')])->exitCode());
    }
}
