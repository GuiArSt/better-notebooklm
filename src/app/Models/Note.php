<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Note extends Model
{
    protected $fillable = ['notebook_id', 'title', 'content', 'citations', 'from_chat', 'checked', 'source_id'];

    protected function casts(): array
    {
        return ['citations' => 'array', 'from_chat' => 'boolean', 'checked' => 'boolean'];
    }

    /** @return BelongsTo<Notebook, $this> */
    public function notebook(): BelongsTo
    {
        return $this->belongsTo(Notebook::class);
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** The note's text without the [P12] citation markers, as a source sees it. */
    public function plainText(): string
    {
        return trim((string) preg_replace('/\s*\[\s*P\d+(?:\s*[,;]\s*P?\d+)*\s*\]/u', '', $this->content));
    }
}
