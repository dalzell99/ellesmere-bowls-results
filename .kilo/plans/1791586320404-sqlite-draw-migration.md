# Migrate draw.json to a normalized SQLite database — Implementation Plan

Project: `C:\Users\dalze\Documents\ellesmere-bowls-results` (vanilla HTML/CSS/JS + PHP, no composer).

## Objective
Replace `draw.json` as the live data store with a normalized SQLite database (`draw.sqlite`
in the project root). `api.php` reads and writes the draw through SQL and exposes a
**DB-native API** (games addressed by database id, optimistic per-game revision). `app.js`
is updated to the new API. `draw.json` stays in the repo **only as the one-time seed**
(read once when the DB is empty); the live DB is **not** tracked in git.

## Locked decisions
- **Storage:** normalized relational schema in `draw.sqlite` (root). Live DB is gitignored.
- **API:** DB-native — `load` returns the draw with `id`/`revision`; `save` sends
  `gameId` + `revision`; `dispute` sends `gameId`.
- **Concurrency:** per-game optimistic lock via a `games.revision` integer. A save that
  finds a newer revision returns `409`; only the same game can conflict, so two people can
  edit different games concurrently.
- **Backups:** after each successful save, export the current draw to
  `backups/draw_<user>_<YYYYMMDD-HHMMSS>.json` (unchanged naming/shape from today).
- **Seed:** `draw.json` is kept as the seed. When the DB is missing/empty, `api.php`
  auto-creates the schema and imports `draw.json`; the file is never written again.
- **Disputes:** stay as files in `disputes/` (not stored in the DB); only the game-detail
  lookup behind the filename moves to SQL.

## Data flow
`app.js` → `api.php` (PDO/SQLite) → `draw.sqlite`. Backups are JSON exports produced from
the DB. On first run the DB is seeded from `draw.json`.

---

## Schema (`draw.sqlite`, created by `db.php`)
All statements `CREATE TABLE IF NOT EXISTS`; connection sets `PRAGMA foreign_keys = ON`,
`journal_mode = WAL`, `busy_timeout = 5000`.

```sql
CREATE TABLE IF NOT EXISTS meta (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS competitions (
  id          INTEGER PRIMARY KEY,
  name        TEXT NOT NULL,
  num_players INTEGER NOT NULL DEFAULT 4,
  position    INTEGER NOT NULL
);

-- Every discipline INSTANCE, in order (repeats allowed): ["pairs","singles","pairs"].
CREATE TABLE IF NOT EXISTS competition_disciplines (
  id             INTEGER PRIMARY KEY,
  competition_id INTEGER NOT NULL REFERENCES competitions(id) ON DELETE CASCADE,
  position       INTEGER NOT NULL,
  name           TEXT NOT NULL,
  UNIQUE(competition_id, position)
);

-- The conditions map, one row per discipline that has a condition.
CREATE TABLE IF NOT EXISTS competition_conditions (
  competition_id INTEGER NOT NULL REFERENCES competitions(id) ON DELETE CASCADE,
  discipline     TEXT NOT NULL,
  num            INTEGER NOT NULL,
  type           TEXT NOT NULL,          -- 'score' | 'ends'
  PRIMARY KEY (competition_id, discipline)
);

CREATE TABLE IF NOT EXISTS rounds (
  id             INTEGER PRIMARY KEY,
  competition_id INTEGER NOT NULL REFERENCES competitions(id) ON DELETE CASCADE,
  round_number   INTEGER NOT NULL,
  date           TEXT,                   -- YYYY-MM-DD
  position       INTEGER NOT NULL,
  UNIQUE(competition_id, position)
);

CREATE TABLE IF NOT EXISTS games (
  id         INTEGER PRIMARY KEY,
  round_id   INTEGER NOT NULL REFERENCES rounds(id) ON DELETE CASCADE,
  home_team  TEXT NOT NULL,
  away_team  TEXT NOT NULL,
  position   INTEGER NOT NULL,
  revision   INTEGER NOT NULL DEFAULT 1, -- optimistic lock, bumped per save
  updated_at TEXT,
  UNIQUE(round_id, position)
);

CREATE TABLE IF NOT EXISTS game_players (
  id       INTEGER PRIMARY KEY,
  game_id  INTEGER NOT NULL REFERENCES games(id) ON DELETE CASCADE,
  side     TEXT NOT NULL CHECK (side IN ('home','away')),
  position INTEGER NOT NULL,
  name     TEXT NOT NULL,
  UNIQUE(game_id, side, position)
);

CREATE TABLE IF NOT EXISTS game_scores (
  id         INTEGER PRIMARY KEY,
  game_id    INTEGER NOT NULL REFERENCES games(id) ON DELETE CASCADE,
  position   INTEGER NOT NULL,           -- index into competition_disciplines
  discipline TEXT NOT NULL,
  home_score INTEGER,
  away_score INTEGER,
  home_ends  INTEGER,
  away_ends  INTEGER,
  UNIQUE(game_id, position)
);

CREATE INDEX IF NOT EXISTS idx_rounds_comp   ON rounds(competition_id, position);
CREATE INDEX IF NOT EXISTS idx_games_round   ON games(round_id, position);
CREATE INDEX IF NOT EXISTS idx_players_game  ON game_players(game_id, side, position);
CREATE INDEX IF NOT EXISTS idx_scores_game   ON game_scores(game_id, position);
```

