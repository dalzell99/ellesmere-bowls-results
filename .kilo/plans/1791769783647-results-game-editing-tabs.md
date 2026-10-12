# Results page: edit every game, add games, tabbed UI

## Goal

On `results.php` (admin), make **every** game editable, and let an admin **add** games:

- A game that already has results: **only its scores** are editable.
- A game with no results at all: **scores, date and teams** are editable.
- Add a new game to an existing competition + round.
- Reorganize the page into **tabs**: Games / Disputes / Games played.

## Decisions (agreed with user)

1. **Date is round-level** (`rounds.date`), shared by every game in the round. A game's Date field edits the round's date and is enabled **only when no game in that round has any result**. (No schema change.)
2. **Add game** appends to an **existing** competition + round; fields are home team and away team; no scores at creation.
3. **Team names** are free text (trimmed/capped) with a datalist of existing names as suggestions; new names allowed. (Teams remain plain strings — no team table.)
4. **Validation is per-discipline**: a discipline is either fully blank (skipped) or fully filled and rule-checked. For a game that already has results, a discipline that currently holds a result **cannot be blanked** (scores correctable, never accidentally erased).
5. **Tabs** are Games (default, keeps the existing filters), Disputes, Games played; the active tab is stored in the URL hash (`#games` / `#disputes` / `#counts`) so reload/resync returns to it.

## Constraints / invariants

- Target runtime is **PHP 7.4**: no union types, `match`, nullsafe, constructor promotion, named args.
- Do **not** change the public `save` action or `index.php`/`app.js` behaviour; the score sheet must keep working unchanged.
- No DB schema changes; `rounds.date` stays the single date. No `schema_version` bump.
- Preserve tabs indentation, existing comments, and the "one definition of each helper" convention (keep `results.js` helpers in sync with `app.js`/`validation.js` as noted in their headers).
- Every successful write still produces a `backups/` export (best-effort, non-blocking).

## Affected boundaries

- `db.php` — add an admin-only `update_game()` write path (teams/date allowed only for resultless games) that leaves the public `save_game()` untouched; add `insert_game()`; add validation for "don't blank an existing result" and the round-date guard.
- `api.php` — add admin-gated actions; keep `save` public and unchanged.
- `results.php` — replace the three stacked `<section>`s with a tablist + panels; add an Add-game dialog.
- `results.js` — tab logic + hash routing; edit button on every row; richer editor for resultless games; per-discipline validation; add-game flow; new save path.
- `styles.css` — tab styles (reuse the existing admin palette).
- `validation.js` — **no rule change** (rules are unchanged); the per-discipline completeness check lives in `results.js`.

## Backend tasks (`db.php`, `api.php`)

