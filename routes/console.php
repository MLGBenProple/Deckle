<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('daily-game:generate')->dailyAt('00:00');
