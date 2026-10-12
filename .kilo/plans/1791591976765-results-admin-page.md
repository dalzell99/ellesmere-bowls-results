# Results & Disputes Page — Implementation Plan

Project: `C:\Users\dalze\Documents\ellesmere-bowls-results` (vanilla HTML/CSS/JS + PHP 7.4 + SQLite).
New standalone page: `results.php`. Not linked from `index.php`.

## Objective

Add a `results.php` page that:

1. Lists **every game** across all competitions/rounds, with **Competition / Round / Team** filters.
2. Lets games **without results** be edited inline (per-discipline score/ends), saved via the
   existing `POST api.php?action=save`.
3. Lists **every dispute**; clicking one opens the scorecard photo **fullscreen** with the
   **disputed discipline's** score/ends editable.
4. Shows a **counts** section: how many games each person has played, per competition.
5. **Flags** anyone who has played 3+ Rooster games and then a Chatterton game (not allowed).

## Locked decisions

- **Editing model:** inline on the new page, saving through `POST api.php?action=save` using the
  existing per-game `revision` optimistic lock. No navigation to the score sheet for editing.
- **"Played a game":** the person's name appears in that game's `homePlayers` or `awayPlayers`
  (results not required). A person counts once per game even if listed on both sides.
- **Counts/flags:** computed client-side from the full draw; **not** affected by the filters.
- **Filters apply to the games list only.** Round cascades from Competition; Team is independent.
- **Games with results are read-only** in the list; only result-less games are inline-editable.
- **Dispute fullscreen:** only the disputed discipline's score/ends is editable.
- **`index.php` and `app.js` stay untouched** (avoid regressions to the deployed score sheet);
  `results.js` is self-contained and duplicates a few small pure helpers.
- Competition names **"Rooster"** and **"Chatterton"** are hard-coded in the flag rule
  (case-insensitive match).

## Assumptions to confirm

- **Same-day is not "then".** All Rooster and Chatterton rounds share their dates and the games
  run at the same time, so a player cannot legitimately play both on the same day. A Chatterton
  game therefore counts as "then played" only when its date is **strictly later** than the date of
  the player's 3rd Rooster game. A same-day Rooster+Chatterton appearance in the data is treated as
  a data-entry error and does **not** trigger this flag (see Risks/notes).
- **Scores/ends only.** Player names are not editable on this page; the existing players are
  preserved on every save (they are entered/edited on the score sheet).
- **Scorer name optional.** If the `#nameDialog` is dismissed with no name, saves still proceed;
  `sanitise_user()` attributes them to `unknown` (same as the score sheet).

## Files to change

| File | Change |
| --- | --- |
| `db.php` | Add read helper `load_disputes(PDO): array`. No schema change. |
| `api.php` | Add `GET action=disputes` + `handle_disputes()`; update the header docblock. |
| `results.php` | New page shell (filters, games, disputes, counts, dialogs) + local `asset_url()` copy. |
| `results.js` | New, self-contained client logic. |
| `styles.css` | Add styles + responsive rules for the new page. |

## API / data

### `db.php` — `load_disputes(PDO $pdo): array`

```sql
SELECT id, game_id, competition, round_number, discipline, home_team, away_team,
       user_name, filename, created_at
FROM disputes
ORDER BY created_at DESC, id DESC
```

Cast `id`/`game_id`/`round_number` to int; cast the rest to string. Read-only; PHP 7.4-safe (no
union types / match / nullsafe). `open_db()` already ensures the schema.

### `api.php` — `GET action=disputes`

Add a dispatch branch (before the trailing 405):

```php
if ($method === 'GET' && $action === 'disputes') {
	handle_disputes(open_db($SEED_FILE));
}
```

`handle_disputes(PDO $pdo)`:

- `try { $rows = load_disputes($pdo); }` on failure → `respond(500, ['success' => false, 'error' => 'data_unreadable'])`.
- `respond(200, ['success' => true, 'disputes' => $rows])`.

Dispute photos are served directly from the web root at `disputes/<filename>` (no new endpoint).

## `results.php` (markup)

- Copy the small `asset_url()` helper from `index.php` (cache-busting) into this file.
- Structure:
  - Header (title + "Reload" button).
  - **Filters** bar: `<select id="filterCompetition">`, `<select id="filterRound">` (cascades),
    `<select id="filterTeam">`.
  - **Games** section: `<div id="gamesBody">` (table).
  - **Disputes** section: `<div id="disputesList">`.
  - **Counts** section: `<div id="countsBody">`.
  - **Scorer-name dialog** (`#nameDialog`) mirroring `index.php`, shown only when
    localStorage `scorerName` is empty.
  - **Dispute viewer**: `<dialog id="disputeViewer">` with `<img id="disputeImage">`, the
    disputed discipline's editable fields, a Save button, a status line, and a Close button.