1. **`db.php`: `game_has_results(PDO, int $gameId): bool`** — EXISTS query over `game_scores` for any non-null score/ends. Reuse the pattern already in `game_summary()`.
2. **`db.php`: `round_has_results(PDO, int $roundId): bool`** — EXISTS join `games`→`game_scores` for any result in the round (drives the date guard).
3. **`db.php`: add `update_game(PDO, int $gameId, int $revision, array $game, ?string $roundDate)`** as a distinct **admin-only** write path. Do **not** relax the existing public `save_game()`: it must keep ignoring teams/date, so the public `save` action can never mutate them.
	- keep the existing `BEGIN IMMEDIATE` + revision check (returns `'not_found'`/`'conflict'`);
	- resolve the game's `round_id` (join `games`→`rounds`) and read its current results before writing;
	- **if the game has results**: reject a supplied team or round-date change (return `'teams_locked'`) — replace players/scores only (today's behaviour);
	- **if the game has no results**: `UPDATE games SET home_team=?, away_team=?`; and only when `$roundDate !== null` and `round_has_results($roundId)` is false, `UPDATE rounds SET date=?` — an out-of-guard date change is rejected, never silently dropped;
	- still replace players + scores wholesale (`DELETE` + `insert_game_players`/`insert_game_scores`) and bump `revision`; return the new revision.
4. **`db.php`: `insert_game(PDO, int $roundId, string $homeTeam, string $awayTeam): int`** — verify the round exists; `position = COALESCE(MAX(position),-1)+1` for that `round_id`; insert with `revision` default 1; no player/score rows. Return the new id.
5. **`api.php` action `update_game` (POST, admin-gated with `require_results_auth_json()`)**:
	- body `{ gameId, revision, userName, game, roundDate? }`; validate ints; `normalise_game()` for the game;
	- reject (`400`) a teams/date change when the stored game has results;
	- enforce the **never blank an existing result** rule: compare submitted score positions against stored ones and reject if a position that had a result is now blank/incomplete;
	- run `validate_game()` for filled disciplines; return `{ success, revision, backup }` (same shape as `save`, incl. `409 conflict`).
6. **`api.php` action `add_game` (POST, admin-gated)**:
	- body `{ roundId, homeTeam, awayTeam, userName }`; require non-empty capped team names and an existing round (`400` otherwise);
	- `insert_game()`; return `{ success, gameId, backup }`.
7. Wire both actions into the existing dispatch block (distinct `POST` branches, mirroring `delete_dispute`). `update_game` writes a backup like `save`.

## Frontend tasks (`results.php`, `results.js`, `styles.css`)

1. **`results.php`**: add a `role="tablist"` with three `role="tab"` buttons (`games`, `disputes`, `counts`) and wrap the existing three sections as `role="tabpanel"` panels (ids `panelGames`, `panelDisputes`, `panelCounts`), one visible at a time. Add an **Add game** button in the Games panel (next to the filters) and an `addGame` `<dialog>` with Competition select, Round select (cascades), Home team + Away team inputs (with datalists), Save/Cancel.
2. **`results.js` — tabs**: `activateTab(name)` toggles `.is-active` / `hidden` on tabs+panels, sets `aria-selected` and roving `tabindex`, updates `location.hash`, and is called on load from `location.hash` (default `games`); listen for `hashchange`. Support Left/Right arrow keys between tabs per the ARIA tabs pattern. Do not re-fetch on tab change.
3. **`results.js` — edit every row**: always render the Edit/Close toggle (remove the `gameHasResults` gate on the button). Keep the existing expanded-editor `<tr>`.
4. **`results.js` — `buildEditor(row)`**:
	- if `gameHasResults(row.game)`: render only the discipline score blocks (as today) plus a note that only scores are editable;
	- else: additionally render a **Date** input (prefilled from `row.round.date`) and **Home team**/**Away team** text inputs, above the score blocks. Date input `disabled` when `roundHasResults(row.roundIdx)`; show a short note why.
5. **`results.js` — per-discipline validation**: change `validateEntries()` to take a completeness mode, because its two callers must differ:
	- **Games editor** (`mode: 'per-discipline'`): for each known discipline, all four fields blank → skip; some-but-not-all filled → invalid; all filled → run `conditionViolations`/`scoreEndsViolations`.
	- **Dispute viewer** (`mode: 'require-all'`, unchanged behaviour): the single disputed entry must still be fully filled, so a blank entry can never clear a disputed result.
	In the games editor, additionally disable Save when a discipline that **currently holds a result** in `row.game` is left blank (client mirror of the server guard). Update `updateEditorSaveState()` (per-discipline) and `updateDisputeSaveState()` (require-all) to pass their mode explicitly.
6. **`results.js` — save path**: for the Games editor call `api.php?action=update_game` with `{ gameId, revision, userName, game, roundDate? }` (roundDate only for resultless games with an edited/entered date). On success, adopt `data.revision`, write the returned game back into `state.draw[...].rounds[...].games[...]` (including home/away team), update `round.date` if changed, `rebuildGamesById()`, re-render Games + counts. Keep the existing `409` → reload notice. `results.js` no longer calls the public `save` action; that stays in use only by `app.js`.
7. **`results.js` — add game**: submit calls `api.php?action=add_game`; on success push a `{ id, revision, homeTeam, awayTeam, homePlayers: [], awayPlayers: [], scores: [] }` game onto the chosen round, `rebuildGamesById()`, re-render Games (+ counts) and close the dialog. Rebuild the team-name datalist from `flattenGames()` whenever the draw reloads.
8. **`results.js` — helpers**: add `roundHasResults` (scan that round's games) and a `teamNames()` collector (distinct `homeTeam`/`awayTeam`, case-insensitive de-dupe with `byText` sort, mirroring `populateFilters`). Keep `renderAll()` rendering all three panels; tab visibility is CSS/`hidden` only.
9. **`styles.css`**: add `.tabs`, `.tab`, `.tab.is-active`, and panel spacing in the existing "Results & Disputes page" block; keep responsive behaviour and the coarse-pointer sizing.

## Data flow

`load` (public) → draw (competition→round→game, each with `id`, `revision`, teams, players, scores; rounds carry `date`).
Games editor (admin) → `api.php?action=update_game` → `db.php:update_game()` (revision lock; conditional teams/round-date) → backup → returns new revision.
Add game (admin) → `add_game` → `insert_game` (max position + 1) → backup.
Counts recompute entirely client-side from the draw, so new/edited games flow through with no extra work.

## Failure modes to handle

- **Revision conflict (409)** on `update_game`: existing reload notice; discard the inline editor.
- **Session expiry (401)** on admin actions: existing "Session expired — reload" notice.
- **Public `save` cannot mutate teams/date**: it keeps calling the unchanged `save_game()`, so anonymous callers on the score-sheet endpoint cannot rename teams or change round dates.
- **Round-date guard violated server-side**: reject with a clear error; client keeps the guard too.
- **Blank an existing result**: blocked client-side and re-checked server-side (dispute viewer keeps the strict all-present rule so it cannot clear a result either).
- **Team rename vs disputes**: `disputes` store a denormalised team copy; after a rename `disputeMatchesGame()` fails and the viewer already degrades to read-only. Acceptable; note in the UI copy if needed.
- **Add game to a round that vanished** (re-seed between load and submit): `add_game` returns an error; surface it and prompt reload.
- **Two admins add concurrently**: positions use `MAX+1` inside the insert; retry/reload on failure.

## Validation plan

- `php -l` on changed PHP files (note: local PHP 8.3 will not catch 8-only syntax; manually confirm 7.4 compatibility).
- Manual, with `php -S localhost:8000`:
	1. Scored game → Edit shows score-only; changing a score saves; teams/date not shown.
	2. Unplayed game → Edit shows date + teams + scores; save teams only (blank scores) succeeds; save a filled discipline with a rule violation is blocked; a different discipline left blank is allowed.
	3. Round with any scored game → the Date field is disabled for its games.
	4. Add game to a round → appears at the end in the Games table and in the score sheet's fixture list on `index.php`; counts tab includes it.
	5. Tabs switch and persist across reload via the hash; filters still apply within Games.
	6. `409` path: open the same game twice and save twice to see the conflict notice.
	7. Crafted `POST api.php?action=save` for a resultless game carrying different `homeTeam`/`awayTeam`/round date → confirm the teams and date are unchanged (public path cannot mutate them); the same body via `action=update_game` without a session → `401`.
	8. Dispute viewer: a disputed discipline cannot be saved blank (Save stays disabled).
- Confirm a `backups/` file is written on each `update_game`/`add_game`.

## Out of scope

- Deleting games.
- Creating new rounds or competitions.
- A team registry, per-game dates, or editing players from the results page.
- Any change to the public score sheet or dispute upload flow.
