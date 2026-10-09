<?php
declare(strict_types=1);

/**
 * Rooster Cup Score Sheet — API endpoint.
 *
 * Actions:
 *   GET  api.php?action=load     -> { success, version, draw }
 *   POST api.php?action=save     -> { success, version, backup } or an error
 *   POST api.php?action=dispute  -> { success, file } or an error
 *
 * Save is a targeted update of one game inside draw.json, guarded by an exclusive
 * lock and an md5 "version" conflict check. The file is replaced atomically (temp
 * file + rename) and a post-save backup copy is written to backups/. The lock uses
 * a separate lock file because Windows cannot replace a file that is held open.
 *
 * Dispute writes a compressed scorecard photo into disputes/, named from the
 * server clock and the game details taken out of draw.json (read under a shared
 * lock). It never modifies draw.json, its version, results or backups.
 *
 * Run with: php -S localhost:8000   (then open http://localhost:8000/)
 */

$DATA_FILE   = __DIR__ . '/draw.json';
$BACKUP_DIR  = __DIR__ . '/backups';
$DISPUTE_DIR = __DIR__ . '/disputes';

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

/** True when a game has at least one saved score/ends value (matches the client gate). */
function game_has_results(array $game): bool
{
	$scores = (isset($game['scores']) && is_array($game['scores'])) ? $game['scores'] : [];
	foreach ($scores as $entry) {
		if (!is_array($entry)) {
			continue;
		}
		foreach (['homeScore', 'awayScore', 'homeEnds', 'awayEnds'] as $field) {
			if (($entry[$field] ?? null) !== null) {
				return true;
			}
		}
	}
	return false;
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
 * Write image bytes into disputes/ under a unique name, created atomically with
 * fopen($path, 'x') so two concurrent disputes can never overwrite each other.
 * Collisions get a -1, -2, ... suffix (like backup_file()). Returns the stored
 * basename, or null when the write fails; the retry loop is capped so a
 * pathological name clash fails cleanly instead of spinning.
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

/**
 * Save a compressed scorecard photo for one game into disputes/. The photo is
 * base64 JPEG in the JSON body; the filename is built server-side from the game
 * details read under a shared lock. draw.json is never modified.
 */
function handle_dispute(string $dataFile, string $disputeDir): void
{
	$raw = file_get_contents('php://input');
	if ($raw === false || trim($raw) === '') {
		respond(400, ['success' => false, 'error' => 'empty_body']);
	}

	// Bound the raw body before decoding: base64 in JSON is ~4/3 of its payload,
	// so this rejects oversize uploads without materialising the decoded image.
	if (strlen($raw) > 12 * 1024 * 1024) {
		respond(413, ['success' => false, 'error' => 'too_large']);
	}

	$input = json_decode($raw, true);
	if (!is_array($input)) {
		respond(400, ['success' => false, 'error' => 'bad_body']);
	}

	$ci = filter_int($input['competitionIndex'] ?? null);
	$ri = filter_int($input['roundIndex'] ?? null);
	$gi = filter_int($input['gameIndex'] ?? null);
	if ($ci === null || $ri === null || $gi === null || $ci < 0 || $ri < 0 || $gi < 0) {
		respond(400, ['success' => false, 'error' => 'bad_indices']);
	}

	$userName = sanitise_user($input['userName'] ?? '');
	$discipline = cap_str($input['discipline'] ?? '', 40);

	// Resolve the game details under a shared lock (same read path as handle_load).
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

	if (!isset($draw[$ci]['rounds'][$ri]['games'][$gi])
		|| !is_array($draw[$ci]['rounds'][$ri]['games'][$gi])) {
		respond(400, ['success' => false, 'error' => 'indices_out_of_range']);
	}

	$competition = $draw[$ci];
	$round = $competition['rounds'][$ri];
	$game = $round['games'][$gi];

	// Defensive: the client also gates this, but a dispute needs saved results.
	if (!game_has_results($game)) {
		respond(400, ['success' => false, 'error' => 'no_results']);
	}

	// Decode the image: JPEG only (the client always re-encodes), verified by the
	// data-URL prefix and the JPEG magic bytes so a .jpg file never holds PNG bytes.
	$image = $input['image'] ?? null;
	if (!is_string($image)) {
		respond(400, ['success' => false, 'error' => 'bad_image']);
	}
	$prefix = 'data:image/jpeg;base64,';
	if (strncmp($image, $prefix, strlen($prefix)) !== 0) {
		respond(400, ['success' => false, 'error' => 'bad_image']);
	}
	// Bound the encoded payload too, so it is never base64-decoded into memory
	// (base64 is ~4/3 of the decoded size; the decoded cap below is the backstop).
	if (strlen($image) > 12 * 1024 * 1024) {
		respond(413, ['success' => false, 'error' => 'too_large']);
	}
	$bytes = base64_decode(substr($image, strlen($prefix)), true);
	if ($bytes === false || $bytes === '') {
		respond(400, ['success' => false, 'error' => 'bad_image']);
	}
	if (strncmp($bytes, "\xFF\xD8\xFF", 3) !== 0) {
		respond(400, ['success' => false, 'error' => 'bad_image']);
	}
	if (strlen($bytes) > 8 * 1024 * 1024) {
		respond(413, ['success' => false, 'error' => 'too_large']);
	}

	// Filename: server timestamp + slugged game details (never a client filename).
	$roundNumber = filter_int($round['round'] ?? null);
	if ($roundNumber === null) {
		$roundNumber = $ri + 1;
	}
	$basename = build_dispute_filename(
		$userName,
		$competition['competition'] ?? '',
		$roundNumber,
		$discipline,
		$game['homeTeam'] ?? '',
		$game['awayTeam'] ?? '',
		date('Ymd-His')
	);

	if (!is_dir($disputeDir)) {
		if (!@mkdir($disputeDir, 0775, true) && !is_dir($disputeDir)) {
			respond(500, ['success' => false, 'error' => 'mkdir_failed']);
		}
	}

	$stored = write_dispute_file($disputeDir, $basename, $bytes);
	if ($stored === null) {
		respond(500, ['success' => false, 'error' => 'write_failed']);
	}

	respond(200, ['success' => true, 'file' => $stored]);
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

if ($method === 'POST' && $action === 'dispute') {
	handle_dispute($DATA_FILE, $DISPUTE_DIR);
}

respond(405, ['success' => false, 'error' => 'method_not_allowed']);