`meta` holds `('schema_version','1')` for future migrations.

**Ordering invariant:** `competition_disciplines.position` and `game_scores.position`
must preserve the original `discipline` array order. The client submits `scores` in that
order, so the server stores `position = index` of the submitted array and returns scores
ordered by `position`. This keeps `game.scores[disciplineIndex]` working in `app.js`.

---

## New file: `db.php` (shared DB layer)
Functions (all take/return plain PHP arrays for easy reuse by `api.php` and the CLI):

- `db(): PDO` — open DSN `sqlite:<__DIR__>/draw.sqlite`; set `ERRMODE_EXCEPTION`,
  `FETCH_ASSOC`; `exec('PRAGMA foreign_keys=ON')`, `exec('PRAGMA journal_mode=WAL')`,
  `exec('PRAGMA busy_timeout=5000')`. `foreign_keys` is per-connection, so set it on every
  call. `api.php` wraps the `db()` call in try/catch: a missing `pdo_sqlite` extension or an
  unwritable file → `500 db_unavailable`.
- `ensure_schema(PDO $pdo): void` — run the DDL above; insert `schema_version` if absent.
- `seed_from_json(PDO $pdo, string $path): void` — if `competitions` is empty **and** the
  file exists, decode + `import_draw()`. Idempotent; a no-op once seeded. Guard the
  empty-check + import under a single `BEGIN IMMEDIATE` transaction so two simultaneous
  first requests cannot both seed (the second sees the rows and skips).
- `import_draw(PDO $pdo, array $draw): void` — insert competitions → disciplines →
  conditions → rounds → games inside one transaction. Uses the same coercions as today
  (cap lengths; `numPlayers` fallback 4).
- `load_draw(PDO $pdo): array` — assemble the nested array **in `position` order** with
  `id` and `revision` on competitions/rounds/games, plus `discipline`, `conditions`,
  `numPlayers`, `homePlayers`, `awayPlayers`, `scores`. Run the whole assembly inside a read
  transaction (`beginTransaction()`/`commit()` = `BEGIN DEFERRED`) so the several SELECTs see
  one consistent snapshot and a concurrent save cannot produce a torn, half-updated view.
- `save_game(PDO $pdo, int $gameId, int $revision, array $game, string $user): int|string`
  — `$pdo->exec('BEGIN IMMEDIATE')` (NOT `beginTransaction()`, which emits a plain deferred
  `BEGIN`); `SELECT revision FROM games WHERE id=?`; missing row → ROLLBACK + return
  `'not_found'`; stored `revision !== $revision` → ROLLBACK + return `'conflict'`; else
  update `home_team`/`away_team`, `DELETE`+`INSERT` `game_players` and `game_scores`,
  `revision = revision + 1`, `updated_at = now`, `exec('COMMIT')`, return the new revision.
  Wrap in try/catch and `exec('ROLLBACK')` on any throwable.
- `get_game(PDO $pdo, int $gameId): ?array` — single game in the load shape (for the `409`
  body).
