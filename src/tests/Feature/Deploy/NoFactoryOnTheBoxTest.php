<?php

namespace Tests\Feature\Deploy;

use App\Frontend\Featured;
use App\Models\Invitation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** P-018: the image has no dev dependencies, so nothing the box runs may use a model factory (Faker is not there). */
class NoFactoryOnTheBoxTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_database_seeder_creates_nothing(): void
    {
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class])->assertSuccessful();

        $this->assertSame(0, User::count());
    }

    public function test_no_app_code_calls_a_model_factory(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && preg_match('/::factory\(|(?<![\w:>\\\\])fake\(/', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = str_replace(app_path().'/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'app/ runs on the box: no factory, no Faker');
    }

    public function test_the_step_ten_commands_run_without_a_factory(): void
    {
        $this->artisan('invite', ['email' => 'someone@example.test', 'role' => 'reviewer'])->assertSuccessful();
        $this->assertSame(1, Invitation::count());

        $user = User::forceCreate(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => bcrypt('x'), 'role' => 'owner']);
        $notebook = Featured::open($user);
        $this->assertSame(3, $notebook->sources()->count(), 'the featured notebook is made from text, not from a factory');
    }
}
