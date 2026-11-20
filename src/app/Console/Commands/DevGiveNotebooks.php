<?php

namespace App\Console\Commands;

use App\Models\Notebook;
use App\Models\StudioItem;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Dev only: hands the demo notebooks from one user to another, with everything that hangs off them. Sources, passages, notes and
 * the files on disk belong to the notebook (their paths hold the notebook id, not a user id), so only the notebook and its studio
 * items carry the owner. The ledger (usage_events) stays with the user who spent it.
 */
class DevGiveNotebooks extends Command
{
    protected $signature = 'dev:give-notebooks {from : e-mail of the user who has them now} {to : e-mail of the user who gets them} {--prefix=Demo · : only notebooks whose title starts with this}';

    protected $description = 'Dev only: move the demo notebooks (title starting with --prefix) from one user to another, in one transaction';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run: APP_ENV is production. This command exists only for dev.');

            return self::FAILURE;
        }

        $from = User::where('email', mb_strtolower((string) $this->argument('from')))->first();
        $to = User::where('email', mb_strtolower((string) $this->argument('to')))->first();
        if (! $from || ! $to || $from->is($to)) {
            $this->error('Both users must exist and be different.');

            return self::FAILURE;
        }

        $prefix = (string) $this->option('prefix');
        $moved = DB::transaction(function () use ($from, $to, $prefix) {
            $notebooks = Notebook::where('user_id', $from->id)->where('title', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'%')->get();
            foreach ($notebooks as $notebook) {
                $notebook->forceFill(['user_id' => $to->id])->save();
                StudioItem::where('notebook_id', $notebook->id)->update(['user_id' => $to->id]);
            }

            return $notebooks;
        });

        foreach ($moved as $n) {
            $this->line("moved #{$n->id} {$n->title}");
        }
        $this->components->info($moved->count().' notebook(s) moved from '.$from->email.' to '.$to->email.'.');

        return self::SUCCESS;
    }
}