- `game_summary(PDO $pdo, int $gameId): ?array` — joins `games → rounds → competitions` to
  return `{ competition, round, homeTeam, awayTeam, hasResults }` for the dispute filename.
  `hasResults` = `EXISTS(SELECT 1 FROM game_scores WHERE game_id=? AND (home_score IS NOT
  NULL OR away_score IS NOT NULL OR home_ends IS NOT NULL OR away_ends IS NOT NULL))`.
- `export_draw_json(PDO $pdo): string` — `load_draw()` → tab-indented pretty JSON (reuse
  today's `json_with_tabs`).

**Helper layout (avoid redeclare):** move the pure helpers shared by both files —
`json_with_tabs`, `cap_str`, `filter_int`, `score_value`, `normalise_players`,
`normalise_game`, `sanitise_user`, `sanitise_slug`, `build_dispute_filename`,
`write_dispute_file` — into `db.php` (or a dedicated `helpers.php` required by both) and
delete their definitions from `api.php`. Keep exactly one definition; `api.php` must not
redeclare them. `normalise_game()` must now carry each score's `position` through (see save
payload).

Optional CLI `seed.php` — `php seed.php [path]` to force (re)import a JSON file (e.g. restore
from `backups/*.json`). Not required for normal running; document it.

---

## `api.php` changes
Remove: `$DATA_FILE` file reads/writes, the temp-file+`rename` logic, the temp `flock`
lock file (`acquire_lock`/`release_lock`), and `backup_file` (JSON `copy`).
Add: `require __DIR__ . '/db.php';` then on every action `db()`, `ensure_schema()`,
`seed_from_json(__DIR__ . '/draw.json')`, wrapped so a DB failure returns
`500 db_unavailable`. Keep the action handlers, response helper, and the image/JPEG +
`write_dispute_file` flow; the pure helpers now live in the shared file (see Helper layout).

**`GET api.php?action=load`** →
```json
{ "success": true, "draw": [ {
  "id": 1, "competition": "Rooster", "discipline": ["singles","pairs","triples"],
  "conditions": { "singles": { "num": 25, "type": "score" }, ... },
  "numPlayers": 6,
  "rounds": [ { "id": 3, "round": 1, "date": "2026-10-17", "games": [
    { "id": 7, "revision": 1, "homeTeam": "Leeston MJ", "awayTeam": "Leeston Hammy",
      "homePlayers": [], "awayPlayers": [], "scores": [] } ] } ] } ] }
```
(No global `version`.)

**`POST api.php?action=save`** body:
```json
{ "gameId": 7, "revision": 1, "userName": "Jane",
  "game": { "homeTeam": "…", "awayTeam": "…",
            "homePlayers": ["a","b"], "awayPlayers": ["c","d"],
            "scores": [ { "position": 0, "discipline":"singles",
                          "homeScore":21,"awayScore":18,
                          "homeEnds":null,"awayEnds":null } ] } }
```
`position` is the score's index in the competition's original `discipline` array (the client
already tracks it as `entry.index`). The server stores `game_scores.position` from it, so the
scores stay aligned with `competition_disciplines` even if the client ever filters unknown
discipline names (the client's `state.entries` is filtered to known disciplines today, so
array index and original index coincide only while every discipline is known). If `position`
is absent, fall back to the array index.

Behaviour:
1. Validate `gameId` (int ≥ 0), `revision` (int ≥ 1), and that `game` is an object; else
   `400 bad_body`.
2. `normalise_game($game)` (unchanged coercions: cap strings, int scores/ends, drop empty
   players; carry `position` through).
3. `save_game(...)`:
   - `'not_found'` → `400 { success:false, error:"game_not_found" }`.
   - `'conflict'` → `409 { success:false, error:"conflict", game:<current game> }`.
   - new revision → write the JSON backup (below) and
     `200 { success:true, revision:<new> }`.
4. Backup: `backups/draw_<sanitise_user>_<Ymd-His>.json` from `export_draw_json()`, with the
   same `-1`,`-2` collision suffix; mkdir `backups/` if needed. A backup failure is logged but
   does not fail the save (the DB write already committed) — return `success:true` with
   `backup:null` so the client isn't blocked.