- `<script src="<?= asset_url('results.js') ?>"></script>`. No inline logic beyond `asset_url`.
- Reuse `.dialog` / `.primary-button` / `.secondary-button` / `.save-status` classes.

## `results.js` (behaviour)

Self-contained. Copy from `app.js`, with a comment naming `app.js` as the canonical source so the
rules stay in sync: `byText`, `deepCopy`, `parseWholeNumber`, `capitalise`, `element`, `option`,
`canonical`, `conditionFor`, `conditionViolations`, `scoreEndsViolations`.

### Boot / load

- `NAME_KEY = 'scorerName'`, `API = 'api.php'`, `KNOWN_DISCIPLINES = ['singles','pairs','triples','fours']`.
- On load: `Promise.all([loadDraw, loadDisputes])`; build `gamesById` (map game id → `{compIdx,
  roundIdx, gameIdx, game, competition}`) from the draw.
- Read `localStorage.scorerName`; if empty, open `#nameDialog`. The dialog may be dismissed with
  no name (saves then use `userName: ''` → server stores `unknown`).
- "Reload" re-fetches the draw and disputes and re-renders everything.

### Games list + filters

- Flatten games in draw order (competition position → round position → game position); each row
  keeps `{compIdx, roundIdx, gameIdx, game, competition, round}`.
- Filter selects: competition = all competition names sorted (plus an "All" placeholder); round =
  rounds of the selected competition sorted by number, **disabled until a competition is chosen**
  (mirrors `index.php`); team = distinct `homeTeam` / `awayTeam` values across the whole draw,
  sorted, always enabled.
- Filtering: keep a row when competition matches (or "All"), and (if a competition is chosen) its
  round matches, and team matches either side. `onFilterChange()` re-renders the table.
- Table columns: **Competition | Round | Date | Home | Away | Result | Edit**.
  - Result: per discipline `Singles 21–12 (E:…)` style summary, or `—` when no results.
  - `gameHasResults(game)` = any `scores[i]` has any of `homeScore/awayScore/homeEnds/awayEnds`
    non-null.
  - Result-less rows get an **Edit** toggle that expands an inline `.game-edit` block; games with
    results show no Edit control.

### Inline edit + save

- Form: one block per declared discipline (competition `discipline[]` filtered to
  `KNOWN_DISCIPLINES`; keep each entry's original index as `position`), 4 number inputs
  (`homeScore`, `awayScore`, `homeEnds`, `awayEnds`). Players are shown for context but not
  editable.
  - Adapt the copied `conditionFor` to take the competition object as an argument (in `app.js` it
    reads the single global selection); scope the input lookups per rendered row (not a global
    `sheetBody`).
  - If a result-less game declares no known disciplines, render a short "no scoreable disciplines"
    note instead of an empty, unsubmittable form.
- Live validation reuses `conditionFor` (per competition `conditions`) + `conditionViolations`
  (score/ends rules) + `scoreEndsViolations`; mark bad inputs `is-invalid` and disable Save until
  all four values per discipline are present and valid.
- Save: `game = deepCopy(row.game)`, then replace `scores` with the form values
  (`{position, discipline, homeScore, awayScore, homeEnds, awayEnds}`), **preserving
  `homePlayers`/`awayPlayers` and any other keys**. POST
  `{gameId: game.id, revision: game.revision, userName: getScorerName(), game}`.
  - 200: adopt new `revision`, update `state.draw[...]`, re-render the row, recompute counts.
  - 409: show a "someone else saved this fixture — reload" notice and refetch the draw.
  - other error: inline `save-status--error`.
- Disable the row's Save while a request is in flight.

### Disputes list + fullscreen viewer

- Render each dispute row: Created | Competition | Round | Home vs Away | Discipline | Scorer,
  with the row clickable.
- On click: open `#disputeViewer`; set `#disputeImage.src = 'disputes/' + filename`; look up
  `gamesById[game_id]`.
  - Found: render only the **disputed** discipline's 4 inputs (match the game's `scores` entry by
    `discipline` name, case-insensitive) and a Save that posts the full game (other disciplines
    and players preserved). Reuse the same save path and lock, using the game's current revision.
  - Not found (game removed / re-seeded): show the photo and a "game is no longer in the draw —
    score not editable" note.
