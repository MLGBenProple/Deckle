<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DailyGame extends Model
{
    /**
     * Define which attributes can be mass assigned during model creation.
     * 
     * This fillable array enables safe bulk assignment for daily game creation,
     * protecting against mass assignment vulnerabilities while allowing efficient
     * model instantiation from tournament API data.
     * 
     * Fields correspond to the complete tournament puzzle data structure:
     * - date: The puzzle date (typically today())
     * - mode: Difficulty mode ('normal' or 'hard')
     * - tournament_name: Source tournament for attribution
     * - player_name: Tournament player for attribution
     * - player_standing: Actual tournament result (the "answer")
     * - total_participants: Tournament size for context
     * - decklist: Complete deck data structure (JSON)
     * - decklist_url: Original deck URL when available
     */
    protected $fillable = [
        'date',
        'mode',
        'tournament_name',
        'player_name',
        'player_standing',
        'total_participants',
        'decklist',
        'decklist_url',
    ];

    /**
     * Configure automatic casting for model attributes.
     * 
     * These casts ensure proper data type handling when retrieving daily games
     * from the database and converting them for use in the application.
     * 
     * - date: Automatically converts date strings to Carbon instances for easy
     *   date manipulation and formatting in controllers and views
     * - decklist: Automatically serializes/deserializes array data to/from JSON
     *   in the database, enabling complex deck structure storage
     * 
     * @return array Attribute casting configuration
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'decklist' => 'array',
        ];
    }

    /**
     * Scope query to retrieve daily games for the current date.
     * 
     * This scope implements the core "daily puzzle" filtering logic,
     * ensuring that puzzle lookups return only games for the current day.
     * It uses Laravel's today() helper for timezone-aware date comparison.
     * 
     * Usage: DailyGame::forToday()->get()
     * 
     * This scope is essential for the puzzle caching system, preventing
     * accidental retrieval of games from previous days and ensuring each
     * day has distinct puzzle content.
     * 
     * @param Builder $query Eloquent query builder instance
     * @return Builder Modified query scoped to today's date
     */
    public function scopeForToday(Builder $query): Builder
    {
        return $query->where('date', today());
    }

    /**
     * Scope query to retrieve daily games for a specific difficulty mode.
     * 
     * This scope enables filtering by game difficulty ('normal' or 'hard'),
     * supporting the dual-mode puzzle system where players can attempt
     * different difficulty levels of the same daily puzzle concept.
     * 
     * Usage: DailyGame::mode('hard')->get()
     * 
     * The mode system allows:
     * - Independent puzzle instances for different difficulty levels
     * - Players can complete both modes if desired
     * - Different UI/scoring behaviors based on difficulty
     * 
     * @param Builder $query Eloquent query builder instance
     * @param string $mode Difficulty mode ('normal', 'hard', etc.)
     * @return Builder Modified query filtered by difficulty mode
     */
    public function scopeMode(Builder $query, string $mode): Builder
    {
        return $query->where('mode', $mode);
    }
}
