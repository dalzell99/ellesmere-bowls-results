<?php
declare(strict_types=1);

/**
 * Ellesmere Interclub Score Sheet — shared database layer.
 *
 * The live draw lives in a normalized SQLite database (draw.sqlite, project
 * root). draw.json is kept only as the one-time seed: when the database is
 * missing or empty it is imported once, then never written again.
 *
 * This file also holds the pure helpers shared by api.php and the CLI so there
 * is exactly one definition of each.
 *
 * Schema notes:
 *   competition_disciplines stores every discipline *instance* in order, so a
 *   repeated name ("pairs","singles","pairs") round-trips; game_scores.position
 *   is the index into that array, keeping the two aligned.
 */

// ---------------------------------------------------------------------------
// Connection & schema
// ---------------------------------------------------------------------------

/**
 * Open (and cache) the SQLite connection. PRAGMAs are applied on every call so
 * a freshly created connection always has them; a cached one is harmless to
 * re-apply. Throws on a missing pdo_sqlite extension or an unwritable file.
 */
function db(): PDO
{
	static $pdo = null;
	if (!($pdo instanceof PDO)) {
		$pdo = new PDO('sqlite:' . __DIR__ . '/draw.sqlite');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
	}
	// foreign_keys is per-connection, so (re)assert it on every call.
	$pdo->exec('PRAGMA foreign_keys = ON');
	$pdo->exec('PRAGMA journal_mode = WAL');
	$pdo->exec('PRAGMA busy_timeout = 5000');
	return $pdo;
}

/** Create the tables/indexes if absent, and record the schema version. */
function ensure_schema(PDO $pdo): void
{
	$statements = [
		'CREATE TABLE IF NOT EXISTS meta (
			key   TEXT PRIMARY KEY,
			value TEXT NOT NULL
		)',
		'CREATE TABLE IF NOT EXISTS competitions (
			id          INTEGER PRIMARY KEY,
			name        TEXT NOT NULL,
			num_players INTEGER NOT NULL DEFAULT 4,
			position    INTEGER NOT NULL
		)',
		'CREATE TABLE IF NOT EXISTS competition_disciplines (
			id             INTEGER PRIMARY KEY,
			competition_id INTEGER NOT NULL REFERENCES competitions(id) ON DELETE CASCADE,
			position       INTEGER NOT NULL,
			name           TEXT NOT NULL,
			UNIQUE(competition_id, position)
		)',
		'CREATE TABLE IF NOT EXISTS competition_conditions (
			competition_id INTEGER NOT NULL REFERENCES competitions(id) ON DELETE CASCADE,
			discipline     TEXT NOT NULL,
			num            INTEGER NOT NULL,
			type           TEXT NOT NULL,
			PRIMARY KEY (competition_id, discipline)
		)',
		'CREATE TABLE IF NOT EXISTS rounds (
			id             INTEGER PRIMARY KEY,
			competition_id INTEGER NOT NULL REFERENCES competitions(id) ON DELETE CASCADE,
			round_number   INTEGER NOT NULL,
			date           TEXT,
			position       INTEGER NOT NULL,
			UNIQUE(competition_id, position)
		)',
		'CREATE TABLE IF NOT EXISTS games (
			id         INTEGER PRIMARY KEY,
			round_id   INTEGER NOT NULL REFERENCES rounds(id) ON DELETE CASCADE,
			home_team  TEXT NOT NULL,
			away_team  TEXT NOT NULL,
			position   INTEGER NOT NULL,
			revision   INTEGER NOT NULL DEFAULT 1,
			updated_at TEXT,
			UNIQUE(round_id, position)
		)',
		'CREATE TABLE IF NOT EXISTS game_players (
			id       INTEGER PRIMARY KEY,
			game_id  INTEGER NOT NULL REFERENCES games(id) ON DELETE CASCADE,
			side     TEXT NOT NULL CHECK (side IN (\'home\',\'away\')),
			position INTEGER NOT NULL,
			name     TEXT NOT NULL,
			UNIQUE(game_id, side, position)
		)',
		'CREATE TABLE IF NOT EXISTS game_scores (
			id         INTEGER PRIMARY KEY,
			game_id    INTEGER NOT NULL REFERENCES games(id) ON DELETE CASCADE,
			position   INTEGER NOT NULL,
			discipline TEXT NOT NULL,
			home_score INTEGER,
			away_score INTEGER,
			home_ends  INTEGER,
			away_ends  INTEGER,
			UNIQUE(game_id, position)
		)',
		// Dispute submissions: one row per uploaded scorecard photo. The draw
		// details are denormalised into the row so the record stays readable
		// even if the game is later removed, and game_id is a plain reference
		// (not a cascading FK) so re-seeding the draw does not erase dispute
		// history while the matching photo files in disputes/ survive.
		'CREATE TABLE IF NOT EXISTS disputes (
			id           INTEGER PRIMARY KEY,
			game_id      INTEGER NOT NULL,
			competition  TEXT NOT NULL,
			round_number INTEGER NOT NULL,
			discipline   TEXT NOT NULL,
			home_team    TEXT NOT NULL,
			away_team    TEXT NOT NULL,
			user_name    TEXT NOT NULL,
			filename     TEXT NOT NULL,
			created_at   TEXT NOT NULL
		)',
		'CREATE INDEX IF NOT EXISTS idx_rounds_comp    ON rounds(competition_id, position)',
		'CREATE INDEX IF NOT EXISTS idx_games_round    ON games(round_id, position)',
		'CREATE INDEX IF NOT EXISTS idx_players_game   ON game_players(game_id, side, position)',
		'CREATE INDEX IF NOT EXISTS idx_scores_game    ON game_scores(game_id, position)',
		'CREATE INDEX IF NOT EXISTS idx_disputes_game  ON disputes(game_id)',
		'CREATE INDEX IF NOT EXISTS idx_disputes_made  ON disputes(created_at)',
	];
	foreach ($statements as $sql) {
		$pdo->exec($sql);
	}

	// Record the schema version, but only when it actually changes. A plain
	// INSERT OR IGNORE would be a no-op on an upgraded database (the row already
	// exists), leaving the stale version in place while the new tables are
	// created anyway, so the marker would no longer describe the schema.
	$current = $pdo->query("SELECT value FROM meta WHERE key = 'schema_version'")->fetchColumn();
	if ($current !== '2') {
		// One-time import of scorecard photos written before the disputes table
		// existed (the v1 upload flow only wrote the file). Best-effort and only
		// attempted during this upgrade, so it can never disturb live rows; a
		// failure is logged rather than blocking requests.
		try {
			backfill_disputes($pdo, __DIR__ . '/disputes');
		} catch (Throwable $e) {
			error_log('dispute_backfill_failed: ' . $e->getMessage());
		}
		$pdo->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)')
			->execute(['schema_version', '2']);
	}
}

