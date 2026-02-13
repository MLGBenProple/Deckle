<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DailyGame extends Model
{
    protected $fillable = [
        'date',
        'mode',
        'tournament_name',
        'player_name',
        'decklist',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'decklist' => 'array',
        ];
    }

    public function scopeForToday(Builder $query): Builder
    {
        return $query->where('date', today());
    }

    public function scopeMode(Builder $query, string $mode): Builder
    {
        return $query->where('mode', $mode);
    }
}
