<?php

namespace App\Console\Commands;

use App\Icons\NotebookIcons;
use App\Models\Notebook;
use Illuminate\Console\Command;

class NotebooksIcons extends Command
{
    protected $signature = 'notebooks:icons {--missing : ask for the icon of every notebook that has none} {--retry-failed : also ask again for the ones that failed}';

    protected $description = 'Queue the generated icon of existing notebooks (the seeded ones). Needs NOTEBOOK_ICONS=true and OPENAI_API_KEY.';

    public function handle(NotebookIcons $icons): int
    {
        if (! $this->option('missing') && ! $this->option('retry-failed')) {
            $this->error('Say what to do: --missing (and/or --retry-failed).');

            return self::FAILURE;
        }
        if (! NotebookIcons::available()) {
            $this->error('Icons are off: set NOTEBOOK_ICONS=true and OPENAI_API_KEY.');

            return self::FAILURE;
        }

        $queued = 0;
        Notebook::query()->orderBy('id')->each(function (Notebook $n) use ($icons, &$queued) {
            $queued += $icons->request($n, (bool) $this->option('retry-failed')) ? 1 : 0;
        });

        $this->components->info("$queued icon(s) queued.");

        return self::SUCCESS;
    }
}