/**
 * Seed the database from a JSON draw file, once. Guarded by a single write
 * transaction so two simultaneous first requests cannot both import: the
 * empty-check and the insert share one BEGIN IMMEDIATE, and the loser sees the
 * committed rows and skips.
 */
function seed_from_json(PDO $pdo, string $path): void
{
	// Fast path: once seeded, skip the write lock so loads stay cheap.
	$count = (int) $pdo->query('SELECT COUNT(*) FROM competitions')->fetchColumn();
	if ($count > 0 || !is_file($path)) {
		return;
	}

	// Re-check inside the write transaction so two first requests cannot both
	// seed: the loser sees the committed rows and skips.
	$pdo->exec('BEGIN IMMEDIATE');
	try {
		$count = (int) $pdo->query('SELECT COUNT(*) FROM competitions')->fetchColumn();
		if ($count === 0) {
			// A 'seeded' marker means a populated DB has gone missing (deleted,
			// corrupted, or a fresh checkout). Re-seeding the blank template
			// silently drops live results, so log it loudly before doing so.
			$seeded = $pdo->query("SELECT value FROM meta WHERE key = 'seeded'")->fetchColumn();
			if (is_string($seeded) && $seeded !== '') {
				error_log(
					'draw.sqlite has no competitions but was previously seeded at ' . $seeded
					. '; re-importing ' . $path
					. ' (live results may be lost — restore from backups/ if unintended).'
				);
			}

			$contents = @file_get_contents($path);
			if ($contents !== false) {
				$draw = json_decode($contents, true);
				if (is_array($draw)) {
					import_draw($pdo, $draw);
					$pdo->prepare("INSERT OR REPLACE INTO meta (key, value) VALUES ('seeded', ?)")
						->execute([date('c')]);
				}
			}
		}
		$pdo->exec('COMMIT');
	} catch (Throwable $e) {
		$pdo->exec('ROLLBACK');
		throw $e;
	}
}

/**
 * Insert one decoded draw array. Must be called inside an open transaction (the
 * caller owns BEGIN/COMMIT) so the whole import is atomic; uses the same
 * coercions as the old file writer (capped strings, numPlayers fallback 4).
 */
function import_draw(PDO $pdo, array $draw): void
{
	$insComp = $pdo->prepare(
		'INSERT INTO competitions (name, num_players, position) VALUES (?, ?, ?)'
	);
	$insDisc = $pdo->prepare(
		'INSERT INTO competition_disciplines (competition_id, position, name) VALUES (?, ?, ?)'
	);
	$insCond = $pdo->prepare(
		'INSERT INTO competition_conditions (competition_id, discipline, num, type) VALUES (?, ?, ?, ?)'
	);
	$insRound = $pdo->prepare(
		'INSERT INTO rounds (competition_id, round_number, date, position) VALUES (?, ?, ?, ?)'
	);
	$insGame = $pdo->prepare(
		'INSERT INTO games (round_id, home_team, away_team, position) VALUES (?, ?, ?, ?)'
	);

	$compPos = 0;
	foreach ($draw as $competition) {
		if (!is_array($competition)) {
			continue;
		}
		$numPlayers = filter_int($competition['numPlayers'] ?? null);
		if ($numPlayers === null || $numPlayers < 1) {
			$numPlayers = 4;
		}

		$insComp->execute([
			cap_str($competition['competition'] ?? '', 120),
			$numPlayers,
			$compPos,
		]);
		$competitionId = (int) $pdo->lastInsertId();

		$discipline = (isset($competition['discipline']) && is_array($competition['discipline']))
			? $competition['discipline']
			: [];
		$discPos = 0;
		foreach ($discipline as $name) {
			$insDisc->execute([
				$competitionId,
				$discPos,
				strtolower(cap_str($name, 40)),
			]);
			$discPos++;
		}

		$conditions = (isset($competition['conditions']) && is_array($competition['conditions']))
			? $competition['conditions']
			: [];
		foreach ($conditions as $name => $condition) {
			if (!is_array($condition)) {
				continue;
			}
			$num = filter_int($condition['num'] ?? null);
			if ($num === null) {
				continue;
			}
			$insCond->execute([
				$competitionId,
				strtolower(cap_str($name, 40)),
				$num,
				cap_str($condition['type'] ?? '', 40),
			]);
		}

		$rounds = (isset($competition['rounds']) && is_array($competition['rounds']))
			? $competition['rounds']
			: [];
		$roundPos = 0;
		foreach ($rounds as $round) {
			if (!is_array($round)) {
				continue;
			}
			$roundNumber = filter_int($round['round'] ?? null);
			if ($roundNumber === null) {
				$roundNumber = $roundPos + 1;
			}
			$date = cap_str($round['date'] ?? '', 40);
			$insRound->execute([
				$competitionId,
				$roundNumber,
				$date === '' ? null : $date,
				$roundPos,
			]);
			$roundId = (int) $pdo->lastInsertId();

			$games = (isset($round['games']) && is_array($round['games'])) ? $round['games'] : [];
			$gamePos = 0;
			foreach ($games as $game) {
				if (!is_array($game)) {
					continue;
				}
				$insGame->execute([
					$roundId,
					cap_str($game['homeTeam'] ?? '', 120),
					cap_str($game['awayTeam'] ?? '', 120),
					$gamePos,
				]);
				$gameId = (int) $pdo->lastInsertId();
				insert_game_players($pdo, $gameId, $game);
				insert_game_scores($pdo, $gameId, $game);

				$gamePos++;
			}
			$roundPos++;
		}
		$compPos++;
	}
}

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

/**
 * Assemble the nested draw in position order with the ids/revisions the client
 * needs. Runs inside a deferred read transaction so the several SELECTs see one
 * consistent snapshot and a concurrent save cannot produce a torn view.
 */
