<?php
declare(strict_types=1);

/**
 * Rooster Cup Score Sheet — API endpoint.
 *
 * Actions:
 *   GET  api.php?action=load  -> { success, version, draw }
 *   POST api.php?action=save  -> { success, version, backup } or an error
 *
 * Save is a targeted update of one game inside draw.json, guarded by an exclusive
 * lock and an md5 "version" conflict check. The file is replaced atomically (temp
 * file + rename) and a post-save backup copy is written to backups/. The lock uses
 * a separate lock file because Windows cannot replace a file that is held open.
 *
 * Run with: php -S localhost:8000   (then open http://localhost:8000/)
 */

$DATA_FILE  = __DIR__ . '/draw.json';
$BACKUP_DIR = __DIR__ . '/backups';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Emit a JSON response and stop. */
function respond(int $status, array $payload): void
{
	http_response_code($status);
	echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

/**
 * Encode as pretty JSON, then convert each line's leading 4-space run to tabs so
 * the file matches the existing draw.json formatting. Only leading whitespace is
 * touched — never a global replace, which could alter string values.
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
	$name = cap_str($value, 80);
	if ($name === '') {
		return 'unknown';
	}
	$name = preg_replace('/[^A-Za-z0-9_-]+/', '-', $name) ?? '';
	$name = trim($name, '-');
	if ($name === '') {
		return 'unknown';
	}
	return substr($name, 0, 40);
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
 * Normalise one game object: coerce scores/ends to ints and keep any unknown
 * top-level keys intact. Ends are kept for every discipline, including singles.
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
	foreach ($incoming as $entry) {
		if (!is_array($entry)) {
			continue;
		}
		$discipline = strtolower(cap_str($entry['discipline'] ?? '', 40));
		$scores[] = [
			'discipline' => $discipline,
			'homeScore'  => score_value($entry['homeScore'] ?? null),
			'awayScore'  => score_value($entry['awayScore'] ?? null),
			'homeEnds'   => score_value($entry['homeEnds'] ?? null),
			'awayEnds'   => score_value($entry['awayEnds'] ?? null),
		];
	}
	$out['scores'] = $scores;

	return $out;
}

/**
 * Copy the saved data file into backups/, returning the backup filename or null.
 * Name: draw_<user>_<YYYYMMDD-HHMMSS>.json, with a numeric suffix on collision.
 */
function backup_file(string $dataFile, string $backupDir, string $user): ?string
{
	if (!is_dir($backupDir)) {
		if (!@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
			return null;
		}
	}

	$base = 'draw_' . $user . '_' . date('Ymd-His');
	$path = $backupDir . '/' . $base . '.json';
	$i = 1;
	while (file_exists($path)) {
		$path = $backupDir . '/' . $base . '-' . $i . '.json';
		$i++;
	}

	if (!@copy($dataFile, $path)) {
		return null;
	}
	return basename($path);
}

/**
 * Take an advisory lock without opening the data file itself (Windows cannot
 * replace a file that is held open, so the lock lives in a separate file).
 */
function acquire_lock(string $dataFile, int $mode)
{
	$lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
		. 'rooster-draw-' . md5($dataFile) . '.lock';
	$fh = @fopen($lockPath, 'c');
	if ($fh === false) {
		respond(500, ['success' => false, 'error' => 'lock_failed']);
	}
	if (!flock($fh, $mode)) {
		fclose($fh);
		respond(500, ['success' => false, 'error' => 'lock_failed']);
	}
	return $fh;
}

/** Release a lock handle returned by acquire_lock(). */
function release_lock($fh): void
{
	if (is_resource($fh)) {
		flock($fh, LOCK_UN);
		fclose($fh);
	}
}

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------

function handle_load(string $dataFile): void
{
	$lock = acquire_lock($dataFile, LOCK_SH);
	$contents = @file_get_contents($dataFile);
	release_lock($lock);

	if ($contents === false) {
		respond(500, ['success' => false, 'error' => 'data_unreadable']);
	}

	$draw = json_decode($contents, true);
	if (!is_array($draw)) {
		respond(500, ['success' => false, 'error' => 'data_corrupt']);
	}

	respond(200, [
		'success' => true,
		'version' => md5($contents),
		'draw'    => $draw,
	]);
}

function handle_save(string $dataFile, string $backupDir): void
{
	$raw = file_get_contents('php://input');
	if ($raw === false || trim($raw) === '') {
		respond(400, ['success' => false, 'error' => 'empty_body']);
	}

	$input = json_decode($raw, true);
	if (!is_array($input) || !isset($input['game']) || !is_array($input['game'])) {
		respond(400, ['success' => false, 'error' => 'bad_body']);
	}

	$version = (isset($input['version']) && is_string($input['version'])) ? $input['version'] : '';
	$ci = filter_int($input['competitionIndex'] ?? null);
	$ri = filter_int($input['roundIndex'] ?? null);
	$gi = filter_int($input['gameIndex'] ?? null);
	if ($ci === null || $ri === null || $gi === null || $ci < 0 || $ri < 0 || $gi < 0) {
		respond(400, ['success' => false, 'error' => 'bad_indices']);
	}

	$userName = sanitise_user($input['userName'] ?? '');

	$lock = acquire_lock($dataFile, LOCK_EX);

	try {
		$contents = @file_get_contents($dataFile);
		if ($contents === false) {
			respond(500, ['success' => false, 'error' => 'data_unreadable']);
		}

		// Conflict: someone else wrote the file since this page loaded it.
		if (md5($contents) !== $version) {
			$currentDraw = json_decode($contents, true);
			respond(409, [
				'success' => false,
				'error'   => 'conflict',
				'version' => md5($contents),
				'draw'    => is_array($currentDraw) ? $currentDraw : [],
			]);
		}

		$draw = json_decode($contents, true);
		if (!is_array($draw)) {
			respond(500, ['success' => false, 'error' => 'data_corrupt']);
		}

		// Indices must point at an existing game.
		if (!isset($draw[$ci]['rounds'][$ri]['games'][$gi])
			|| !is_array($draw[$ci]['rounds'][$ri]['games'][$gi])) {
			respond(400, ['success' => false, 'error' => 'indices_out_of_range']);
		}

		$draw[$ci]['rounds'][$ri]['games'][$gi] = normalise_game($input['game']);

		try {
			$newContents = json_with_tabs($draw);
		} catch (Throwable $e) {
			respond(500, ['success' => false, 'error' => 'encode_failed']);
		}

		// Atomic replace: write a sibling temp file, then rename over draw.json.
		$tmp = $dataFile . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
		if (file_put_contents($tmp, $newContents) === false) {
			@unlink($tmp);
			respond(500, ['success' => false, 'error' => 'write_failed']);
		}

		if (!@rename($tmp, $dataFile)) {
			// Windows/edge fallback: remove the old file then rename.
			if (!(@unlink($dataFile) && @rename($tmp, $dataFile))) {
				@unlink($tmp);
				respond(500, ['success' => false, 'error' => 'replace_failed']);
			}
		}
	} finally {
		release_lock($lock);
	}

	$newVersion = md5_file($dataFile);
	$backupName = backup_file($dataFile, $backupDir, $userName);

	respond(200, [
		'success' => true,
		'version' => $newVersion,
		'backup'  => $backupName,
	]);
}

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'load';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && $action === 'load') {
	handle_load($DATA_FILE);
}

if ($method === 'POST' && $action === 'save') {
	handle_save($DATA_FILE, $BACKUP_DIR);
}

respond(405, ['success' => false, 'error' => 'method_not_allowed']);
