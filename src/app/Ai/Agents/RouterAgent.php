<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/** The one agent every plain text call goes through, so a single fake covers them all in tests. */
class RouterAgent implements Agent, HasProviderOptions
{
    use Promptable;

    /**
     * @param  array<string, mixed>  $anthropicOptions  request options for Claude only (config/models.php 'structured_call_options': how THIS model
     *                                                  is told not to think). Empty for every call that did not ask, and for every other provider.
     */
    public function __construct(public string $system = '', public int $limit = 1024, public array $anthropicOptions = [], public ?string $schemaName = null, public ?array $jsonSchema = null) {}

    /**
     * Provider options of the request. Claude gets the thinking options a call asked for, and a JSON schema (output_config.format, merged beside
     * the effort); Mistral gets response_format json_schema, strict (it is off by default there). Everything else gets nothing.
     *
     * The schema is sent here and not through laravel/ai's HasStructuredOutput on purpose: an agent that implements that interface cannot be
     * STREAMED ("Streaming structured output is not currently supported"), and the chat stream goes through this same agent.
     */
    public function providerOptions(Lab|string $provider): array
    {
        if ($provider === Lab::Anthropic || $provider === 'anthropic') {
            $options = $this->anthropicOptions;
            if ($this->jsonSchema !== null) {
                $options['output_config'] = ($options['output_config'] ?? []) + ['format' => ['type' => 'json_schema', 'schema' => $this->jsonSchema]];
            }

            return $options;
        }

        if ($this->jsonSchema !== null && ($provider === Lab::Mistral || $provider === 'mistral')) {
            return ['response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => $this->schemaName ?? 'answer', 'schema' => $this->jsonSchema, 'strict' => true]]];
        }

        return [];
    }

    /** Every call tells the provider where to stop; the hold is made against the same number. */
    public function maxTokens(): int
    {
        return $this->limit;
    }

    public function instructions(): string
    {
        return $this->system;
    }
}