function load_draw(PDO $pdo): array
{
	$pdo->beginTransaction();
	try {
		$competitions = $pdo->query(
			'SELECT id, name, num_players FROM competitions ORDER BY position, id'
		)->fetchAll();

		// Load every child table with one set-based query each (no per-row
		// round-trips), then group in PHP. Ordering within each group preserves
		// the original position order.
		$disciplines = group_rows(
			$pdo->query('SELECT competition_id, name FROM competition_disciplines ORDER BY competition_id, position, id')->fetchAll(),
			'competition_id'
		);
		$conditions = group_rows(
			$pdo->query('SELECT competition_id, discipline, num, type FROM competition_conditions')->fetchAll(),
			'competition_id'
		);
		$rounds = group_rows(
			$pdo->query('SELECT id, competition_id, round_number, date FROM rounds ORDER BY competition_id, position, id')->fetchAll(),
			'competition_id'
		);
		$games = group_rows(
			$pdo->query('SELECT id, round_id, home_team, away_team, revision FROM games ORDER BY round_id, position, id')->fetchAll(),
			'round_id'
		);

		$gameIds = [];
		foreach ($games as $rows) {
			foreach ($rows as $row) {
				$gameIds[] = (int) $row['id'];
			}
		}
		$playersByGame = fetch_players_by_game($pdo, $gameIds);
		$scoresByGame = fetch_scores_by_game($pdo, $gameIds);

		$draw = [];
		foreach ($competitions as $comp) {
			$competitionId = (int) $comp['id'];

			$discipline = array_map(
				static fn(array $row): string => (string) $row['name'],
				$disciplines[$competitionId] ?? []
			);

			$conditionOut = [];
			foreach ($conditions[$competitionId] ?? [] as $row) {
				$conditionOut[(string) $row['discipline']] = [
					'num'  => (int) $row['num'],
					'type' => (string) $row['type'],
				];
			}

			$roundsOut = [];
			foreach ($rounds[$competitionId] ?? [] as $roundRow) {
				$roundId = (int) $roundRow['id'];

				$gamesOut = [];
				foreach ($games[$roundId] ?? [] as $gameRow) {
					$gameId = (int) $gameRow['id'];
					$gamesOut[] = build_game(
						$gameRow,
						$playersByGame[$gameId] ?? [],
						$scoresByGame[$gameId] ?? []
					);
				}

				$round = [
					'id'    => $roundId,
					'round' => (int) $roundRow['round_number'],
					'games' => $gamesOut,
				];
				if ($roundRow['date'] !== null && $roundRow['date'] !== '') {
					$round['date'] = (string) $roundRow['date'];
				}
				$roundsOut[] = $round;
			}

			$draw[] = [
				'id'          => $competitionId,
				'competition' => (string) $comp['name'],
				'discipline'  => $discipline,
				'conditions'  => $conditionOut,
				'numPlayers'  => (int) $comp['num_players'],
				'rounds'      => $roundsOut,
			];
		}

		$pdo->commit();
		return $draw;
	} catch (Throwable $e) {
		$pdo->rollBack();
		throw $e;
	}
}

/** Group fetched rows into a map keyed by an integer column (e.g. a parent id). */
function group_rows(array $rows, string $key): array
{
	$map = [];
	foreach ($rows as $row) {
		$map[(int) $row[$key]][] = $row;
	}
	return $map;
}

/** Players for many games at once: [gameId => ['home' => [...], 'away' => [...]]]. */
function fetch_players_by_game(PDO $pdo, array $gameIds): array
{
	if (!$gameIds) {
		return [];
	}
	$placeholders = implode(',', array_fill(0, count($gameIds), '?'));
	$stmt = $pdo->prepare(
		"SELECT game_id, side, name FROM game_players
		 WHERE game_id IN ({$placeholders}) ORDER BY game_id, side, position, id"
	);
	$stmt->execute(array_values($gameIds));

	$map = [];
	foreach ($stmt->fetchAll() as $row) {
		$map[(int) $row['game_id']][(string) $row['side']][] = (string) $row['name'];
	}
	return $map;
}

/** Scores for many games at once, in position order: [gameId => [score, ...]]. */
function fetch_scores_by_game(PDO $pdo, array $gameIds): array
{
	if (!$gameIds) {
		return [];
	}
	$placeholders = implode(',', array_fill(0, count($gameIds), '?'));
	$stmt = $pdo->prepare(
		"SELECT game_id, discipline, home_score, away_score, home_ends, away_ends
		 FROM game_scores WHERE game_id IN ({$placeholders}) ORDER BY game_id, position, id"
	);
	$stmt->execute(array_values($gameIds));

	$map = [];
	foreach ($stmt->fetchAll() as $row) {
		$map[(int) $row['game_id']][] = [
			'discipline' => (string) $row['discipline'],
			'homeScore'  => $row['home_score'] === null ? null : (int) $row['home_score'],
			'awayScore'  => $row['away_score'] === null ? null : (int) $row['away_score'],
			'homeEnds'   => $row['home_ends'] === null ? null : (int) $row['home_ends'],
			'awayEnds'   => $row['away_ends'] === null ? null : (int) $row['away_ends'],
		];
	}
	return $map;
}

/** Assemble one game's array from its row plus already-fetched players/scores. */
function build_game(array $row, array $players, array $scores): array
{
	return [
		'id'          => (int) $row['id'],
		'revision'    => (int) $row['revision'],
		'homeTeam'    => (string) $row['home_team'],
		'awayTeam'    => (string) $row['away_team'],
		'homePlayers' => $players['home'] ?? [],
		'awayPlayers' => $players['away'] ?? [],
		'scores'      => $scores,
	];
}

/**
 * True when a game has at least one saved score/ends value. A row that exists
 * with every value NULL (a blank discipline) does not count. This is the single
 * "has results" gate shared by game_summary() and the admin update path.
 */
function game_has_results(PDO $pdo, int $gameId): bool
{
	$stmt = $pdo->prepare(
		'SELECT EXISTS(
			SELECT 1 FROM game_scores
			WHERE game_id = ? AND (home_score IS NOT NULL OR away_score IS NOT NULL
				OR home_ends IS NOT NULL OR away_ends IS NOT NULL)
		)'
	);
	$stmt->execute([$gameId]);
	return (bool) $stmt->fetchColumn();
}

/**
 * True when any game in the round has a saved result. Drives the round-date
 * guard: a round's date may only be edited while it holds no results anywhere.
 */
function round_has_results(PDO $pdo, int $roundId): bool
{
	$stmt = $pdo->prepare(
		'SELECT EXISTS(
			SELECT 1 FROM game_scores s
			JOIN games g ON g.id = s.game_id
			WHERE g.round_id = ? AND (s.home_score IS NOT NULL OR s.away_score IS NOT NULL
				OR s.home_ends IS NOT NULL OR s.away_ends IS NOT NULL)
		)'
	);
	$stmt->execute([$roundId]);
	return (bool) $stmt->fetchColumn();
}

/**
 * The score positions in a game that already carry a result, as
 * position => true. The admin update path uses this to refuse an edit that
 * blanks a discipline holding a stored result.
 */
