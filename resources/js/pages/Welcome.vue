<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';

function startGame() {
    router.visit('/play');
}

function startHardMode() {
    router.visit('/play/hard');
}
</script>

<template>
    <Head title="Deckle" />

    <div class="flex min-h-screen flex-col items-center justify-center bg-[#FDFDFC] px-6 py-12 text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
        <div class="w-full max-w-lg text-center">
            <h1 class="mb-2 text-5xl font-extrabold tracking-tight">Deckle</h1>
            <p class="mb-10 text-lg text-gray-500 dark:text-gray-400">
                A Wordle-style guessing game for Magic: The Gathering
            </p>

            <div class="mb-10 space-y-4 text-left text-sm leading-relaxed text-gray-700 dark:text-gray-300">
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/50">
                    <h2 class="mb-2 font-semibold text-gray-900 dark:text-gray-100">How to Play</h2>
                    <ol class="list-inside list-decimal space-y-2">
                        <li>You'll be shown a real Commander decklist from a tournament &mdash; but the commander is hidden.</li>
                        <li>The cards in the deck are also hidden. Study the card types and counts for clues.</li>
                        <li>Type your guess for the commander and press Enter.</li>
                        <li>Each wrong guess reveals a random card from the decklist, giving you more information.</li>
                        <li>Guess the commander in as few tries as possible!</li>
                        <li class="text-red-600 dark:text-red-400">
                            <span class="font-semibold">Hard Mode:</span> no section headers, commander count is hidden, and cards are shuffled in a random order during play.
                        </li>
                    </ol>
                </div>
            </div>

            <div class="flex flex-col items-center gap-3 sm:flex-row sm:justify-center">
                <button
                    class="rounded-lg bg-gray-900 px-8 py-3 text-base font-semibold text-white shadow-sm transition-colors hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-gray-200"
                    @click="startGame"
                >
                    Start Game
                </button>
                <button
                    class="rounded-lg border border-red-600 px-8 py-3 text-base font-semibold text-red-600 shadow-sm transition-colors hover:bg-red-50 dark:border-red-400 dark:text-red-400 dark:hover:bg-red-950"
                    @click="startHardMode"
                >
                    Hard Mode
                </button>
            </div>
        </div>
    </div>
</template>
