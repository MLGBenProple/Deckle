<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

type GameDay = {
    date: string;
    formattedDate: string;
    modes: string[];
};

defineProps<{
    games: GameDay[];
}>();
</script>

<template>
    <Head title="Previous Days - Deckle" />

    <div class="flex min-h-screen flex-col items-center bg-[#FDFDFC] px-6 py-12 text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
        <div class="w-full max-w-2xl">
            <div class="mb-8 text-center">
                <h1 class="mb-2 text-4xl font-extrabold tracking-tight">Previous Days</h1>
                <p class="text-lg text-gray-500 dark:text-gray-400">
                    Play missed days or replay old puzzles
                </p>
            </div>

            <div class="mb-6 flex justify-center">
                <Link
                    href="/"
                    class="inline-flex items-center rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-600 dark:hover:bg-gray-700"
                >
                    <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    Back to Home
                </Link>
            </div>

            <div v-if="games.length === 0" class="rounded-lg border border-gray-200 bg-gray-50 p-8 text-center dark:border-gray-700 dark:bg-gray-800/50">
                <p class="text-gray-600 dark:text-gray-400">No previous days available yet. Check back tomorrow!</p>
            </div>

            <div v-else class="space-y-3">
                <div
                    v-for="game in games"
                    :key="game.date"
                    class="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm transition-colors hover:border-gray-300 dark:border-gray-700 dark:bg-gray-800/50 dark:hover:border-gray-600 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                            {{ game.formattedDate }}
                        </h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ game.modes.length }} mode{{ game.modes.length > 1 ? 's' : '' }} available
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Link
                            v-if="game.modes.includes('normal')"
                            :href="`/play/previous/${game.date}`"
                            class="inline-flex items-center rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-gray-200"
                        >
                            Play Normal
                        </Link>
                        <Link
                            v-if="game.modes.includes('hard')"
                            :href="`/play/previous/${game.date}/hard`"
                            class="inline-flex items-center rounded-md border border-red-600 px-4 py-2 text-sm font-medium text-red-600 shadow-sm transition-colors hover:bg-red-50 dark:border-red-400 dark:text-red-400 dark:hover:bg-red-950"
                        >
                            Play Hard
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
