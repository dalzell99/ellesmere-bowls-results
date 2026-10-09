# Rooster Cup Score Sheet — Implementation Plan

## Objective
Build a vanilla HTML/CSS/JS score-sheet page, served by PHP, that reads and writes
`draw.json` in the same folder. It replicates the printed Rooster Cup score sheet and
adds competition → round → fixture selection with a validated, conflict-checked save.

## Deliverables (all in `C:\Users\dalze\Documents\ellesmere-bowls-results\`)
- `index.html` — page shell + first-visit name modal
- `styles.css` — PDF-style layout
- `app.js` — load, dropdowns, render, validation, save
- `api.php` — load + save + backup endpoint
- `backups/` — created at runtime (keep all)
- `draw.json` — existing data file (do not restructure)

## Data model (current `draw.json`)
```json
[
  {
    "competition": "Rooster",
    "discipline": ["singles", "pairs", "triples"],
    "numPlayers": 6,
    "rounds": [
      { "round": 1, "date": "2026-10-17",
        "games": [
          { "homeTeam": "Dunsandel", "awayTeam": "Leeston 1",
            "homePlayers": [], "awayPlayers": [], "scores": [] }
        ] }
    ]
  }
]
```
- Top level: array of competitions.
- `discipline`: lists **every discipline instance**, repeats allowed.
  `["singles","pairs","pairs","pairs","triples"]` = 1 singles, 3 pairs, 1 triples.
- `numPlayers`: number of player slots per team.
- `rounds[].games[]`: one fixture. `homePlayers`/`awayPlayers` are player-name arrays.
  `scores` holds one result object per discipline instance, in the same order as the
  `discipline` array.

### Saved result object
```json
{ "discipline": "pairs", "homeScore": 18, "awayScore": 20, "homeEnds": 12, "awayEnds": 14 }
```
Singles keep `homeEnds`/`awayEnds` as `null`.

## Locked decisions
- Rows per fixture = one per entry in `competition.discipline`, grouped for display by
  discipline (first-appearance order). Each rendered row keeps its original index in the
  `discipline` array so the game's `scores` array is rebuilt in the original array order
  (grouped display order ≠ array order when a discipline is interleaved, e.g.
  `["pairs","singles","pairs","triples","pairs"]`).
- Player slots = `numPlayers` per team; each is a text input + `<datalist>` (editable
  combobox) seeded with that team's saved names.
- Player options = union of that team's names from **every** game in the file (from
  `homePlayers` where the team is `homeTeam`, from `awayPlayers` where it is `awayTeam`),
  trimmed, de-duplicated case-insensitively (keeping the first spelling seen), and sorted
  case-insensitively A→Z.
- Singles: no Ends inputs; `homeEnds`/`awayEnds` stay `null`.
- Save stays disabled until all required scores, ends (non-singles) and players are entered.
- Save = targeted game update with a conflict check.
- No lost work: the current fixture's inputs are autosaved (debounced) to `localStorage`,
  flushed on a `409`, and restored on reload — **except when the conflicting save was for the
  same fixture**, in which case the other person's result wins: the draft is discarded and the
  user is told to reload. The draft is cleared on a successful save or when discarded.
- Backups are made **after** a successful save; filename
  `draw_<sanitised-name>_<YYYYMMDD-HHMMSS>.json`; keep all backups.
- First visit prompts for the scorer's name (stored in `localStorage`); it is sent with
  every save and used in the backup filename. Header shows "Scorer: <name> (change)".
- Dropdown order: competitions, fixtures and player lists alphabetical; rounds numeric
  ascending.
- The PDF's "Club" dropdowns are replaced by the fixture selection (teams come from the
  chosen game); no club picker is needed.
- Missing-config fallbacks: unknown `discipline` values are skipped; a missing or zero
  `numPlayers` falls back to 4 so the sheet still renders.

## API (`api.php`) — single endpoint, `flock`-guarded
**`GET api.php?action=load`**
→ `200 { "success": true, "version": "<md5 of draw.json>", "draw": [ ... ] }`

**`POST api.php?action=save`** (JSON body)
```json
{
  "version": "<md5 from load>", "userName": "Jane",
  "competitionIndex": 0, "roundIndex": 1, "gameIndex": 0,
  "game": { "homeTeam": "…", "awayTeam": "…",
            "homePlayers": [], "awayPlayers": [], "scores": [] }
}
```
Behaviour:
1. Take an exclusive lock via a **dedicated lock file** (not `draw.json` itself — Windows
   cannot replace a file that is held open) for the whole read-check-write.
