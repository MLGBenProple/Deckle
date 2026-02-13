# Deckle Project Context

Deckle is a web-based game inspired by Wordle, themed around Magic: The Gathering (MTG) and specifically the Commander (EDH) format. The game is built with Laravel (backend) and Vue.js (frontend).

## Game Concept
- Each game session randomly selects a Magic: The Gathering commander using data from the topdeck.gg API.
- The game then fetches a list of cards commonly played with that commander (as recommended by topdeck.gg).
- The player is shown one of these cards and must guess the commander.
- For each incorrect guess, the game reveals another card from the list, providing more clues.
- The goal is to guess the correct commander in as few clues as possible.

## Technical Notes
- The backend will handle fetching and caching data from topdeck.gg (commander list, recommended cards, etc.).
- The frontend will present the game interface, handle user guesses, and display clues incrementally.
- The game should be replayable, with a new random commander and card set each session.
- Consider rate-limiting or caching topdeck.gg API requests to avoid overloading their servers.

## Future Features (Ideas)
- Daily challenge mode (same commander for all players each day)
- Leaderboards or stats tracking
- Support for different MTG formats
- Hints or difficulty settings

---

Use this context for all future conversations about the Deckle project. This file describes the core gameplay loop, technical stack, and initial requirements.