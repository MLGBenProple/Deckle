<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref, reactive, nextTick, watch } from 'vue';

type Card = {
    quantity: number;
    name: string;
};

const props = withDefaults(defineProps<{
    tournamentName: string | null;
    playerName: string | null;
    playerStanding: number | null;
    totalParticipants: number | null;
    decklist: Record<string, Card[]>;
    decklistUrl?: string | null;
    hardMode?: boolean;
}>(), {
    hardMode: false,
});

// Format ordinal numbers (1st, 2nd, 3rd, etc.)
const formatOrdinal = (number: number): string => {
    let suffix = 'th';
    
    if (number % 100 < 11 || number % 100 > 13) {
        switch (number % 10) {
            case 1: suffix = 'st'; break;
            case 2: suffix = 'nd'; break;
            case 3: suffix = 'rd'; break;
        }
    }
    
    return number + suffix;
};

const standingDisplay = computed(() => {
    if (props.playerStanding && props.totalParticipants) {
        return `${formatOrdinal(props.playerStanding)}/${props.totalParticipants}`;
    }
    return null;
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
const commanderInput = ref<HTMLInputElement | null>(null);
const suggestionResults = ref<string[]>([]);
const suggestionLoading = ref(false);
const suggestionOpen = ref(false);
let suggestionTimer: number | null = null;
let suggestionAbort: AbortController | null = null;
const revealedCommanders = reactive(new Set<number>());
const revealedCards = reactive(new Set<string>());
const shaking = ref(false);
const incorrectGuesses = ref(0);
const showWinModal = ref(false);
const gaveUp = ref(false);
const showGiveUpConfirm = ref(false);

type GuessLogEntry = {
    guess: string;
    correct: boolean;
    revealedCard?: string;
    timestamp: Date;
};
const guessLog = ref<GuessLogEntry[]>([]);

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

function confirmGiveUp() {
    showGiveUpConfirm.value = true;
}

function giveUp() {
    showGiveUpConfirm.value = false;
    gaveUp.value = true;
    // Reveal all commanders
    commanders.value.forEach((_, i) => revealedCommanders.add(i));
    revealAllCards();
    setTimeout(() => {
        showWinModal.value = true;
    }, 1500);
}

function cancelGiveUp() {
    showGiveUpConfirm.value = false;
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

    clearSuggestions();

    const matchIndex = commanders.value.findIndex(
        (c, i) => !revealedCommanders.has(i) && isCloseMatch(guess, c.name),
    );

    if (matchIndex !== -1) {
        // Correct guess
        guessLog.value.push({
            guess,
            correct: true,
            timestamp: new Date()
        });
        revealedCommanders.add(matchIndex);
        commanderGuess.value = '';
    } else {
        incorrectGuesses.value++;
        shaking.value = true;
        setTimeout(() => (shaking.value = false), 600);

        const picked = pickRandomHiddenCard();
        // Log incorrect guess
        guessLog.value.push({
            guess,
            correct: false,
            revealedCard: picked || undefined,
            timestamp: new Date()
        });
        
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

function clearSuggestions() {
    suggestionResults.value = [];
    suggestionLoading.value = false;
    suggestionOpen.value = false;
    if (suggestionTimer) {
        window.clearTimeout(suggestionTimer);
        suggestionTimer = null;
    }
    if (suggestionAbort) {
        suggestionAbort.abort();
        suggestionAbort = null;
    }
}

function onGuessKeyup() {
    const query = commanderGuess.value.trim();
    if (suggestionTimer) {
        window.clearTimeout(suggestionTimer);
    }
    if (query.length < 2) {
        clearSuggestions();
        return;
    }

    suggestionTimer = window.setTimeout(() => {
        void searchScryfall(query);
    }, 250);
}

async function searchScryfall(query: string) {
    if (suggestionAbort) {
        suggestionAbort.abort();
    }
    suggestionAbort = new AbortController();
    suggestionLoading.value = true;
    suggestionOpen.value = true;

    try {
    const scryfallQuery = `name:${query} is:commander`;
        const url = `https://api.scryfall.com/cards/search?order=name&unique=cards&q=${encodeURIComponent(scryfallQuery)}`;
        const response = await fetch(url, { signal: suggestionAbort.signal });
        if (!response.ok) {
            throw new Error('Scryfall search failed');
        }
        const data = await response.json();
        const names = (data?.data ?? []).map((card: { name: string }) => card.name.split(' // ')[0]);
    suggestionResults.value = Array.from(new Set(names)).slice(0, 20);
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            return;
        }
        suggestionResults.value = [];
    } finally {
        suggestionLoading.value = false;
        suggestionOpen.value = suggestionResults.value.length > 0;
    }
}

function selectSuggestion(name: string) {
    commanderGuess.value = name;
    clearSuggestions();
    submitGuess();
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

    <div class="mx-auto max-w-7xl px-4 py-8 relative">
        <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between mr-80 lg:mr-84">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">
                    <span v-if="hardMode" class="block text-red-600 dark:text-red-400">Hard Mode</span>
                    <span
                        class="block rounded transition-colors duration-1000"
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
                    <span v-if="allCommandersRevealed && standingDisplay"> ({{ standingDisplay }})</span>
                    <a
                        v-if="allCommandersRevealed && decklistUrl"
                        :href="decklistUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="ml-2 inline-flex items-center text-xs font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300"
                    >
                        <svg class="mr-1 h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                        View on Moxfield
                    </a>
                </p>
                
                <!-- Navigation Buttons -->
                <div class="mt-4 flex flex-wrap gap-2">
                    <Link
                        href="/"
                        class="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-medium text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-600 dark:hover:bg-gray-700"
                    >
                        Home
                    </Link>
                    <Link
                        v-if="hardMode"
                        href="/play"
                        class="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-medium text-emerald-600 shadow-sm ring-1 ring-inset ring-emerald-600 hover:bg-emerald-50 hover:text-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:bg-gray-900 dark:text-emerald-400 dark:ring-emerald-500 dark:hover:bg-emerald-950 dark:hover:text-emerald-300"
                    >
                        Normal Mode
                    </Link>
                    <Link
                        v-else
                        href="/play/hard"
                        class="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-medium text-rose-600 shadow-sm ring-1 ring-inset ring-rose-600 hover:bg-rose-50 hover:text-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-500 dark:bg-gray-900 dark:text-rose-400 dark:ring-rose-500 dark:hover:bg-rose-950 dark:hover:text-rose-300"
                    >
                        Hard Mode
                    </Link>
                </div>
            </div>
        </div>

        <!-- Absolutely positioned info boxes relative to container -->
        <div class="absolute right-0 top-8 z-10 flex flex-col gap-4 w-80">
            <div
                class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700 shadow-lg dark:border-gray-700 dark:bg-gray-800/90 dark:text-gray-300 backdrop-blur-sm"
            >
                <h2 class="mb-2 font-semibold text-gray-900 dark:text-gray-100">How to Play</h2>
                <ol class="list-inside list-decimal space-y-1">
                    <li>Guess the hidden commander by name.</li>
                    <li>Wrong guesses reveal a random card from the decklist.</li>
                    <li>Use card types and counts for clues.</li>
                    <li>Guess all commanders to win &mdash; or give up anytime.</li>
                </ol>
            </div>
            
            <!-- Guess Log -->
            <div
                class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm shadow-lg dark:border-gray-700 dark:bg-gray-800/90 backdrop-blur-sm"
            >
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">Guess Log</h2>
                    <span class="text-xs text-gray-600 dark:text-gray-400">Total: {{ guessLog.length }}</span>
                </div>
                <div class="h-32 space-y-1 overflow-y-auto">
                    <div v-if="guessLog.length === 0" class="text-xs text-gray-500 dark:text-gray-400 italic">
                        No guesses yet...
                    </div>
                    <div
                        v-else
                        v-for="(entry, index) in guessLog.slice().reverse()"
                        :key="index"
                        class="flex items-center justify-between text-xs"
                    >
                        <span class="truncate font-medium">{{ entry.guess }}</span>
                        <div class="flex items-center gap-2 shrink-0">
                            <span
                                v-if="entry.correct"
                                class="inline-block h-2 w-2 rounded-full bg-green-500"
                                title="Correct guess"
                            ></span>
                            <span
                                v-else
                                class="inline-block h-2 w-2 rounded-full bg-red-500"
                                :title="entry.revealedCard ? `Revealed: ${entry.revealedCard}` : 'Incorrect guess'"
                            ></span>
                        </div>
                    </div>
                </div>
            </div>
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

        <!-- Hard mode: placeholder box before any commanders are revealed -->
        <div v-if="hardMode && revealedCommanders.size === 0" class="mb-6">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
                <div class="col-span-2 relative aspect-[976/680] overflow-hidden rounded-lg shadow-md bg-gray-300 dark:bg-gray-700 flex items-center justify-center">
                    <span class="text-4xl sm:text-5xl font-bold text-gray-500 dark:text-gray-400 select-none">?</span>
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
                    <div v-else>
                        <div class="relative aspect-[488/680] overflow-hidden rounded-lg shadow-md bg-gray-300 dark:bg-gray-700"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Guess input (both modes) -->
        <div v-if="!gameOver" class="mb-6 flex max-w-md items-center gap-3">
            <div class="relative flex-1">
                <input
                    ref="commanderInput"
                    v-model="commanderGuess"
                    type="text"
                    placeholder="Guess a commander..."
                    class="w-full rounded-md border bg-white px-3 py-2 text-sm shadow-sm transition-colors focus:outline-none focus:ring-1 dark:bg-gray-800 dark:text-gray-100"
                    :class="shaking
                        ? 'animate-shake border-red-500 focus:border-red-500 focus:ring-red-500'
                        : 'border-gray-300 focus:border-gray-500 focus:ring-gray-500 dark:border-gray-600'"
                    @keydown.enter="submitGuess()"
                    @keyup="onGuessKeyup"
                />
                <div
                    v-if="suggestionOpen && (suggestionLoading || suggestionResults.length > 0)"
                    class="absolute left-0 right-0 top-full z-20 mt-1 max-h-56 overflow-y-auto rounded-md border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800"
                >
                    <div v-if="suggestionLoading" class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400">
                        Searching Scryfall...
                    </div>
                    <button
                        v-for="name in suggestionResults"
                        :key="name"
                        type="button"
                        class="block w-full px-3 py-2 text-left text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700"
                        @mousedown.prevent
                        @click="selectSuggestion(name)"
                    >
                        {{ name }}
                    </button>
                </div>
            </div>
            <button
                class="shrink-0 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm transition-colors hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700 dark:focus:ring-offset-gray-800"
                :disabled="!commanderGuess.trim()"
                @click="submitGuess()"
            >
                Guess
            </button>
            <button
                class="shrink-0 rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-600 transition-colors hover:bg-gray-100 dark:border-gray-600 dark:text-gray-400 dark:hover:bg-gray-700"
                @click="confirmGiveUp"
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
                    You guessed {{ commanders.length === 1
                        ? 'the commander'
                        : (commanders.length === 2 ? 'both commanders' : `all ${commanders.length} commanders`) }}
                </p>
                <p class="mb-4 text-gray-600 dark:text-gray-300">
                    <span v-if="incorrectGuesses === 0">with no wrong guesses!</span>
                    <span v-else>in {{ incorrectGuesses }} {{ incorrectGuesses === 1 ? 'guess' : 'guesses' }}!</span>
                </p>
                <div class="mb-4 flex items-center justify-center gap-2">
                    <template v-for="(card, i) in commanders" :key="card.name">
                        <span class="text-sm font-medium text-green-600 dark:text-green-400">
                            {{ card.name }}
                        </span>
                        <span v-if="i < commanders.length - 1" class="text-sm font-medium text-gray-400 dark:text-gray-500">&amp;</span>
                    </template>
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

    <!-- Give up confirmation modal -->
    <Transition name="modal-fade">
        <div v-if="showGiveUpConfirm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="cancelGiveUp">
            <div class="mx-4 w-full max-w-sm rounded-xl bg-white p-6 text-center shadow-2xl dark:bg-gray-800">
                <h2 class="mb-2 text-xl font-bold text-gray-900 dark:text-gray-100">Give Up?</h2>
                <p class="mb-4 text-gray-600 dark:text-gray-300">
                    Are you sure you want to give up? This will reveal all commanders and cards.
                </p>
                <div class="flex gap-3 justify-center">
                    <button
                        class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm transition-colors hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700 dark:focus:ring-offset-gray-800"
                        @click="cancelGiveUp"
                    >
                        Cancel
                    </button>
                    <button
                        class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800"
                        @click="giveUp"
                    >
                        Give Up
                    </button>
                </div>
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
