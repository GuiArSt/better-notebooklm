<?php

namespace Tests\Feature\Auth;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/** scripts/users.sh: prod is the owner's, from a terminal only; plain arguments only. A stub `ssh` stands in for the box. */
class UsersScriptTest extends TestCase
{
    private string $script;

    private string $bin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->script = base_path('../scripts/users.sh');
        if (! is_file($this->script)) {
            $this->markTestSkipped('scripts/ is not in this tree (the image).');
        }
        $this->bin = sys_get_temp_dir().'/bnlm-stub-'.bin2hex(random_bytes(4));
        mkdir($this->bin);
        // The stub ssh: the master connection (-fNM) just succeeds; the command is echoed.
        file_put_contents($this->bin.'/ssh', "#!/bin/sh\ncase \"\$*\" in *-fNM*|*-O\\ exit*) exit 0;; esac\necho STUB-SSH-RAN \"\$@\"\n");
        chmod($this->bin.'/ssh', 0755);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->bin.'/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->bin);
        parent::tearDown();
    }

    private function users(array $args, bool $tty): Process
    {
        $env = ['PATH' => $this->bin.':'.getenv('PATH'), 'BNLM_PROD_HOST' => 'stub-box', 'NO_COLOR' => '1'];
        if (! $tty) {
            $p = new Process(['bash', $this->script, ...$args], null, $env);
            $p->setTimeout(30)->run();

            return $p;
        }
        // A real pseudo-terminal for stdin and stdout, via python's pty module.
        $driver = <<<'PY'
import os, pty, subprocess, sys
master, slave = pty.openpty()
p = subprocess.Popen(sys.argv[1:], stdin=slave, stdout=slave, stderr=slave, close_fds=True)
os.close(slave)
out = b""
while True:
    try:
        d = os.read(master, 4096)
    except OSError:
        break
    if not d:
        break
    out += d
sys.stdout.write(out.decode(errors="replace"))
sys.exit(p.wait())
PY;
        $p = new Process(['python3', '-I', '-c', $driver, 'bash', $this->script, ...$args], null, $env);
        $p->setTimeout(30)->run();

        return $p;
    }

    public function test_prod_is_refused_without_a_terminal(): void
    {
        $p = $this->users(['prod', 'list'], tty: false);

        $this->assertSame(2, $p->getExitCode());
        $this->assertStringContainsString('prod is run by the owner from his own terminal', $p->getErrorOutput());
        $this->assertStringNotContainsString('STUB-SSH-RAN', $p->getOutput().$p->getErrorOutput());
    }

    public function test_prod_runs_from_a_terminal(): void
    {
        if (trim((string) shell_exec('command -v python3')) === '') {
            $this->markTestSkipped('python3 is needed to make a terminal.');
        }

        $p = $this->users(['prod', 'list'], tty: true);

        $this->assertSame(0, $p->getExitCode(), $p->getOutput());
        $this->assertStringContainsString('STUB-SSH-RAN', $p->getOutput());
        $this->assertStringContainsString('users:list', $p->getOutput());
        $this->assertStringContainsString('connected', $p->getOutput());
    }

    public function test_a_dry_run_of_prod_needs_no_terminal_and_runs_nothing(): void
    {
        $p = $this->users(['--dry-run', 'prod', 'list'], tty: false);

        $this->assertSame(0, $p->getExitCode());
        $this->assertStringNotContainsString('STUB-SSH-RAN', $p->getOutput());
    }

    public function test_arguments_starting_with_a_dash_are_refused(): void
    {
        foreach ([['--dry-run', 'dev', 'link', '--help'], ['--dry-run', 'dev', 'invite', '-x@example.com', 'member'], ['--dry-run', 'dev', 'role', 'a@b.com', '--yes']] as $args) {
            $p = $this->users($args, tty: false);
            $this->assertSame(2, $p->getExitCode(), implode(' ', $args));
            $this->assertStringContainsString('unexpected argument', $p->getErrorOutput());
        }
    }

    public function test_delete_suggests_disable_first_and_needs_the_email_back(): void
    {
        $p = $this->users(['dev', 'delete', 'a@example.com'], tty: false);

        $this->assertSame(2, $p->getExitCode());                      // no terminal and no --confirm
        $this->assertStringContainsString('--confirm a@example.com', $p->getErrorOutput());
        $this->assertStringContainsString('disable', $p->getErrorOutput());
    }
}