**`POST api.php?action=dispute`** body: `{ gameId, userName, discipline, image }`.
Validate `gameId`; `game_summary()` to resolve competition/round/teams; `400 no_results` if
the game has no saved scores/ends; keep the existing JPEG prefix/magic-byte/size checks,
filename build, and atomic `fopen(x)` write. Respond `{ success:true, file:"…" }`.

Every response keeps `Content-Type: application/json` and the
`{ success:false, error:"<code>" }` shape.

---

## `app.js` changes
- **`loadDraw`/`resync`:** fetch `action=load`, store `data.draw`. Drop `state.version`
  everywhere — including the `resync()` assignment `state.version = data.version`, which must
  be removed (there is no global version now).
- **Selection:** keep array indices as dropdown values (draw order is stable per load), but
  resolve ids from the draw: on render set `state.gameId = game.id` and
  `state.gameRevision = game.revision`.
- **`renderSheet`:** capture `gameId`/`gameRevision`; `state.loadedGame = deepCopy(game)`
  (now includes `id`/`revision`).
- **`validateSheet`:** add `position: entry.index` to each score object (currently built as
  `{ discipline: entry.name, … }`), so the save payload carries the original discipline index
  and scores stay aligned with the DB even if unknown discipline names are ever filtered.
- **`onSave`:** POST `{ gameId: state.gameId, revision: state.gameRevision, userName, game }`
  where `game` is the rebuilt `{ homeTeam, awayTeam, homePlayers, awayPlayers, scores }`.
  - Success: `state.gameRevision = data.revision`; update the in-memory game object with the
    new revision; refresh baseline/datalists; remove the draft; show "Saved".
  - `409`: go to the simplified conflict handler (below).
- **Conflict (`handleConflict`) — simplified:** a per-game lock means a `409` is always *this*
  fixture. Set `state.forceReload = true`, remove this fixture's draft, show
  "Someone else saved this fixture. Reload to see their result." with a single **Reload**
  action (`resync()`). Delete the old "different fixture changed" branch and its
  `gameAt()`/sameGame comparison against the response (the server returns the current game if
  a comparison is still wanted for a message).
- **Drafts:** `draft.baseVersion` now holds `state.gameRevision` (a number). No other draft
  logic changes.
- **`onSubmitDispute`:** POST `{ gameId: state.gameId, userName, discipline, image }`.
- **Comments:** replace "draw.json" wording with "draw data"/"database"; the error copy in
  `loadDraw` ("Could not load draw.json") becomes "Could not load draw data".
- Guard against a stale/missing `gameId` (e.g. after the draw reloads while a sheet is open):
  if `state.gameId` is null, disable Save and resync.

---

## `index.php` / `styles.css`
No structural change; no `draw.json` reference in `index.php`. Update the load-failure copy
only (lives in `app.js`). `styles.css` unchanged.

## `.gitignore`
Add the live DB and its sidecar files (keep `draw.json` tracked as the seed):
```
draw.sqlite
draw.sqlite-wal
draw.sqlite-shm
draw.sqlite-journal
```

---

## Ordered tasks
1. `db.php`: `db()`, `ensure_schema()` (DDL above), `import_draw()`, `seed_from_json()`,
   `load_draw()`, `save_game()`, `get_game()`, `game_summary()`, `export_draw_json()`.
   Move/share the existing pure helpers. Verify load order matches the old nested shape.
2. `api.php`: swap the file backend for `db.php`; rewrite `handle_load`, `handle_save`
   (gameId/revision), `handle_dispute` (gameId); replace backup-file with JSON export;
   remove the temp lock file and temp/rename write. Keep error codes and the JSON content
   type.
3. `app.js`: per-game id/revision save + dispute payloads; simplified conflict handler; drop
   `state.version`; update comments/copy.
4. `.gitignore`: add the `draw.sqlite*` patterns.
5. Optional `seed.php` CLI for explicit import/restore.
6. Manual verification (below).

## Migration / rollout path
- First request after deploy: `draw.sqlite` is absent → schema created and seeded from
  `draw.json` (all fixtures, empty results). `draw.json` is left untouched thereafter.
- The current `draw.json` in the repo is the clean baseline (all scores empty), so the seed
  is correct. Any results saved in the old running instance only exist in its local
  `draw.json`; if present, that file is the seed (verify it holds the intended current state
  before first run).
