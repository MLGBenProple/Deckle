<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref, reactive, nextTick, watch } from 'vue';

type Card = {
    quantity: number;
    name: string;
};

const props = withDefaults(defineProps<{
    tournamentName: string | null;
    playerName: string | null;
    decklist: Record<string, Card[]>;
    hardMode?: boolean;
}>(), {
    hardMode: false,
});

const typeSections = computed(() => {
    return Object.entries(props.decklist).filter(([key]) => key !== 'Commanders');
});

const commanders = computed(() => props.decklist.Commanders ?? []);

// Hard mode: flatten all non-commander cards into a single shuffled array (computed once)
function buildShuffledCards(): Card[] {
    const all: Card[] = [];
    for (const [key, cards] of Object.entries(props.decklist)) {
        if (key !== 'Commanders') all.push(...cards);
    }
    for (let i = all.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [all[i], all[j]] = [all[j], all[i]];
    }
    return all;
}
const shuffledCards = props.hardMode ? buildShuffledCards() : [];

const commanderGuess = ref('');
const revealedCommanders = reactive(new Set<number>());
const revealedCards = reactive(new Set<string>());
const shaking = ref(false);
const incorrectGuesses = ref(0);
const showWinModal = ref(false);
const gaveUp = ref(false);

const allCommandersRevealed = computed(() =>
    commanders.value.length > 0 && commanders.value.every((_, i) => revealedCommanders.has(i)),
);

const gameOver = computed(() => allCommandersRevealed.value || gaveUp.value);

watch(allCommandersRevealed, (won) => {
    if (!won) return;
    revealAllCards();
    setTimeout(() => {
        showWinModal.value = true;
    }, 1500);
});

function revealAllCards() {
    for (const [, cards] of typeSections.value) {
        for (const card of cards) {
            revealedCards.add(card.name);
        }
    }
}

function giveUp() {
    gaveUp.value = true;
    // Reveal all commanders
    commanders.value.forEach((_, i) => revealedCommanders.add(i));
    revealAllCards();
    setTimeout(() => {
        showWinModal.value = true;
    }, 1500);
}

function normalize(str: string): string {
    return str.toLowerCase().replace(/[^a-z0-9\s]/g, '').replace(/\s+/g, ' ').trim();
}

function levenshtein(a: string, b: string): number {
    const m = a.length, n = b.length;
    const dp: number[][] = Array.from({ length: m + 1 }, () => Array(n + 1).fill(0));
    for (let i = 0; i <= m; i++) dp[i][0] = i;
    for (let j = 0; j <= n; j++) dp[0][j] = j;
    for (let i = 1; i <= m; i++) {
        for (let j = 1; j <= n; j++) {
            dp[i][j] = a[i - 1] === b[j - 1]
                ? dp[i - 1][j - 1]
                : 1 + Math.min(dp[i - 1][j], dp[i][j - 1], dp[i - 1][j - 1]);
        }
    }
    return dp[m][n];
}

function isCloseMatch(guess: string, target: string): boolean {
    const g = normalize(guess);
    const t = normalize(target);
    if (g === t) return true;
    const maxDist = Math.max(1, Math.floor(t.length * 0.25));
    return levenshtein(g, t) <= maxDist;
}

function pickRandomHiddenCard(): string | null {
    const source: Card[] = props.hardMode ? shuffledCards : typeSections.value.flatMap(([, cards]) => cards);
    const hiddenCards = source.filter(card => !revealedCards.has(card.name)).map(card => card.name);
    if (hiddenCards.length === 0) return null;
    return hiddenCards[Math.floor(Math.random() * hiddenCards.length)];
}

function submitGuess() {
    const guess = commanderGuess.value.trim();
    if (!guess) return;

    const matchIndex = commanders.value.findIndex(
        (c, i) => !revealedCommanders.has(i) && isCloseMatch(guess, c.name),
    );

    if (matchIndex !== -1) {
        revealedCommanders.add(matchIndex);
        commanderGuess.value = '';
    } else {
        incorrectGuesses.value++;
        shaking.value = true;
        setTimeout(() => (shaking.value = false), 600);

        const picked = pickRandomHiddenCard();
        if (picked) {
            nextTick(() => {
                const el = document.querySelector(`[data-card="${CSS.escape(picked)}"]`);
                if (el) {
                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    setTimeout(() => {
                        revealedCards.add(picked);
                    }, 1200);
                } else {
                    revealedCards.add(picked);
                }
            });
        }

        commanderGuess.value = '';
    }
}

function sectionCardCount(cards: Card[]): number {
    return cards.reduce((sum, c) => sum + c.quantity, 0);
}