function game_result_positions(PDO $pdo, int $gameId): array
{
	$stmt = $pdo->prepare(
		'SELECT position FROM game_scores
		 WHERE game_id = ? AND (home_score IS NOT NULL OR away_score IS NOT NULL
			OR home_ends IS NOT NULL OR away_ends IS NOT NULL)'
	);
	$stmt->execute([$gameId]);
	$positions = [];
	foreach ($stmt->fetchAll() as $row) {
		$positions[(int) $row['position']] = true;
	}
	return $positions;
}

/**
 * Resolve the details needed to name a dispute photo, plus whether the game has
 * any saved results. Returns null when the game id is unknown.
 */
function game_summary(PDO $pdo, int $gameId): ?array
{
	$stmt = $pdo->prepare(
		'SELECT c.name AS competition, r.round_number AS round,
		        g.home_team AS homeTeam, g.away_team AS awayTeam
		 FROM games g
		 JOIN rounds r       ON r.id = g.round_id
		 JOIN competitions c ON c.id = r.competition_id
		 WHERE g.id = ?'
	);
	$stmt->execute([$gameId]);
	$row = $stmt->fetch();
	if ($row === false) {
		return null;
	}

	return [
		'competition' => (string) $row['competition'],
		'round'       => (int) $row['round'],
		'homeTeam'    => (string) $row['homeTeam'],
		'awayTeam'    => (string) $row['awayTeam'],
		'hasResults'  => game_has_results($pdo, $gameId),
	];
}

/**
 * The discipline/condition context a game's results must satisfy: the ordered
 * discipline names for its competition, plus that competition's conditions.
 * Returns null when the game id is unknown.
 */
function game_validation_context(PDO $pdo, int $gameId): ?array
{
	$stmt = $pdo->prepare(
		'SELECT r.competition_id AS comp
		 FROM games g JOIN rounds r ON r.id = g.round_id
		 WHERE g.id = ?'
	);
	$stmt->execute([$gameId]);
	$row = $stmt->fetch();
	if ($row === false) { return null; }

	$d = $pdo->prepare('SELECT position, name FROM competition_disciplines WHERE competition_id = ? ORDER BY position, id');
	$d->execute([(int) $row['comp']]);
	$disciplines = []; // position => lowercased name
	foreach ($d->fetchAll() as $r) {
		$disciplines[(int) $r['position']] = strtolower((string) $r['name']);
	}

	$c = $pdo->prepare('SELECT discipline, num, type FROM competition_conditions WHERE competition_id = ?');
	$c->execute([(int) $row['comp']]);
	$conditions = [];
	foreach ($c->fetchAll() as $r) {
		$conditions[strtolower((string) $r['discipline'])] = ['num' => (int) $r['num'], 'type' => strtolower((string) $r['type'])];
	}
	return ['disciplines' => $disciplines, 'conditions' => $conditions];
}

/**
 * Server-side mirror of the client rules in app.js/results.js
 * (conditionViolations + scoreEndsViolations). Only disciplines that carry a
 * complete submitted result are validated: an absent row, or one with any blank
 * value, is legacy/partial data the clients cannot produce, and rejecting it
 * would deadlock saves of such games (the inline editor is hidden once a game
 * has any result). Complete results are still fully checked: each side's score
 * must be >= its ends, and the competition condition is applied ("score": each
 * side <= num and both sides may not equal num together; "ends":
 * homeEnds + awayEnds <= num). Returns a list of error strings (empty = valid).
 * The rules here MUST stay in sync with the two JS copies.
 */
function validate_game(PDO $pdo, int $gameId, array $game): array
{
	$ctx = game_validation_context($pdo, $gameId);
	if ($ctx === null) { return ['game_not_found']; }

	// Index submitted scores by position; a negative/non-integer position is a
	// malformed payload.
	$byPos = [];
	$submitted = (isset($game['scores']) && is_array($game['scores'])) ? $game['scores'] : [];
	foreach ($submitted as $entry) {
		if (!is_array($entry)) { continue; }
		$pos = filter_int($entry['position'] ?? null);
		if ($pos === null || $pos < 0) { return ['bad_scores']; }
		$byPos[$pos] = $entry;
	}

	$errors = [];
	foreach ($ctx['disciplines'] as $pos => $name) {
		if (!in_array($name, ['singles', 'pairs', 'triples', 'fours'], true)) { continue; }
		$entry = $byPos[$pos] ?? null;
		if ($entry === null) { continue; } // no row for this discipline — nothing to validate
		$vals = [];
		foreach (['homeScore', 'awayScore', 'homeEnds', 'awayEnds'] as $f) {
			$vals[$f] = score_value($entry[$f] ?? null);
		}
		if (in_array(null, $vals, true)) { continue; } // partial/blank result — do not block a save

		// score >= ends, both sides
		foreach (['home', 'away'] as $side) {
			if ($vals[$side . 'Score'] < $vals[$side . 'Ends']) { $errors[] = "score_lt_ends:{$name}:{$side}"; }
		}
		// competition condition
		$cond = $ctx['conditions'][$name] ?? null;
		if ($cond !== null) {
			if ($cond['type'] === 'score') {
				if ($vals['homeScore'] > $cond['num']) { $errors[] = "score_over:{$name}:home"; }
				if ($vals['awayScore'] > $cond['num']) { $errors[] = "score_over:{$name}:away"; }
				if ($vals['homeScore'] === $cond['num'] && $vals['awayScore'] === $cond['num']) { $errors[] = "score_both_max:{$name}"; }
			} elseif ($cond['type'] === 'ends' && ($vals['homeEnds'] + $vals['awayEnds']) > $cond['num']) {
				$errors[] = "ends_over:{$name}";
			}
		}
	}
	return $errors;
}

/**
 * Every recorded dispute, newest first. Read-only. The stored game details
 * (competition/round/teams) are denormalised copies taken when the photo was
 * uploaded, so callers must resolve game_id against the current draw rather
 * than trusting them: after a re-seed the ids restart (INTEGER PRIMARY KEY)
 * and a stored game_id can silently point at a *different* game. Ordering by
 * created_at then id keeps same-second rows stable (created_at has only
 * second resolution).
 */
function load_disputes(PDO $pdo): array
{
	$rows = $pdo->query(
		'SELECT id, game_id, competition, round_number, discipline, home_team,
		        away_team, user_name, filename, created_at
		 FROM disputes
		 ORDER BY created_at DESC, id DESC'
	)->fetchAll();

	$out = [];
	foreach ($rows as $row) {
		$out[] = [
			'id'           => (int) $row['id'],
			'game_id'      => (int) $row['game_id'],
			'competition'  => (string) $row['competition'],
			'round_number' => (int) $row['round_number'],
			'discipline'   => (string) $row['discipline'],
			'home_team'    => (string) $row['home_team'],
			'away_team'    => (string) $row['away_team'],
			'user_name'    => (string) $row['user_name'],
			'filename'     => (string) $row['filename'],
			'created_at'   => (string) $row['created_at'],
		];
	}
	return $out;
}

