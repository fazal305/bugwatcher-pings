<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Named ErrorEvent so it can't be confused with Laravel's Event facade; the table is "events".
class ErrorEvent extends Model
{
    use HasUlids;

    protected $table = 'events';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'line' => 'integer',
            'stacktrace' => 'array',
            'request' => 'array',
            'user_context' => 'array',
            'is_regression' => 'boolean',
            'is_sample' => 'boolean',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }
}
