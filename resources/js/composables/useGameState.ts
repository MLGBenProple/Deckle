export type GameMode = 'normal' | 'hard';

export type GuessLogEntry = {
    guess: string;
    correct: boolean;
    revealedCard?: string;
    timestamp: Date;
};

export type SavedGameState = {
    completed: boolean;
    won: boolean;
    incorrectGuesses: number;
    guessLog: GuessLogEntry[];
    completedAt: string;
};

export type CompletionStatus = {
    normal?: SavedGameState;
    hard?: SavedGameState;
};

const STORAGE_KEY_PREFIX = 'deckle-game-';

function getStorageKey(date: string, mode: GameMode): string {
    return `${STORAGE_KEY_PREFIX}${date}-${mode}`;
}

export function saveGameState(
    date: string,
    mode: GameMode,
    state: Omit<SavedGameState, 'completedAt'>
): void {
    try {
        const fullState: SavedGameState = {
            ...state,
            completedAt: new Date().toISOString(),
        };
        localStorage.setItem(getStorageKey(date, mode), JSON.stringify(fullState));
    } catch {
        // localStorage might be full or unavailable
        console.warn('Failed to save game state to localStorage');
    }
}

export function loadGameState(date: string, mode: GameMode): SavedGameState | null {
    try {
        const stored = localStorage.getItem(getStorageKey(date, mode));
        if (!stored) return null;
        
        const parsed = JSON.parse(stored);
        // Restore Date objects in guessLog
        if (parsed.guessLog) {
            parsed.guessLog = parsed.guessLog.map((entry: GuessLogEntry) => ({
                ...entry,
                timestamp: new Date(entry.timestamp),
            }));
        }
        return parsed;
    } catch {
        return null;
    }
}

export function getCompletionStatus(date: string): CompletionStatus {
    return {
        normal: loadGameState(date, 'normal') ?? undefined,
        hard: loadGameState(date, 'hard') ?? undefined,
    };
}

export function getAllCompletedGames(): Map<string, CompletionStatus> {
    const completed = new Map<string, CompletionStatus>();
    
    try {
        for (let i = 0; i < localStorage.length; i++) {
            const key = localStorage.key(i);
            if (!key?.startsWith(STORAGE_KEY_PREFIX)) continue;
            
            // Extract date and mode from key: "deckle-game-YYYY-MM-DD-mode"
            const suffix = key.slice(STORAGE_KEY_PREFIX.length);
            const lastDash = suffix.lastIndexOf('-');
            if (lastDash === -1) continue;
            
            const date = suffix.slice(0, lastDash);
            const mode = suffix.slice(lastDash + 1) as GameMode;
            
            if (!completed.has(date)) {
                completed.set(date, {});
            }
            
            const state = loadGameState(date, mode);
            if (state) {
                const current = completed.get(date)!;
                current[mode] = state;
            }
        }
    } catch {
        // localStorage might be unavailable
    }
    
    return completed;
}

export function useGameState() {
    return {
        saveGameState,
        loadGameState,
        getCompletionStatus,
        getAllCompletedGames,
    };
}
