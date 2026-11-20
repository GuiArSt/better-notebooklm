<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotebookOverview extends Model
{
    protected $fillable = ['notebook_id', 'summary', 'questions', 'status'];

    protected function casts(): array
    {
        return ['questions' => 'array'];
    }

    /**
     * A row that exists only to say "building" or "failed" (D-038) still holds an empty summary and an empty list of questions, never NULL: the code
     * before the states existed (prod's 087b607, if it is ever rolled back to) reads `questions` as an array and `summary` as text.
     */
    public static function mark(int $notebookId, string $status, ?string $onlyWhen = null): void
    {
        $row = static::query()->where('notebook_id', $notebookId)->when($onlyWhen, fn ($q) => $q->where('status', $onlyWhen))->first();

        $row?->update(['status' => $status, 'summary' => $row->summary ?? '', 'questions' => $row->questions ?? []]);
    }

    /** The row for a notebook, made with an empty summary and empty questions if there is none; its text is never touched when it exists. */
    public static function begin(int $notebookId): self
    {
        $row = static::query()->firstOrCreate(['notebook_id' => $notebookId], ['summary' => '', 'questions' => []]);
        $row->update(['status' => 'building', 'summary' => $row->summary ?? '', 'questions' => $row->questions ?? []]);

        return $row;
    }
}
