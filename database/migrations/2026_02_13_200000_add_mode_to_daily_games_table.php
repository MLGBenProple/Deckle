<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_games', function (Blueprint $table) {
            $table->string('mode')->default('normal')->after('date');

            // Replace the unique date constraint with a composite unique on date + mode
            $table->dropUnique(['date']);
            $table->unique(['date', 'mode']);
        });
    }

    public function down(): void
    {
        Schema::table('daily_games', function (Blueprint $table) {
            $table->dropUnique(['date', 'mode']);
            $table->unique('date');
            $table->dropColumn('mode');
        });
    }
};
