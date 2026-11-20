<?php

namespace App\Ai;

use App\Studio\SlideContract;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\ObjectSchema;

/**
 * The JSON schema of each kind of call that must answer in JSON (D-051), in the shape both providers' strict structured output takes: every
 * field required, objects closed (laravel/ai adds additionalProperties:false), no recursion, no length or count keywords (Claude refuses them,
 * and laravel/ai's sanitizer would only turn them into hints). Meaning stays with our own validators: counts, lengths, citations.
 */
class Schemas
{
    public const NAMES = ['quiz', 'overview', 'podcast_script', 'mindmap', 'slides', 'infographic_review'];

    /**
     * The whole JSON schema of a kind, as a provider receives it: objects closed (additionalProperties false), every field required.
     *
     * @return array<string, mixed>
     */
    public static function schema(string $name): array
    {
        return (new ObjectSchema(self::for($name)(new JsonSchemaTypeFactory)))->toSchema();
    }

    /** @return Closure(JsonSchema): array<string, Type> */
    public static function for(string $name): Closure
    {
        return match ($name) {
            'quiz' => self::quiz(...),
            'overview' => self::overview(...),
            'podcast_script' => self::podcastScript(...),
            'mindmap' => self::mindmap(...),
            'slides' => self::slides(...),
            'infographic_review' => self::infographicReview(...),
            default => throw new \InvalidArgumentException("No schema named [$name]."),
        };
    }

    /** @return array<string, Type> */
    private static function quiz(JsonSchema $s): array
    {
        return [
            'title' => $s->string()->required(),
            'questions' => $s->array()->items($s->object([
                'question' => $s->string()->required(),
                'options' => $s->array()->items($s->string())->required(),
                'answer' => $s->integer()->enum([0, 1, 2, 3])->required(),
                'hint' => $s->string()->required(),
                'explanation' => $s->string()->required(),
                'refs' => $s->array()->items($s->string())->required(),
            ]))->required(),
        ];
    }

    /** D-057: Claude's verdict on the picture it briefed: good or bad, and what is wrong with it.
     *
     * @return array<string, Type>
     */
    private static function infographicReview(JsonSchema $s): array
    {
        return [
            'verdict' => $s->string()->enum(['good', 'bad'])->required(),
            'problems' => $s->array()->items($s->string())->required(),
        ];
    }

    /** @return array<string, Type> */
    private static function overview(JsonSchema $s): array
    {
        return [
            'summary' => $s->string()->required(),
            'questions' => $s->array()->items($s->string())->required(),
        ];
    }

    /** @return array<string, Type> */
    private static function podcastScript(JsonSchema $s): array
    {
        return [
            'title' => $s->string()->required(),
            'turns' => $s->array()->items($s->object([
                'speaker' => $s->string()->enum(['A', 'B'])->required(),
                'text' => $s->string()->required(),
            ]))->required(),
        ];
    }

    /**
     * The mind map has a fixed depth (config studio.mindmap_max_depth, 3), so it is written out level by level instead of with a recursive
     * reference, which strict structured output does not allow.
     *
     * @return array<string, Type>
     */
    private static function mindmap(JsonSchema $s): array
    {
        $node = fn (?Type $children = null) => $s->object(array_filter([
            'label' => $s->string()->required(),
            'refs' => $s->array()->items($s->string())->required(),
            'children' => $children?->required(),
        ]));
        $level3 = $node();
        $level2 = $node($s->array()->items($level3));
        $level1 = $node($s->array()->items($level2));

        return [
            'title' => $s->string()->required(),
            'children' => $s->array()->items($level1)->required(),
        ];
    }

    /**
     * Each slide is one of the seven templates: its name and ITS fields, from the contract itself (SlideContract::templates()), so the schema and
     * the checker cannot disagree about which fields a template has.
     *
     * @return array<string, Type>
     */
    private static function slides(JsonSchema $s): array
    {
        $slides = array_map(fn (string $template, array $fields) => $s->object([
            'template' => $s->string()->enum([$template])->required(),
            'fields' => $s->object(self::fields($s, $fields))->required(),
            'citations' => $s->array()->items($s->string())->required(),
        ]), array_keys(SlideContract::templates()), array_values(SlideContract::templates()));

        return [
            'title' => $s->string()->required(),
            'slides' => $s->array()->items($s->anyOf($slides))->required(),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $spec  a template's field specs from the contract
     * @return array<string, Type>
     */
    private static function fields(JsonSchema $s, array $spec): array
    {
        $out = [];
        foreach ($spec as $name => $rule) {
            $out[$name] = self::field($s, $rule)->required();
        }

        return $out;
    }

    /** @param  array<string, mixed>  $rule */
    private static function field(JsonSchema $s, array $rule): Type
    {
        if (isset($rule['max'])) {
            return $s->string();
        }
        if (isset($rule['list'])) {
            return $s->array()->items(self::field($s, $rule['item']));
        }

        return $s->object(self::fields($s, $rule['object']));
    }
}
