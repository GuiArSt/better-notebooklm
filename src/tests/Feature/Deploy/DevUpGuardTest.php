<?php

namespace Tests\Feature\Deploy;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** D-050, in code: deploy/dev-up.sh refuses to restart dev while something is in flight. Run with fake counts, so no Docker is touched. */
class DevUpGuardTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/devup-guard-'.bin2hex(random_bytes(4));
        mkdir($this->home);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->home.'/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->home);
        parent::tearDown();
    }

    /** @param  list<string>  $args */
    private function run_(string $fake, array $args = ['--check-only']): ProcessResult
    {
        return Process::env(['DEV_UP_FAKE_INFLIGHT' => $fake, 'DEV_HOME' => $this->home])
            ->run(['bash', base_path('../deploy/dev-up.sh'), ...$args]);
    }

    public function test_it_is_clear_when_nothing_is_in_flight(): void
    {
        $result = $this->run_('0|0|0||');

        $this->assertSame(0, $result->exitCode());
        $this->assertStringContainsString('Clear', $result->output());
    }

    public function test_a_job_in_the_queue_refuses_and_says_what_is_in_flight(): void
    {
        $result = $this->run_('2|0|0||');

        $this->assertSame(3, $result->exitCode());
        $this->assertStringContainsString('REFUSED', $result->errorOutput());
        $this->assertStringContainsString('jobs in the queue (waiting or reserved): 2', $result->errorOutput());
    }

    public function test_an_open_ledger_reservation_refuses(): void
    {
        $result = $this->run_('0|1|0||');

        $this->assertSame(3, $result->exitCode());
        $this->assertStringContainsString('open ledger reservations (a paid call started and not settled): 1', $result->errorOutput());
    }

    public function test_a_ledger_row_in_the_last_two_minutes_refuses(): void
    {
        $result = $this->run_('0|0|3||');

        $this->assertSame(3, $result->exitCode());
        $this->assertStringContainsString('ledger rows written in the last 2 minutes: 3', $result->errorOutput());
    }

    public function test_running_items_and_pending_sources_are_a_warning_and_never_a_refusal(): void
    {
        $result = $this->run_('0|0|0|107 110 145|55 56');

        $this->assertSame(0, $result->exitCode(), 'orphans must not block a restart');
        $this->assertStringContainsString('WARNING (not a refusal): studio items still pending or running: 107 110 145', $result->errorOutput());
        $this->assertStringContainsString('sources still pending: 55 56', $result->errorOutput());
    }

    public function test_force_needs_a_reason_and_a_reason_lifts_the_refusal_and_is_recorded(): void
    {
        $this->assertSame(2, $this->run_('1|0|0||', ['--force', '--check-only'])->exitCode(), 'a bare --force is a usage error');
        $this->assertSame(2, $this->run_('1|0|0||', ['--force'])->exitCode());

        $forced = $this->run_('1|1|1|9|', ['--force', 'the stack is wedged and Tycho said go', '--check-only']);

        $this->assertSame(0, $forced->exitCode());
        $this->assertStringContainsString('FORCED past the guard. Reason: the stack is wedged and Tycho said go', $forced->errorOutput());
        $this->assertStringContainsString('REFUSED', $forced->errorOutput(), 'what was overridden is still printed');
        $log = (string) file_get_contents($this->home.'/force.log');
        $this->assertStringContainsString('the stack is wedged and Tycho said go', $log);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\t/', $log);
    }

    public function test_an_unknown_option_is_refused_and_the_script_is_valid_bash(): void
    {
        $this->assertSame(2, $this->run_('0|0|0||', ['--yolo'])->exitCode());
        $this->assertSame(0, Process::run(['bash', '-n', base_path('../deploy/dev-up.sh')])->exitCode());
    }

    public function test_the_guard_runs_after_the_build_and_just_before_the_stack_is_started(): void
    {
        $script = (string) file_get_contents(base_path('../deploy/dev-up.sh'));

        $build = strpos($script, '"${compose[@]}" build app worker-default');
        $guard = strpos($script, "run_guard || exit \$?\n\necho \"Starting");
        $up = strpos($script, '"${compose[@]}" up -d');

        $this->assertNotFalse($build);
        $this->assertNotFalse($guard);
        $this->assertLessThan($guard, $build, 'asked after the build, not before it');
        $this->assertLessThan($up, $guard, 'and before anything is started or stopped');
    }

    public function test_the_test_hook_says_so_out_loud_when_it_is_used(): void
    {
        $result = $this->run_('0|0|0||');

        $this->assertSame(0, $result->exitCode());
        $this->assertStringContainsString('guard skipped: test hook', $result->errorOutput());
        $this->assertStringContainsString('the real stack was NOT asked', $result->errorOutput());
    }

    public function test_the_test_hook_is_refused_in_a_real_deploy_so_it_can_never_let_a_restart_through(): void
    {
        // No --check-only: a real deploy. It must stop before it builds, starts or stops anything.
        $result = $this->run_('0|0|0||', []);

        $this->assertSame(2, $result->exitCode());
        $this->assertStringContainsString('only allowed with --check-only', $result->errorOutput());
        $this->assertStringNotContainsString('Building', $result->output());
        $this->assertStringNotContainsString('Starting', $result->output());
    }

    public function test_with_a_ref_and_the_hook_it_is_still_refused_outside_check_only(): void
    {
        $this->assertSame(2, $this->run_('0|0|0||', ['HEAD'])->exitCode());
        $this->assertSame(2, $this->run_('0|0|0||', ['--force', 'because', 'HEAD'])->exitCode(), 'not even --force makes the hook acceptable in a deploy');
    }
}
