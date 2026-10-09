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
		'CREATE INDEX IF NOT EXISTS idx_rounds_comp  ON rounds(competition_id, position)',
		'CREATE INDEX IF NOT EXISTS idx_games_round  ON games(round_id, position)',
		'CREATE INDEX IF NOT EXISTS idx_players_game ON game_players(game_id, side, position)',
		'CREATE INDEX IF NOT EXISTS idx_scores_game  ON game_scores(game_id, position)',
	];
	foreach ($statements as $sql) {
		$pdo->exec($sql);
	}

	$ins = $pdo->prepare('INSERT OR IGNORE INTO meta (key, value) VALUES (?, ?)');
	$ins->execute(['schema_version', '1']);
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

	$hasStmt = $pdo->prepare(
		'SELECT EXISTS(
			SELECT 1 FROM game_scores
			WHERE game_id = ? AND (home_score IS NOT NULL OR away_score IS NOT NULL
				OR home_ends IS NOT NULL OR away_ends IS NOT NULL)
		)'
	);
	$hasStmt->execute([$gameId]);

	return [
		'competition' => (string) $row['competition'],
		'round'       => (int) $row['round'],
		'homeTeam'    => (string) $row['homeTeam'],
		'awayTeam'    => (string) $row['awayTeam'],
		'hasResults'  => (bool) $hasStmt->fetchColumn(),
	];
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
 */
function save_game(PDO $pdo, int $gameId, int $revision, array $game): int|string
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
			'UPDATE games SET home_team = ?, away_team = ?, revision = revision + 1, updated_at = ?
			 WHERE id = ?'
		);
		$upd->execute([
			$game['homeTeam'] ?? '',
			$game['awayTeam'] ?? '',
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