/**
 * The stored photo filename for one dispute id, or null when the id is unknown.
 * Used by the admin-only image endpoint, which re-validates the name before
 * reading from disputes/.
 */
function dispute_filename(PDO $pdo, int $id): ?string
{
	$stmt = $pdo->prepare('SELECT filename FROM disputes WHERE id = ?');
	$stmt->execute([$id]);
	$row = $stmt->fetch();
	return $row === false ? null : (string) $row['filename'];
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------

/**
 * Insert a game's players, normalised the same way regardless of caller. Shared
 * by save_game() and import_draw() so both writers enforce one invariant.
 */
function insert_game_players(PDO $pdo, int $gameId, array $game): void
{
	$stmt = $pdo->prepare(
		'INSERT INTO game_players (game_id, side, position, name) VALUES (?, ?, ?, ?)'
	);
	$sides = [
		['home', normalise_players($game['homePlayers'] ?? [])],
		['away', normalise_players($game['awayPlayers'] ?? [])],
	];
	foreach ($sides as $pair) {
		foreach ($pair[1] as $position => $name) {
			$stmt->execute([$gameId, $pair[0], (int) $position, $name]);
		}
	}
}

/**
 * Insert a game's scores, normalised the same way regardless of caller. Keeps
 * each score's `position` (defaulting to array index) so scores stay aligned
 * with competition_disciplines. Shared by save_game() and import_draw().
 */
function insert_game_scores(PDO $pdo, int $gameId, array $game): void
{
	$stmt = $pdo->prepare(
		'INSERT INTO game_scores
			(game_id, position, discipline, home_score, away_score, home_ends, away_ends)
		 VALUES (?, ?, ?, ?, ?, ?, ?)'
	);
	$scores = is_array($game['scores'] ?? null) ? $game['scores'] : [];
	$index = 0;
	foreach ($scores as $score) {
		if (!is_array($score)) {
			continue;
		}
		$position = filter_int($score['position'] ?? null);
		if ($position === null) {
			$position = $index;
		}
		$stmt->execute([
			$gameId,
			$position,
			strtolower(cap_str($score['discipline'] ?? '', 40)),
			score_value($score['homeScore'] ?? null),
			score_value($score['awayScore'] ?? null),
			score_value($score['homeEnds'] ?? null),
			score_value($score['awayEnds'] ?? null),
		]);
		$index++;
	}
}

/**
 * Apply one game's edit under a per-game optimistic lock. Returns the new
 * revision, or the strings 'not_found' / 'conflict'. Uses BEGIN IMMEDIATE (not
 * beginTransaction(), which emits a deferred BEGIN) so the revision read and
 * the write are one atomic step: a concurrent writer cannot read the stale
 * revision before this one commits.
 *
 * Team names are immutable here: a save bumps the revision, refreshes the
 * players/scores, and never rewrites home_team/away_team (the fixture identity
 * is fixed by the draw). The game's players and scores are fully replaced.
 *
 * The return type is left untyped because the server runs PHP 7.4, which does
 * not support union type declarations (int|string).
 */
function save_game(PDO $pdo, int $gameId, int $revision, array $game)
{
	$pdo->exec('BEGIN IMMEDIATE');
	try {
		$stmt = $pdo->prepare('SELECT revision FROM games WHERE id = ?');
		$stmt->execute([$gameId]);
		$row = $stmt->fetch();
		if ($row === false) {
			$pdo->exec('ROLLBACK');
			return 'not_found';
		}
		if ((int) $row['revision'] !== $revision) {
			$pdo->exec('ROLLBACK');
			return 'conflict';
		}

		$upd = $pdo->prepare(
			'UPDATE games SET revision = revision + 1, updated_at = ?
			 WHERE id = ?'
		);
		$upd->execute([
			date('Y-m-d H:i:s'),
			$gameId,
		]);

		$pdo->prepare('DELETE FROM game_players WHERE game_id = ?')->execute([$gameId]);
		$pdo->prepare('DELETE FROM game_scores WHERE game_id = ?')->execute([$gameId]);
		insert_game_players($pdo, $gameId, $game);
		insert_game_scores($pdo, $gameId, $game);

		$pdo->exec('COMMIT');
		return $revision + 1;
	} catch (Throwable $e) {
		$pdo->exec('ROLLBACK');
		throw $e;
	}
}

/**
 * Admin-only write path for one game from the results page. Unlike the public
 * save_game() it may also rewrite the teams and the round's date, but only
 * while nothing has been scored:
 *   - a game that already has results: a supplied team or round-date change is
 *     rejected (returns 'teams_locked'); players/scores are still replaced;
 *   - a resultless game: home_team/away_team are written, and the round date is
 *     written only when $roundDate !== null and the round still has no results.
 *     A date that actually changes while the round has a result is rejected
 *     (returns 'date_locked') rather than silently dropped.
 * The per-game optimistic lock is the same BEGIN IMMEDIATE + revision check as
 * save_game() (see its comment for why the lock is immediate). Returns the new
 * revision, or 'not_found' / 'conflict' / 'teams_locked' / 'date_locked'.
 *
 * The return type is left untyped because the server runs PHP 7.4, which does
 * not support union type declarations (int|string).
 */
function update_game(PDO $pdo, int $gameId, int $revision, array $game, ?string $roundDate)
{
	$pdo->exec('BEGIN IMMEDIATE');
	try {
		$stmt = $pdo->prepare(
			'SELECT g.revision, g.home_team, g.away_team, g.round_id, r.date AS round_date
			 FROM games g
			 JOIN rounds r ON r.id = g.round_id
			 WHERE g.id = ?'
		);
		$stmt->execute([$gameId]);
		$row = $stmt->fetch();
		if ($row === false) {
			$pdo->exec('ROLLBACK');
			return 'not_found';
		}
		if ((int) $row['revision'] !== $revision) {
			$pdo->exec('ROLLBACK');
			return 'conflict';
		}

		$roundId = (int) $row['round_id'];

		if (game_has_results($pdo, $gameId)) {
			// Teams and date are fixed once a result exists: report a change
			// rather than quietly ignoring it.
			$homeTeam = cap_str($game['homeTeam'] ?? '', 120);
			$awayTeam = cap_str($game['awayTeam'] ?? '', 120);
			if ($homeTeam !== (string) $row['home_team'] || $awayTeam !== (string) $row['away_team']
				|| $roundDate !== null) {
				$pdo->exec('ROLLBACK');
				return 'teams_locked';
			}
		} else {
			// Resultless game: the teams and (guard permitting) the round date
			// are editable. Only a real date change is guarded, so re-sending the
			// unchanged value is never rejected.
			$pdo->prepare('UPDATE games SET home_team = ?, away_team = ? WHERE id = ?')->execute([
				cap_str($game['homeTeam'] ?? '', 120),
				cap_str($game['awayTeam'] ?? '', 120),
				$gameId,
			]);

			if ($roundDate !== null) {
				$newDate = cap_str($roundDate, 40);
				$newDate = $newDate === '' ? null : $newDate;
				$currentDate = $row['round_date'] === null ? null : (string) $row['round_date'];
				if ($newDate !== $currentDate) {
					if (round_has_results($pdo, $roundId)) {
						$pdo->exec('ROLLBACK');
						return 'date_locked';
					}
					$pdo->prepare('UPDATE rounds SET date = ? WHERE id = ?')->execute([$newDate, $roundId]);
				}
			}
		}

		$pdo->prepare('UPDATE games SET revision = revision + 1, updated_at = ? WHERE id = ?')
			->execute([date('Y-m-d H:i:s'), $gameId]);

		$pdo->prepare('DELETE FROM game_players WHERE game_id = ?')->execute([$gameId]);
		$pdo->prepare('DELETE FROM game_scores WHERE game_id = ?')->execute([$gameId]);
		insert_game_players($pdo, $gameId, $game);
		insert_game_scores($pdo, $gameId, $game);

		$pdo->exec('COMMIT');
		return $revision + 1;
	} catch (Throwable $e) {
		$pdo->exec('ROLLBACK');
		throw $e;
	}
}

/**
 * Admin-only reset of one game from the results page: removes the game's scores
 * and players, leaving the fixture (with its teams and round) in place. This is
 * the one path that may erase a stored result — the client confirms before
 * calling it, and no score rules apply since nothing is written back.
 *
 * The per-game optimistic lock is the same BEGIN IMMEDIATE + revision check as
 * save_game()/update_game() (see their comments for why the lock is immediate),
 * so a reset cannot race a concurrent save. Returns the new revision, or
 * 'not_found' / 'conflict'.
 *
 * The return type is left untyped because the server runs PHP 7.4, which does
 * not support union type declarations (int|string).
 */
function reset_game(PDO $pdo, int $gameId, int $revision)
{
	$pdo->exec('BEGIN IMMEDIATE');
	try {
		$stmt = $pdo->prepare('SELECT revision FROM games WHERE id = ?');
		$stmt->execute([$gameId]);
		$row = $stmt->fetch();
		if ($row === false) {
			$pdo->exec('ROLLBACK');
			return 'not_found';
		}
		if ((int) $row['revision'] !== $revision) {
			$pdo->exec('ROLLBACK');
			return 'conflict';
		}

		$pdo->prepare('UPDATE games SET revision = revision + 1, updated_at = ? WHERE id = ?')
			->execute([date('Y-m-d H:i:s'), $gameId]);
		$pdo->prepare('DELETE FROM game_players WHERE game_id = ?')->execute([$gameId]);
		$pdo->prepare('DELETE FROM game_scores WHERE game_id = ?')->execute([$gameId]);

		$pdo->exec('COMMIT');
		return $revision + 1;
	} catch (Throwable $e) {
		$pdo->exec('ROLLBACK');
		throw $e;
	}
}

/**
 * Append one fixture to an existing round with no players or scores. The round
 * is verified first (an unknown id throws RuntimeException('round_not_found'))
 * and the position is one past the round's current maximum (0 when the round is
 * empty). BEGIN IMMEDIATE serialises concurrent adds, so the MAX(position)
 * read and the insert are one step and cannot collide on UNIQUE(round_id,
 * position); a caller that loses a race waits on the lock rather than failing.
 * Returns the new game id.
 */
function insert_game(PDO $pdo, int $roundId, string $homeTeam, string $awayTeam): int
{
	$check = $pdo->prepare('SELECT 1 FROM rounds WHERE id = ?');
	$check->execute([$roundId]);
	if ($check->fetchColumn() === false) {
		throw new RuntimeException('round_not_found');
	}

	$pdo->exec('BEGIN IMMEDIATE');
	try {
		$pos = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM games WHERE round_id = ?');
		$pos->execute([$roundId]);
		$position = (int) $pos->fetchColumn();

		$ins = $pdo->prepare(
			'INSERT INTO games (round_id, home_team, away_team, position) VALUES (?, ?, ?, ?)'
		);
		$ins->execute([$roundId, cap_str($homeTeam, 120), cap_str($awayTeam, 120), $position]);
		$id = (int) $pdo->lastInsertId();

		$pdo->exec('COMMIT');
		return $id;
	} catch (Throwable $e) {
		$pdo->exec('ROLLBACK');
		throw $e;
	}
}

/**
 * Record one uploaded dispute. Takes the game details already resolved by
 * game_summary() (competition, round, teams) plus the stored photo filename, and
 * denormalises them into the row so the record is self-describing. Returns the
 * new dispute id. Not wrapped in its own transaction: the caller writes the
 * photo file first, then records it here, so a failure here leaves the photo in
 * place rather than a row pointing at a file that was never written.
 */
function insert_dispute(
	PDO $pdo,
	int $gameId,
	string $userName,
	string $discipline,
	array $summary,
	string $filename
): int {
	$stmt = $pdo->prepare(
		'INSERT INTO disputes
			(game_id, competition, round_number, discipline, home_team, away_team, user_name, filename, created_at)
		 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
	);
	$stmt->execute([
		$gameId,
		$summary['competition'],
		$summary['round'],
		strtolower(cap_str($discipline, 40)),
		$summary['homeTeam'],
		$summary['awayTeam'],
		$userName,
		$filename,
		date('Y-m-d H:i:s'),
	]);
	return (int) $pdo->lastInsertId();
}

/**
 * Delete one recorded dispute and return its stored photo filename, or null
 * when the id is unknown. The row is removed inside its own write transaction
 * and the filename it named is returned so the caller can also remove the photo
 * from disputes/; the two are separate steps because the file lives outside the
 * database, and a row gone with a leftover file is harmless (the file is never
 * listed once the row is gone).
 */
function delete_dispute(PDO $pdo, int $id): ?string
{
	$pdo->exec('BEGIN IMMEDIATE');
	try {
		$stmt = $pdo->prepare('SELECT filename FROM disputes WHERE id = ?');
		$stmt->execute([$id]);
		$row = $stmt->fetch();
		if ($row === false) {
			$pdo->exec('ROLLBACK');
			return null;
		}
		$pdo->prepare('DELETE FROM disputes WHERE id = ?')->execute([$id]);
		$pdo->exec('COMMIT');
		return (string) $row['filename'];
	} catch (Throwable $e) {
		$pdo->exec('ROLLBACK');
		throw $e;
	}
}

/**
 * One-time, best-effort import of scorecard photos written to disputes/ before
 * the disputes table existed. Idempotent: it does nothing unless the table is
 * empty (and it is only called during the schema-1 -> 2 upgrade). Each filename
 * is parsed and resolved against the current draw by slugged competition, round
 * and teams; a resolved file adopts the draw's real names (so it stays editable
 * in results.php), while an unresolved file is still listed with game_id 0 and
 * de-slugged names (shown read-only). Files that do not match the naming shape
 * are skipped and logged. Returns the number of rows inserted.
 */
function backfill_disputes(PDO $pdo, string $dir): int
{
	if (!is_dir($dir)) {
		return 0;
	}
	// Never merge into a populated table (guards a re-run before the version
	// marker is written).
	$count = (int) $pdo->query('SELECT COUNT(*) FROM disputes')->fetchColumn();
	if ($count > 0) {
		return 0;
	}
	$files = glob($dir . '/dispute_*.jpg');
	if ($files === false || !$files) {
		return 0;
	}

	// Index the current draw by slugged identity so a filename resolves back to
	// a real game (and its real, un-slugged names).
	$games = $pdo->query(
		'SELECT g.id, g.home_team, g.away_team, r.round_number, c.name AS competition
		 FROM games g
		 JOIN rounds r       ON r.id = g.round_id
		 JOIN competitions c ON c.id = r.competition_id'
	)->fetchAll();
	$index = [];
	foreach ($games as $game) {
		$key = sanitise_slug($game['competition']) . '|' . (int) $game['round_number']
			. '|' . sanitise_slug($game['home_team']) . '|' . sanitise_slug($game['away_team']);
		// First match wins; competition + round + both teams is specific enough.
		if (!isset($index[$key])) {
			$index[$key] = $game;
		}
	}

	$insert = $pdo->prepare(
		'INSERT INTO disputes
			(game_id, competition, round_number, discipline, home_team, away_team, user_name, filename, created_at)
		 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
	);

	$inserted = 0;
	foreach ($files as $path) {
		if (is_link($path) || !is_file($path)) {
			continue;
		}
		$name = basename($path);
		$parsed = parse_dispute_filename($name);
		if ($parsed === null) {
			error_log('dispute_backfill_skipped: ' . $name);
			continue;
		}

		$key = $parsed['comp_slug'] . '|' . $parsed['round']
			. '|' . $parsed['home_slug'] . '|' . $parsed['away_slug'];
		$game = $index[$key] ?? null;

		if ($game !== null) {
			$gameId = (int) $game['id'];
			$competition = (string) $game['competition'];
			$round = (int) $game['round_number'];
			$home = (string) $game['home_team'];
			$away = (string) $game['away_team'];
		} else {
			// Unresolved: keep the photo visible read-only; de-slug for display.
			$gameId = 0;
			$competition = str_replace('-', ' ', $parsed['comp_slug']);
			$round = $parsed['round'];
			$home = str_replace('-', ' ', $parsed['home_slug']);
			$away = str_replace('-', ' ', $parsed['away_slug']);
		}

		$insert->execute([
			$gameId,
			$competition,
			$round,
			strtolower($parsed['discipline_slug']),
			$home,
			$away,
			$parsed['scorer_slug'],
			$name,
			$parsed['created_at'],
		]);
		$inserted++;
	}
	return $inserted;
}

/**
 * Parse a stored dispute filename back into its parts. Mirrors the shape
 * build_dispute_filename() produces:
 *   dispute_<scorer>_<competition>_R<round>_[<discipline>_]<home>-vs-<away>_<Ymd-His>.jpg
 * Underscores inside a slug make the split heuristic rather than exact, so this
 * is only used for the one-off backfill of pre-existing photos; returns null
 * when the name does not match.
 */
function parse_dispute_filename(string $name): ?array
{
	if (!preg_match('/^dispute_(.+)\.jpg$/', $name, $m)) {
		return null;
	}
	$stem = $m[1];

	// Strip the trailing timestamp, then the "<home>-vs-<away>" segment.
	$pos = strrpos($stem, '_');
	if ($pos === false) {
		return null;
	}
	$timestamp = substr($stem, $pos + 1);
	$stem = substr($stem, 0, $pos);

	$pos = strrpos($stem, '_');
	if ($pos === false) {
		return null;
	}
	$teams = substr($stem, $pos + 1);
	$stem = substr($stem, 0, $pos);

	$vs = strpos($teams, '-vs-');
	if ($vs === false) {
		return null;
	}
	$homeSlug = substr($teams, 0, $vs);
	$awaySlug = substr($teams, $vs + strlen('-vs-'));

	// "<scorer>_<competition>_R<round>[_<discipline>]".
	$parts = explode('_', $stem);
	$roundIndex = -1;
	foreach ($parts as $i => $part) {
		if (preg_match('/^R\d+$/', $part)) {
			$roundIndex = $i;
			break;
		}
	}
	if ($roundIndex < 1) {
		return null;
	}

	$createdAt = parse_dispute_timestamp($timestamp);
	if ($createdAt === null) {
		return null;
	}

	return [
		'comp_slug'       => implode('_', array_slice($parts, 1, $roundIndex - 1)),
		'round'           => (int) substr($parts[$roundIndex], 1),
		'home_slug'       => $homeSlug,
		'away_slug'       => $awaySlug,
		'discipline_slug' => implode('_', array_slice($parts, $roundIndex + 1)),
		'scorer_slug'     => $parts[0],
		'created_at'      => $createdAt,
	];
}

/** `Ymd-His` -> `Y-m-d H:i:s`, or null when the value does not round-trip. */
function parse_dispute_timestamp(string $ts): ?string
{
	$dt = DateTime::createFromFormat('Ymd-His', $ts);
	if ($dt === false || $dt->format('Ymd-His') !== $ts) {
		return null;
	}
	return $dt->format('Y-m-d H:i:s');
}

/** Pretty JSON export of the whole draw, for backups and the CLI. */
function export_draw_json(PDO $pdo): string
{
	return json_with_tabs(load_draw($pdo));
}

// ---------------------------------------------------------------------------
// Shared pure helpers (used by api.php and the CLI)
// ---------------------------------------------------------------------------

/**
 * Encode as pretty JSON, then convert each line's leading 4-space run to tabs so
 * the output matches the existing draw.json formatting. Only leading whitespace
 * is touched — never a global replace, which could alter string values.
 */
function json_with_tabs(array $data): string
{
	$json = json_encode(
		$data,
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	);
	if ($json === false) {
		throw new RuntimeException('encode_failed');
	}

	$lines = preg_split('/\r\n|\r|\n/', $json) ?: [];
	foreach ($lines as $i => $line) {
		$lines[$i] = preg_replace_callback(
			'/^( +)/',
			static function (array $m): string {
				$spaces = strlen($m[1]);
				return str_repeat("\t", intdiv($spaces, 4)) . str_repeat(' ', $spaces % 4);
			},
			$line
		);
	}

	return implode("\n", $lines) . "\n";
}

/** Accept int, "12", or 12.0; anything else (including null) -> null. */
function filter_int($value): ?int
{
	if (is_int($value)) {
		return $value;
	}
	if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
		return (int) $value;
	}
	if (is_float($value) && floor($value) === $value) {
		return (int) $value;
	}
	return null;
}

