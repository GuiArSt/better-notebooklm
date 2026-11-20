<?php

namespace App\Chat;

use App\Ai\SafeLog;
use App\Models\Notebook;
use App\Models\Passage;
use Axyr\Langfuse\Dto\ScoreBody;
use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Throwable;

/**
 * One validator for every model: the model writes passage ids like [P12] or in groups like [P12, P14]; only ids that exist in a CHECKED source
 * of this notebook survive. The rest are dropped, and the drop is recorded as a Langfuse score.
 */
class CitationValidator
{
    /** A citation bracket: one id [P12] or a group [P12, P14] / [P12, 14]; the first id carries the P. Group 0 is the whole bracket. */
    public const BRACKET = '/\[\s*P\d+(?:\s*[,;]\s*P?\d+)*\s*\]/';

    /** @return list<int> every passage id written inside citation brackets, single or grouped, in order, once each */
    public static function idsIn(string $text): array
    {
        preg_match_all(self::BRACKET, $text, $brackets);
        $ids = [];
        foreach ($brackets[0] as $bracket) {
            preg_match_all('/\d+/', $bracket, $n);
            foreach ($n[0] as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>|null  $shownIds  the passages the model was actually shown; any other id is dropped
     * @return array{citations: list<array<string, mixed>>, dropped: list<int>}
     */
    public function resolve(string $answer, Notebook $notebook, ?string $traceId = null, ?array $shownIds = null): array
    {
        $ids = self::idsIn($answer);
        $dropped = [];
        if ($shownIds !== null) {
            $dropped = array_values(array_diff($ids, $shownIds));
            $ids = array_values(array_intersect($ids, $shownIds));
        }

        $found = Passage::query()
            ->where('notebook_id', $notebook->id)
            ->whereIn('id', $ids)
            ->whereHas('source', fn ($q) => $q->where('checked', true)->where('status', 'ready'))
            ->with('source:id,title,content')
            ->get()->keyBy('id');

        $citations = [];
        foreach ($ids as $id) {
            $passage = $found->get($id);
            if ($passage === null) {
                $dropped[] = $id;

                continue;
            }
            $citations[] = [
                'id' => $passage->id,
                'source_id' => $passage->source_id,
                'source_title' => $passage->source->title,
                'page' => $passage->page,
                'start' => $passage->start_offset,
                'end' => $passage->end_offset,
                'quote' => $passage->content,
            ];
        }

        if ($dropped !== [] && $traceId !== null) {
            try {
                Langfuse::score((new ScoreBody(
                    name: 'citation_dropped',
                    value: (float) count($dropped),
                    comment: 'Dropped passage ids: '.implode(', ', array_map(fn ($i) => "P$i", $dropped)),
                ))->withTraceId($traceId));
            } catch (Throwable $e) {
                SafeLog::failure('Langfuse score', $e);
            }
        }

        return ['citations' => $citations, 'dropped' => $dropped];
    }
}
