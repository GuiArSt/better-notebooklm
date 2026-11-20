<?php

namespace App\Console\Commands;

use App\Ai\Prompts;
use Axyr\Langfuse\Dto\CreatePromptBody;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Console\Command;

class PromptsPush extends Command
{
    protected $signature = 'prompts:push';

    protected $description = 'Send the repo copies in resources/prompts to Langfuse as new prompt versions (the owner moves the production label)';

    public function handle(Prompts $prompts): int
    {
        foreach ($prompts->repoCopies() as $name => $text) {
            $created = Langfuse::createPrompt(new CreatePromptBody(name: $name, type: 'text', prompt: $text));

            if ($created === null) {
                $this->error("Could not push [$name].");

                return self::FAILURE;
            }

            $this->info("Pushed $name as version {$created->getVersion()}.");
        }

        return self::SUCCESS;
    }
}