2. Read contents and compute md5. If it differs from `version` → `409` returning the
   current `version` and `draw` so the client can resync.
3. Validate `competitionIndex`/`roundIndex`/`gameIndex` are ints in range and that `game`
   is an object; otherwise `400`. Replace
   `draw[competitionIndex].rounds[roundIndex].games[gameIndex]` with `game`. Coerce scores
   and ends to ints, and force `homeEnds`/`awayEnds` to `null` for `singles`
   (case-insensitive).
4. Encode with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, then
   re-indent each line's leading 4-space runs to **tabs** (per-line leading whitespace only,
   never a global string replace) and append a trailing newline, matching `draw.json`.
5. Write to a temp file in the same directory, flush, close, then `rename()` it over
   `draw.json` (atomic on the same volume; on Windows PHP's `rename` overwrites, else fall
   back to `unlink` + `rename`). Release the lock.
6. Compute the new md5. **After** the successful write, `mkdir backups/` if needed and
   `copy()` the saved file to `backups/draw_<name>_<timestamp>.json` (append a short suffix
   if that name already exists).
7. → `200 { "success": true, "version": "<new md5>" }`; `500` with
   `{ "success": false, "error": "…" }` on a write or backup failure.

Every response sets `Content-Type: application/json`. The load read takes the same lock in
shared mode (`flock(LOCK_SH)`) so it never reads a half-written file. Errors use
`{ "success": false, "error": "<code>" }` with `400` (bad body/indices) or `409` (conflict).

Safety: sanitise `userName` to `[A-Za-z0-9_-]`, cap length, blank → `unknown`; trim and cap
team/player string lengths. Build the backup path only from the sanitised name (never
interpolate user input into a shell command; no `exec`).

## Page flow (`app.js`)
1. Boot: `GET api.php?action=load`; cache `draw` and `version`.
2. If `localStorage.scorerName` is missing, show the name modal; store on submit.
3. Render **Competition** dropdown alphabetically; empty selection hides the rest.
4. On competition → **Round** dropdown ordered numerically ascending; reset lower levels.
5. On round → **Fixture** dropdown (`"<home> vs <away>"`, alphabetical); reset lower.
6. On fixture → build the sheet:
   - Heading `"<competition> Score Sheet"`, round number, team names as Home/Away headers.
   - Discipline table: one section per distinct discipline, coloured (singles blue, pairs
     orange, triples green, fours purple); per instance a **Score** row and, except singles,
     an **Ends** row, each with home/away inputs `type="number" min="0" step="1"`
     `inputmode="numeric"`. Keep the grey divider between home and away columns. Store the
     row's original `discipline` index in `data-idx`.
   - Players table: `numPlayers` rows of home/away comboboxes; two shared `<datalist>`s
     rebuilt from the team-name lookups.
   - Prefill from the game's existing `scores` (matched by original index), `homePlayers`
     and `awayPlayers`. Render all names via `textContent`/`value`, never `innerHTML`.
   - Keep a deep copy of the game as the `loadedGame` baseline for the `409` same-fixture
     check.
   - Save button + status area.
7. Validate on every change; enable Save only when complete: per instance home/away Score a
   whole number ≥ 0; non-singles also home/away Ends a whole number ≥ 0; every player slot
   non-blank after trimming.
8. Save: POST the payload, rebuilding `scores` in original `discipline`-array order (using
   `data-idx`) and trimming player names. On `200` → success message, adopt the returned
   `version`, refresh the `loadedGame` baseline, clear the stored draft for this fixture,
   rebuild datalists.
9. Conflict (`409`): flush the current inputs to the draft store, then compare the returned
   `draw` at the fixture's indices against the game the page loaded (`loadedGame`).
   - **Same fixture changed** → the other person's result wins: delete the draft for this
     fixture and show "Someone else saved this fixture. Reload to see their result." with a
     single **Reload** action; no restore is offered.
   - **Different fixture changed** → keep the draft and show "Someone else saved another
     fixture. Your unsaved entries have been kept." with **Reload latest** and **Discard
     mine**. Reloading re-fetches `draw`/`version` and leaves the draft intact, so nothing
     is lost.
