<?php

namespace App\Models;

use App\Sources\FileCleanup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudioItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['citations' => 'array', 'script' => 'array', 'tree' => 'array', 'settings' => 'array', 'quiz' => 'array', 'slides' => 'array', 'infographic' => 'array'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (StudioItem $item) => app(FileCleanup::class)->forStudioItem($item));
    }

    /** @return BelongsTo<Notebook, $this> */
    public function notebook(): BelongsTo
    {
        return $this->belongsTo(Notebook::class);
    }

    public function progress(string $step, int $n, int $total): void
    {
        $this->update(['status' => 'running', 'step' => $step, 'step_n' => $n, 'step_total' => $total]);
    }

    /** @return array<string, mixed> */
    public function toArrayForClient(): array
    {
        return [
            'id' => $this->id,
            'notebook_id' => $this->notebook_id,
            'kind' => $this->kind,
            'title' => $this->title,
            'status' => $this->status,
            'step' => $this->step,
            'step_n' => $this->step_n,
            'step_total' => $this->step_total,
            'content' => $this->content,
            'citations' => $this->citations,
            'script' => $this->script,
            'tree' => $this->tree,
            'quiz' => $this->quiz,
            'slides' => $this->slides,
            'infographic' => $this->infographic,
            'image_url' => $this->kind === 'infographic' && $this->file_path ? route('studio.image', [$this->notebook_id, $this->id]) : null,
            'audio_url' => $this->file_path ? route('studio.audio', [$this->notebook_id, $this->id]) : null,
            'error' => $this->error,
            'settings' => $this->settings,
            'sources_count' => is_array($this->settings['sources'] ?? null) ? count($this->settings['sources']) : null,
            'duration_seconds' => $this->settings['duration_seconds'] ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