- To rebuild: stop the server, delete `draw.sqlite` (and `-wal`/`-shm`), restart — it
  re-seeds from `draw.json`. To restore a snapshot, `php seed.php backups/draw_<…>.json`.
- No data is deleted by this change; `draw.json` stays on disk.

## Failure modes to handle
- `pdo_sqlite` not enabled → `db()` throws; return `500 { error:"db_unavailable" }` and log a
  clear message. **Pre-flight: confirm `php -m` lists `pdo_sqlite`** (see Verification).
- DB file not writable by the PHP user → `500` with a clear error; same permissions story as
  `backups/`/`disputes/`.
- Concurrent saves to the same game → SQLite serialises via `BEGIN IMMEDIATE`; the loser sees
  the bumped revision and gets `409`.
- Backup write failure → save still succeeds (`backup:null`); never fail a committed save.
- Empty/deleted DB mid-run → next request re-creates + re-seeds (loses results not in
  backups; documented tradeoff of keeping the DB out of git).

## Verification
- Pre-flight: `php -v` and `php -m` — confirm `pdo_sqlite` (and `sqlite3`) are present.
- Delete `draw.sqlite*`; run `php -S localhost:8000`; open `http://localhost:8000/`.
  Confirm `draw.sqlite` is created and the competition/round/fixture lists match `draw.json`.
- `api.php?action=load` returns the nested draw with `id`/`revision` and correct
  discipline/conditions; a game with repeated disciplines still renders the right rows.
- Save a fixture → `200` with a new `revision`; row counts in `game_players`/`game_scores`
  match; `backups/draw_<user>_<ts>.json` appears and matches `load` output; `draw.json` is
  byte-for-byte unchanged.
- Two tabs, **same** fixture: second save gets `409`, shows the conflict banner, and after
  Reload shows the first tab's result.
- Two tabs, **different** fixtures: both save with no `409` (per-game lock); reloading shows
  both results.
- Dispute on a game with results writes
  `disputes/dispute_<user>_<competition>_R<n>_<home>-vs-<away>_<ts>.jpg` from DB-resolved
  details; a game with no results returns `400 no_results`.
- Stale `revision` (hand-crafted `save` body) returns `409`; malformed body/indices return
  `400`; an unknown `gameId` returns `400 game_not_found`; none crash.
- Scores stay aligned: save a fixture whose competition repeats a discipline name (e.g.
  `["pairs","singles","pairs"]`), reload, and confirm each score lands on its original
  discipline row.
- Fresh clone (only `draw.json`, no DB) seeds correctly on first load; concurrent `load`
  requests on a fresh DB still yield exactly one seeded draw (no duplicate rows).
- `git status` shows `draw.json` tracked and `draw.sqlite*` ignored.

## Risks / notes
- `php -S` serves the project root, so `draw.sqlite` is fetchable by URL (same exposure as
  `draw.json` today). If the data must be private, add a server deny rule or move the DB
  outside the docroot — out of scope unless requested.
- WAL mode creates `draw.sqlite-wal`/`-shm`; both are gitignored. WAL requires a writable
  local directory; it does not work reliably on some network shares (keep the DB on local
  disk).
- PDO's `beginTransaction()` emits a deferred `BEGIN`, so the save path must issue
  `BEGIN IMMEDIATE` via `exec()` (see `save_game`); otherwise two writers can both read the
  old revision before either upgrades to a write lock.
- `load_draw` issues several SELECTs, so it must run inside a read transaction for a
  consistent snapshot; otherwise a save landing mid-assembly can return a half-updated draw.
- Team names are written from the save payload (capped); the client round-trips the loaded
  names, so this matches today's whole-game replace. Only non-empty values should overwrite.
- Unknown top-level keys on a game are no longer preserved (only players/scores/teams are
  stored) — equivalent in practice, since those are the only keys used.
- Per-game revisions remove the old global "another fixture changed" conflict; a remote save
  to a different game is simply picked up on the next load/resync.

## Out of scope
- Storing dispute photos in the DB (they stay files in `disputes/`).
- Editing the draw itself in the UI (add competitions/rounds/teams); the schema supports it
  but no UI is added.
- Authentication beyond the self-declared scorer name.
- Pruning `backups/` or `disputes/`.
- Committing the live DB to git (explicitly declined).