/** Trim a scalar to a string and cap its length. */
function cap_str($value, int $max): string
{
	$value = is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
	$value = trim($value);
	if (mb_strlen($value) > $max) {
		$value = mb_substr($value, 0, $max);
	}
	return $value;
}

/** Score/ends value -> non-negative int, or null when blank/invalid. */
function score_value($value): ?int
{
	$int = filter_int($value);
	if ($int === null || $int < 0) {
		return null;
	}
	return $int;
}

/** Make a filesystem-safe, non-empty scorer name. */
function sanitise_user($value): string
{
	return sanitise_slug($value, 40);
}

/**
 * Normalise a human display name for storage: preserve spaces/case, strip
 * control characters and collapse runs of whitespace, cap the length, and fall
 * back to "unknown". Distinct from sanitise_user(), which slugs for filenames.
 */
function sanitise_display_name($value): string
{
	$name = cap_str($value, 60);
	$name = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '';
	$name = trim($name);
	return $name === '' ? 'unknown' : $name;
}

/**
 * Make a filesystem-safe, non-empty slug for a filename segment. Mirrors
 * sanitise_user() but caps to a caller-chosen length; only [A-Za-z0-9_-] survives.
 */
function sanitise_slug($value, int $max = 40): string
{
	$slug = cap_str($value, 120);
	$slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $slug) ?? '';
	$slug = trim($slug, '-');
	if ($slug === '') {
		return 'unknown';
	}
	return substr($slug, 0, $max);
}

