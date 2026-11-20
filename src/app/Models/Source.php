<?php

namespace App\Models;

use App\Sources\FileCleanup;
use App\Sources\LargeSource;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    protected $fillable = ['notebook_id', 'kind', 'title', 'content', 'status', 'error', 'checked', 'origin_url', 'file_path', 'summary', 'pages', 'language', 'chars'];

    /** The large-source warning (D-040), read by the interface from the same JSON as the rest of the source. */
    protected $appends = ['large', 'page_count', 'page_count_estimated', 'estimated_cost_micro_usd'];

    /** @var array<string, mixed>|null */
    private ?array $heavy = null;

    protected function casts(): array
    {
        return ['checked' => 'boolean', 'pages' => 'array'];
    }

    /**
     * The page ranges of a PDF source: the character range of each page inside `content`.
     *
     * @return list<array{page: int, start: int, end: int}>|null
     */
    public function pageRanges(): ?array
    {
        $pages = $this->getAttribute('pages');

        return is_array($pages) && $pages !== [] ? array_values($pages) : null;
    }

    /** @return array{large: bool, page_count: int|null, page_count_estimated: bool, estimated_cost_micro_usd: int|null} */
    private function heavy(): array
    {
        // `chars` and `pages` must be among the selected columns; without them the source simply is not "large".
        $chars = $this->getAttribute('chars');
        $tier = auth()->user()?->effectiveTier() ?? 'free';

        return $this->heavy ??= app(LargeSource::class)->describe($chars === null ? null : (int) $chars, $this->pageRanges(), $tier);
    }

    protected function large(): Attribute
    {
        return Attribute::get(fn () => $this->heavy()['large']);
    }

    protected function pageCount(): Attribute
    {
        return Attribute::get(fn () => $this->heavy()['page_count']);
    }

    protected function pageCountEstimated(): Attribute
    {
        return Attribute::get(fn () => $this->heavy()['page_count_estimated']);
    }

    protected function estimatedCostMicroUsd(): Attribute
    {
        return Attribute::get(fn () => $this->heavy()['estimated_cost_micro_usd']);
    }

    protected static function booted(): void
    {
        static::deleting(fn (Source $source) => app(FileCleanup::class)->forSource($source));
    }

    /** @return BelongsTo<Notebook, $this> */
    public function notebook(): BelongsTo
    {
        return $this->belongsTo(Notebook::class);
    }

    /** @return HasMany<Passage, $this> */
    public function passages(): HasMany
    {
        return $this->hasMany(Passage::class);
    }
}
