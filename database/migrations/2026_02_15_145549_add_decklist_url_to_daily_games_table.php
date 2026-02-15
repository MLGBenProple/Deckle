<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('daily_games', function (Blueprint $table) {
            $table->string('decklist_url')->nullable()->after('decklist');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_games', function (Blueprint $table) {
            $table->dropColumn('decklist_url');
        });
    }
};