- Close on the Close button; the native `<dialog>` provides Esc + backdrop close.

### Counts + flag

- `computeCounts(draw)`: group players case-insensitively (display the first spelling seen);
  `counts[person][competition] += 1` per game appearance (once per game). Build the set of
  competition names in draw order.
- Render a table: **Person | <comp 1> | <comp 2> | … | Total | Flag**; persons sorted by name.
- `computeFlags(draw)`: for each person collect their **Rooster** game dates. Dedupe per game
  (once per game, as with counts). If they have ≥ 3 Rooster appearances, sort those dates
  ascending and take the **date of the 3rd Rooster game** as the threshold. Flag when any
  **Chatterton** appearance has a date **strictly greater** than the threshold date. Dates are the
  round `date` strings (`YYYY-MM-DD`, so lexical compare works); a missing date is treated as
  unknown and cannot form or satisfy the threshold.
- Flagged persons: highlighted row + a badge stating the reason (e.g. "3 Rooster games then
  Chatterton R2"), and the offending Chatterton round(s).

## Tasks (ordered)

1. `db.php`: add `load_disputes()`.
2. `api.php`: add the `disputes` dispatch branch, `handle_disputes()`, and update the docblock.
3. `results.php`: page shell, filters, sections, name dialog, dispute viewer, `asset_url()` copy,
   script include.
4. `results.js`: helpers + load/filter + games table + inline edit/validate/save.
5. `results.js`: disputes list + fullscreen viewer + disputed-discipline save.
6. `results.js`: counts + flag computation and rendering.
7. `styles.css`: new sections and responsive rules.
8. Manual verification (below).

## Verification

- `php -S localhost:8000`; open `http://localhost:8000/results.php`.
- Games list shows every game; Competition/Round/Team filters narrow it (Round cascades,
  Team matches either side).
- A result-less game expands to an inline form; entering valid score/ends saves and the row
  updates in place; a value that breaks the competition condition (e.g. singles score > 25 for
  Rooster) or score < ends is blocked/flagged.
- A game with results shows its scores read-only and no Edit control.
- Concurrent edit of the same game from the sheet and this page reconciles: one gets 409 and a
  reload notice (revision lock intact).
- Disputes list shows all rows newest-first; clicking one opens the photo fullscreen with only
  the disputed discipline editable; saving updates that game; a dispute whose game is gone shows
  the photo with the "not editable" note.
- Counts table matches a hand-check of `game_players`; a person with 3 Rooster appearances
  followed by a Chatterton game on a **later** date is highlighted + badged; someone with 3
  Rooster games but no later Chatterton game is not. Explicitly test the same-date case: a
  Chatterton game on the **same date** as the 3rd Rooster game is **not** flagged (the games run
  simultaneously, so that could only be a data error).
- `index.php` and `app.js` are unchanged and still work.
- PHP files pass a PHP 7.4 syntax review (no union types, `match`, constructor promotion, named
  args, nullsafe, `mixed`/`static` return types, or trailing commas in parameter lists).

## Out of scope

- Linking `results.php` from `index.php` / any nav.
- Editing games that already have results from the list (use the score sheet or a dispute).
- Editing player names from this page (scores/ends only; players are preserved on save).
- Server-side counts/flag (computed client-side).
- Authentication beyond the self-declared scorer name.
- Deleting, annotating or pruning disputes.

## Risks / notes

- `app.js` remains the canonical validation logic; `results.js` duplicates the condition and
  score-vs-ends rules — keep them in sync if either changes.
- Dispute→discipline matching is by name; a repeated discipline name within one game would match
  the first occurrence (no repeats in the current draw).
- The flag rule hard-codes "Rooster"/"Chatterton" and relies on round dates. All Rooster and
  Chatterton rounds share the same dates and the games run simultaneously, so "then" is a
  **strictly later date**; a same-day Rooster+Chatterton appearance cannot happen legitimately and
  is not flagged by this rule (it is a data-entry error, not the 3-game rule). Rounds without a
  date cannot form or satisfy the threshold. Document all of this in a code comment.
- `disputes.game_id` can dangle after a re-seed; the viewer still shows the photo but no editor.
- `disputes/` is web-readable (unchanged posture); photos are reachable at `disputes/<filename>`.
- `results.php` needs a writable `backups/` and `draw.sqlite` for saves, same as `index.php`.
