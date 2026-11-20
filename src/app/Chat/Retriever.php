<?php

namespace App\Chat;

use App\Ai\Pricing;
use App\Ai\Router;
use App\Models\Notebook;
use App\Models\Passage;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Two paths, both tested. If the checked sources fit the token budget, they go to the model whole.
 * Above it, hybrid search: vector similarity and full-text, fused by reciprocal rank in one SQL query.
 */
class Retriever
{
    public function __construct(private Router $router, private Pricing $pricing) {}

    /**
     * @param  list<int>|null  $onlySourceIds
     * @return Collection<int, Passage>
     */
    public function context(User $user, Notebook $notebook, string $question, ?int $limit = null, ?array $onlySourceIds = null): Collection
    {
        // Normally the checked sources; a Studio item may name its own (D-035), which must still be ready and this notebook's.
        $sourceIds = $notebook->sources()->where('status', 'ready')
            ->when($onlySourceIds === null, fn ($q) => $q->where('checked', true), fn ($q) => $q->whereIn('id', $onlySourceIds))->pluck('id')->all();
        $sourceIds = array_values(array_map(intval(...), $sourceIds));
        if ($sourceIds === []) {
            return new Collection;
        }

        $base = Passage::query()->where('notebook_id', $notebook->id)->whereIn('source_id', $sourceIds);
        $chars = (int) (clone $base)->sum(DB::raw('char_length(content)'));

        if ($this->pricing->estimateTokens(str_repeat('x', $chars)) <= (int) config('retrieval.whole_budget_tokens')) {
            return $base->orderBy('source_id')->orderBy('position')->get();
        }

        return $this->search($user, $notebook, $sourceIds, $question, $limit);
    }

    /**
     * @param  list<int>  $sourceIds
     * @return Collection<int, Passage>
     */
    public function search(User $user, Notebook $notebook, array $sourceIds, string $question, ?int $limit = null): Collection
    {
        $embedded = $this->router->embed($user, [$question], $notebook->id);
        $vector = '['.implode(',', $embedded['vectors'][0]).']';
        $in = implode(',', array_fill(0, count($sourceIds), '?'));
        $k = $limit ?? (int) config('retrieval.top_k');
        // Any word may match: websearch_to_tsquery ANDs its terms, which a natural question never satisfies.
        preg_match_all('/[\p{L}\p{N}]+/u', $question, $words);
        $orQuery = implode(' or ', array_slice(array_unique($words[0]), 0, 24));

        $rows = DB::select("
            WITH vec AS (
                SELECT id, ROW_NUMBER() OVER (ORDER BY embedding <=> ?::vector) AS rk
                FROM passages WHERE notebook_id = ? AND source_id IN ($in) AND embedding IS NOT NULL
                ORDER BY embedding <=> ?::vector LIMIT 20
            ),
            fts AS (
                SELECT id, ROW_NUMBER() OVER (ORDER BY ts_rank_cd(content_tsv, q) DESC) AS rk
                FROM passages, websearch_to_tsquery('simple', ?) q
                WHERE notebook_id = ? AND source_id IN ($in) AND content_tsv @@ q
                ORDER BY ts_rank_cd(content_tsv, q) DESC LIMIT 20
            )
            SELECT COALESCE(vec.id, fts.id) AS id,
                   COALESCE(1.0 / (60 + vec.rk), 0) + COALESCE(1.0 / (60 + fts.rk), 0) AS score
            FROM vec FULL JOIN fts ON vec.id = fts.id
            ORDER BY score DESC LIMIT $k
        ", [$vector, $notebook->id, ...$sourceIds, $vector, $orQuery, $notebook->id, ...$sourceIds]);

        $ids = array_map(fn ($r) => (int) $r->id, $rows);
        $passages = Passage::query()->whereIn('id', $ids)->get()->keyBy('id');

        return new Collection(array_values(array_filter(array_map(fn ($id) => $passages->get($id), $ids))));
    }
}