function scryfallImageUrl(cardName: string): string {
    return `https://api.scryfall.com/cards/named?format=image&version=normal&exact=${encodeURIComponent(cardName)}`;
}
</script>

<template>
    <Head :title="hardMode ? 'Hard Mode' : 'Commander Tournament'" />

    <div class="mx-auto max-w-7xl px-4 py-8">
        <div class="mb-6">
            <h1 class="text-2xl font-bold tracking-tight">
                <span v-if="hardMode" class="text-red-600 dark:text-red-400">Hard Mode</span>
                <span
                    class="rounded transition-colors duration-1000"
                    :class="allCommandersRevealed
                        ? ''
                        : 'bg-gray-300 text-gray-300 select-none dark:bg-gray-700 dark:text-gray-700'"
                >
                    {{ tournamentName ?? 'Unknown Tournament' }}
                </span>
            </h1>
            <p v-if="playerName" class="text-sm">
                Player:
                <span
                    class="rounded transition-colors duration-1000"
                    :class="allCommandersRevealed
                        ? ''
                        : 'bg-gray-300 text-gray-300 select-none dark:bg-gray-700 dark:text-gray-700'"
                >
                    {{ playerName }}
                </span>
            </p>
        </div>

        <!-- Normal mode: commander cards shown face-down -->
        <div v-if="!hardMode && commanders.length" class="mb-6">
            <h2 class="mb-3 text-lg font-semibold">Commanders</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
                <div v-for="(card, index) in commanders" :key="card.name">
                    <div class="relative aspect-[488/680] overflow-hidden rounded-lg shadow-md">
                        <img
                            :src="scryfallImageUrl(card.name)"
                            :alt="card.name"
                            class="absolute inset-0 h-full w-full object-cover transition-opacity duration-500"
                            :class="revealedCommanders.has(index) ? 'opacity-100' : 'opacity-0'"
                            loading="lazy"
                        />
                        <div
                            v-if="!revealedCommanders.has(index)"
                            class="absolute inset-0 bg-gray-300 dark:bg-gray-700"
                        ></div>
                    </div>
                    <p v-if="revealedCommanders.has(index)" class="mt-2 text-center text-sm font-medium text-green-600 dark:text-green-400">
                        {{ card.name }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Hard mode: revealed commanders appear as they're guessed -->
        <div v-if="hardMode && revealedCommanders.size > 0" class="mb-6">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
                <div v-for="(card, index) in commanders" :key="card.name">
                    <div v-if="revealedCommanders.has(index)">
                        <div class="relative aspect-[488/680] overflow-hidden rounded-lg shadow-md">
                            <img
                                :src="scryfallImageUrl(card.name)"
                                :alt="card.name"
                                class="absolute inset-0 h-full w-full object-cover"
                                loading="lazy"
                            />
                        </div>
                        <p class="mt-2 text-center text-sm font-medium text-green-600 dark:text-green-400">
                            {{ card.name }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Guess input (both modes) -->
        <div v-if="!gameOver" class="mb-6 flex max-w-md items-center gap-3">
            <input
                v-model="commanderGuess"
                type="text"
                placeholder="Guess a commander..."
                class="flex-1 rounded-md border bg-white px-3 py-2 text-sm shadow-sm transition-colors focus:outline-none focus:ring-1 dark:bg-gray-800 dark:text-gray-100"
                :class="shaking
                    ? 'animate-shake border-red-500 focus:border-red-500 focus:ring-red-500'
                    : 'border-gray-300 focus:border-gray-500 focus:ring-gray-500 dark:border-gray-600'"
                @keydown.enter="submitGuess()"
            />
            <span v-if="incorrectGuesses > 0" class="text-sm text-gray-500">
                {{ incorrectGuesses }} {{ incorrectGuesses === 1 ? 'guess' : 'guesses' }}
            </span>
            <button
                v-if="hardMode"
                class="shrink-0 rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-600 transition-colors hover:bg-gray-100 dark:border-gray-600 dark:text-gray-400 dark:hover:bg-gray-700"
                @click="giveUp"
            >
                Give up
            </button>
        </div>

        <!-- Sectioned card view (normal mode always, hard mode after game over) -->
        <template v-if="!hardMode || gameOver">
            <div v-for="[section, cards] in typeSections" :key="section" class="mb-6">
                <h2 class="mb-3 text-lg font-semibold">
                    {{ section }}
                    <span class="text-sm font-normal text-gray-500">
                        ({{ sectionCardCount(cards) }})
                    </span>
                </h2>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
                    <div
                        v-for="card in cards"
                        :key="card.name"
                        :data-card="card.name"
                        class="relative aspect-[488/680] overflow-hidden rounded-lg shadow-md ring-2 transition-[ring-color] duration-[1500ms]"
                        :class="revealedCards.has(card.name) ? 'ring-green-400/60' : 'ring-transparent'"
                    >
                        <img
                            :src="scryfallImageUrl(card.name)"
                            :alt="card.name"
                            class="absolute inset-0 h-full w-full object-cover transition-opacity duration-[1500ms]"
                            :class="revealedCards.has(card.name) ? 'opacity-100' : 'opacity-0'"
                            loading="lazy"
                        />
                        <Transition name="overlay-fade">
                            <div
                                v-if="!revealedCards.has(card.name)"
                                class="absolute inset-0 bg-gray-300 dark:bg-gray-700"
                            ></div>
                        </Transition>
                    </div>
                </div>
            </div>
        </template>

        <!-- Hard mode during gameplay: single shuffled grid, no sections -->
        <div v-if="hardMode && !gameOver" class="mb-6">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
                <div
                    v-for="card in shuffledCards"
                    :key="card.name"
                    :data-card="card.name"
                    class="relative aspect-[488/680] overflow-hidden rounded-lg shadow-md ring-2 transition-[ring-color] duration-[1500ms]"
                    :class="revealedCards.has(card.name) ? 'ring-green-400/60' : 'ring-transparent'"
                >
                    <img
                        :src="scryfallImageUrl(card.name)"
                        :alt="card.name"
                        class="absolute inset-0 h-full w-full object-cover transition-opacity duration-[1500ms]"
                        :class="revealedCards.has(card.name) ? 'opacity-100' : 'opacity-0'"
                        loading="lazy"
                    />
                    <Transition name="overlay-fade">
                        <div
                            v-if="!revealedCards.has(card.name)"
                            class="absolute inset-0 bg-gray-300 dark:bg-gray-700"
                        ></div>
                    </Transition>
                </div>
            </div>
        </div>
    </div>

    <!-- Win modal -->
    <Transition name="modal-fade">
        <div v-if="showWinModal && !gaveUp" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showWinModal = false">
            <div class="mx-4 w-full max-w-sm rounded-xl bg-white p-6 text-center shadow-2xl dark:bg-gray-800">
                <h2 class="mb-2 text-2xl font-bold">Congratulations!</h2>
                <p class="mb-1 text-gray-600 dark:text-gray-300">
                    You guessed {{ commanders.length === 1 ? 'the commander' : `all ${commanders.length} commanders` }}
                </p>
                <p class="mb-4 text-gray-600 dark:text-gray-300">
                    <span v-if="incorrectGuesses === 0">with no wrong guesses!</span>
                    <span v-else>in {{ incorrectGuesses }} {{ incorrectGuesses === 1 ? 'guess' : 'guesses' }}!</span>
                </p>
                <div class="mb-4 flex justify-center gap-2">
                    <span v-for="(card, i) in commanders" :key="i" class="text-sm font-medium text-green-600 dark:text-green-400">
                        {{ card.name }}<span v-if="i < commanders.length - 1" class="text-gray-400"> &amp; </span>
                    </span>
                </div>
                <button
                    class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-gray-200"
                    @click="showWinModal = false"
                >
                    View Decklist
                </button>
            </div>
        </div>
    </Transition>

    <!-- Give up modal -->
    <Transition name="modal-fade">
        <div v-if="showWinModal && gaveUp" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showWinModal = false">
            <div class="mx-4 w-full max-w-sm rounded-xl bg-white p-6 text-center shadow-2xl dark:bg-gray-800">
                <h2 class="mb-2 text-2xl font-bold">Better luck next time!</h2>
                <p class="mb-4 text-gray-600 dark:text-gray-300">
                    The {{ commanders.length === 1 ? 'commander was' : 'commanders were' }}:
                </p>
                <div class="mb-4 flex flex-col items-center gap-1">
                    <span v-for="(card, i) in commanders" :key="i" class="text-sm font-medium text-red-600 dark:text-red-400">
                        {{ card.name }}
                    </span>
                </div>
                <button
                    class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-gray-200"
                    @click="showWinModal = false"
                >
                    View Decklist
                </button>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
@keyframes shake {
    0%, 100% { transform: translateX(0); }
    15%, 45%, 75% { transform: translateX(-5px); }
    30%, 60%, 90% { transform: translateX(5px); }
}

.animate-shake {
    animation: shake 0.5s ease-in-out;
}

.overlay-fade-leave-active {
    transition: opacity 1.5s ease;
}

.overlay-fade-leave-to {
    opacity: 0;
}

.modal-fade-enter-active {
    transition: opacity 0.3s ease;
}

.modal-fade-leave-active {
    transition: opacity 0.2s ease;
}

.modal-fade-enter-from,
.modal-fade-leave-to {
    opacity: 0;
}
</style>