/**
 * Build the stored dispute filename from already-resolved game details. Client
 * filenames are never trusted: every text segment is slugged and the caller
 * supplies the server timestamp verbatim.
 */
function build_dispute_filename(
	string $scorer,
	string $competition,
	int $round,
	string $discipline,
	string $home,
	string $away,
	string $timestamp
): string {
	$segments = [
		'dispute',
		sanitise_slug($scorer),
		sanitise_slug($competition),
		'R' . $round,
	];
	// Only add the discipline segment when supplied, so a whole-game dispute
	// keeps the original filename shape.
	if ($discipline !== '') {
		$segments[] = sanitise_slug($discipline);
	}
	$segments[] = sanitise_slug($home) . '-vs-' . sanitise_slug($away);
	$segments[] = $timestamp;
	$name = implode('_', $segments) . '.jpg';

	// Keep the whole basename within a sane filesystem limit.
	if (strlen($name) > 180) {
		$name = substr($name, 0, 180 - strlen('.jpg')) . '.jpg';
	}
	return $name;
}

/** Normalise the player list to trimmed, non-empty, capped strings. */
function normalise_players($value): array
{
	if (!is_array($value)) {
		return [];
	}
	$out = [];
	foreach ($value as $entry) {
		$name = cap_str($entry, 80);
		if ($name !== '') {
			$out[] = $name;
		}
	}
	return array_slice($out, 0, 64);
}