10. Drafts: autosave the current fixture's inputs to `localStorage` (debounced, ~500 ms)
    under a key built from stable identity (`competition|round|home|away`); the value holds
    `homePlayers`, `awayPlayers`, `scores`, `baseVersion` and a timestamp. When a fixture
    renders and a draft exists for it, show a banner ("Unsaved changes from <time> were
    kept") with **Restore my changes** and **Discard**. Restore repopulates the inputs and
    re-runs validation; Discard deletes the draft. Drafts are removed on a successful save,
    on a same-fixture conflict, and expire after ~7 days. Restoring is offered rather than
    applied silently.

## Tasks (ordered)
1. `api.php`: implement load (shared lock); save with `flock` + md5 conflict check; atomic
   temp-file + `rename` write; post-save backup; JSON content type and 400/409/500 errors.
   Confirm JSON round-trips with tab indentation and Unicode preserved.
2. `index.html`: shell, selector bar, sheet container, name modal, Save/status area.
3. `styles.css`: replicate the sheet (title banner, coloured discipline blocks, input
   styling, grey divider, player rows).
4. `app.js`: load/version cache, name prompt, cascading dropdowns, render with original
   discipline indices, datalist population, validation gate, save handler and conflict
   handling that preserves in-progress input.
5. Manual verification (below).
6. Add brief inline comments / a short run note for `php -S localhost:8000`.

## Verification
- Run `php -S localhost:8000` in the project folder; open `http://localhost:8000/`.
- First load prompts for a name; a reload does not.
- Competition/Round/Fixture lists are correct and sorted (rounds numeric).
- A fixture with repeated disciplines renders the right number of rows; singles shows no
  Ends inputs.
- Save stays disabled until every score, end (non-singles) and player is filled.
- Save writes `draw.json` correctly; singles ends remain `null`; tab indentation preserved.
- `backups/draw_<name>_<datetime>.json` appears after the save and matches the saved file.
- Two tabs on different fixtures: saving in both → the second shows the "entries kept"
  banner and can restore after reloading.
- Two tabs on the same fixture: the second save gets a `409` with only a Reload action and,
  after reloading, shows the first tab's saved result.
- A player name typed once appears in that team's datalist on the next load.
- An interleaved `discipline` array (e.g. `["pairs","singles","pairs"]`) still saves
  `scores` in the original array order.
- Unicode club/player names survive a save/load round-trip unescaped.
- A malformed save body returns `400`, a stale version returns `409` (neither crashes).
- A team or player name containing `<script>` renders as text and is not executed.
- After a different-fixture `409`, the conflicted tab still shows the entered scores, ends
  and players after a reload, restorable from the kept draft.
- The draft banner's **Restore my changes** repopulates every input and **Discard** clears
  it.
- A same-fixture `409` discards the draft and offers no restore; reloading shows the other
  person's saved result.
- A draft is removed after a successful save (no stale banner on the next visit).

## Risks / notes
- Concurrent writers: a single whole-file md5 makes the conflict check global — if two
  people save different fixtures at the same time, the second gets a `409` and must reload
  even though the fixtures differ. That is intentional (per the chosen conflict check) and
  the banner should say so.
- The client must adopt the `version` returned by every successful save, or its next save
  will falsely conflict.
- Backups snapshot the **post-save** state, so a rollback means restoring the previous
  backup, not the newest one; label them accordingly.
- `numPlayers` and discipline sizes (fours needs 4) are independent — render exactly
  `numPlayers` slots.
- Leave the editor's existing `.history/` folder untouched; app backups live in `backups/`.
- Same-second filename collisions append a short suffix.
- Draft storage: `localStorage` keys are per-fixture and expire (~7 days) to avoid unbounded
  growth; a draft holds only that fixture's inputs, never the whole draw.
- The exclusive/shared lock lives in the system temp dir (`rooster-draw-<hash>.lock`), so
  no extra lock file is written into the project folder.
- PHP cannot write files with a bare `fetch`; the save must go through `api.php`.

## Out of scope
- Editing the draw structure (teams, player rosters, discipline counts) from the UI.
- Authentication beyond the self-declared name.
- Pruning backups.
- A print/paper stylesheet (the source is a printed form) — optional follow-up.
- Deep-linking the selected competition/round/fixture in the URL.
