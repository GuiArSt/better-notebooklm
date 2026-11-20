<?php

namespace Tests\Feature;

use App\Models\Passage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VectorSearchTest extends TestCase
{
    use RefreshDatabase;

    private function vec(int $hot): array
    {
        $v = array_fill(0, config('embeddings.dimensions'), 0.0);
        $v[$hot] = 1.0;

        return $v;
    }

    public function test_it_stores_and_queries_vectors_by_similarity(): void
    {
        Passage::create(['content' => 'about cats', 'embedding' => $this->vec(0)]);
        Passage::create(['content' => 'about dogs', 'embedding' => $this->vec(1)]);

        $nearest = Passage::query()
            ->whereVectorSimilarTo('embedding', $this->vec(1), minSimilarity: 0.5)
            ->get();

        $this->assertCount(1, $nearest);
        $this->assertSame('about dogs', $nearest->first()->content);
    }

    public function test_health_endpoint_is_up(): void
    {
        $this->get('/up')->assertOk();
    }
}