/**
 * Normalise one game object: coerce scores/ends to ints, keep each score's
 * original `position` (falling back to its array index) so scores stay aligned
 * with competition_disciplines, and keep any unknown top-level keys intact.
 * Ends are kept for every discipline, including singles.
 */
function normalise_game(array $game): array
{
	$out = $game;
	$out['homeTeam'] = cap_str($game['homeTeam'] ?? '', 120);
	$out['awayTeam'] = cap_str($game['awayTeam'] ?? '', 120);
	$out['homePlayers'] = normalise_players($game['homePlayers'] ?? []);
	$out['awayPlayers'] = normalise_players($game['awayPlayers'] ?? []);

	$scores = [];
	$incoming = (isset($game['scores']) && is_array($game['scores'])) ? $game['scores'] : [];
	$index = 0;
	foreach ($incoming as $entry) {
		if (!is_array($entry)) {
			continue;
		}
		$position = filter_int($entry['position'] ?? null);
		$discipline = strtolower(cap_str($entry['discipline'] ?? '', 40));
		$scores[] = [
			'position'   => $position === null ? $index : $position,
			'discipline' => $discipline,
			'homeScore'  => score_value($entry['homeScore'] ?? null),
			'awayScore'  => score_value($entry['awayScore'] ?? null),
			'homeEnds'   => score_value($entry['homeEnds'] ?? null),
			'awayEnds'   => score_value($entry['awayEnds'] ?? null),
		];
		$index++;
	}
	$out['scores'] = $scores;

	return $out;
}

/**
 * Write image bytes into disputes/ under a unique name, created atomically with
 * fopen($path, 'x') so two concurrent disputes can never overwrite each other.
 * Collisions get a -1, -2, ... suffix. Returns the stored basename, or null when
 * the write fails; the retry loop is capped so a pathological name clash fails
 * cleanly instead of spinning.
 */
function write_dispute_file(string $dir, string $basename, string $bytes): ?string
{
	$dot = strrpos($basename, '.');
	$stem = $dot === false ? $basename : substr($basename, 0, $dot);
	$ext  = $dot === false ? '' : substr($basename, $dot);

	$attempts = 100;
	for ($i = 0; $i < $attempts; $i++) {
		$name = $i === 0 ? $basename : $stem . '-' . $i . $ext;
		$path = $dir . '/' . $name;

		$fh = @fopen($path, 'x');
		if ($fh === false) {
			continue; // name already taken — try the next suffix
		}
		$written = fwrite($fh, $bytes);
		fclose($fh);
		if ($written === strlen($bytes)) {
			return $name;
		}
		@unlink($path); // never leave a truncated file behind
		return null;
	}
	return null;
}
