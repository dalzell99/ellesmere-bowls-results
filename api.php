<?php
declare(strict_types=1);

/**
 * Ellesmere Interclub Score Sheet — API endpoint.
 *
 * Actions:
 *   GET  api.php?action=load     -> { success, draw }
 *   POST api.php?action=save     -> { success, revision, backup } or an error
 *   POST api.php?action=dispute  -> { success, file } or an error
 *
 * The draw lives in a normalized SQLite database (draw.sqlite) managed by
 * db.php; draw.json is only the one-time seed. A save is a targeted update of
 * one game, guarded by a per-game revision (optimistic lock) so two people can
 * edit different fixtures concurrently. A post-save JSON export of the whole
 * draw is written to backups/.
 *
 * Dispute writes a compressed scorecard photo into disputes/, named from the
 * server clock and the game details resolved from the database. It never
 * modifies the draw, its revisions, results or backups.
 *
 * Run with: php -S localhost:8000   (then open http://localhost:8000/)
 */

require __DIR__ . '/db.php';

$BACKUP_DIR  = __DIR__ . '/backups';
$DISPUTE_DIR = __DIR__ . '/disputes';
$SEED_FILE   = __DIR__ . '/draw.json';

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
 * Open the database, make sure the schema exists and the seed has been applied.
 * Any failure (missing pdo_sqlite, unwritable file, bad seed) becomes a 500.
 */
function open_db(string $seedFile): PDO
{
	try {
		$pdo = db();
		ensure_schema($pdo);
		seed_from_json($pdo, $seedFile);
		return $pdo;
	} catch (Throwable $e) {
		error_log('db_unavailable: ' . $e->getMessage());
		respond(500, ['success' => false, 'error' => 'db_unavailable']);
	}
}

/**
 * Export the current draw to backups/draw_<user>_<YYYYMMDD-HHMMSS>.json, with a
 * numeric suffix on collision. Returns the backup filename or null on failure;
 * a backup failure never fails a save that already committed.
 */
function write_backup(PDO $pdo, string $backupDir, string $user): ?string
{
	if (!is_dir($backupDir)) {
		if (!@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
			return null;
		}
	}

	$json = export_draw_json($pdo);

	$base = 'draw_' . $user . '_' . date('Ymd-His');
	$path = $backupDir . '/' . $base . '.json';
	$i = 1;
	while (file_exists($path)) {
		$path = $backupDir . '/' . $base . '-' . $i . '.json';
		$i++;
	}

	if (@file_put_contents($path, $json) === false) {
		return null;
	}
	return basename($path);
}

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------

function handle_load(PDO $pdo): void
{
	try {
		$draw = load_draw($pdo);
	} catch (Throwable $e) {
		error_log('load_failed: ' . $e->getMessage());
		respond(500, ['success' => false, 'error' => 'data_unreadable']);
	}

	respond(200, [
		'success' => true,
		'draw'    => $draw,
	]);
}

function handle_save(PDO $pdo, string $backupDir): void
{
	$raw = file_get_contents('php://input');
	if ($raw === false || trim($raw) === '') {
		respond(400, ['success' => false, 'error' => 'empty_body']);
	}

	$input = json_decode($raw, true);
	if (!is_array($input) || !isset($input['game']) || !is_array($input['game'])) {
		respond(400, ['success' => false, 'error' => 'bad_body']);
	}

	$gameId = filter_int($input['gameId'] ?? null);
	$revision = filter_int($input['revision'] ?? null);
	if ($gameId === null || $gameId < 0 || $revision === null || $revision < 1) {
		respond(400, ['success' => false, 'error' => 'bad_body']);
	}

	$userName = sanitise_user($input['userName'] ?? '');
	$game = normalise_game($input['game']);

	try {
		$result = save_game($pdo, $gameId, $revision, $game);
	} catch (Throwable $e) {
		error_log('save_failed: ' . $e->getMessage());
		respond(500, ['success' => false, 'error' => 'write_failed']);
	}

	if ($result === 'not_found') {
		respond(400, ['success' => false, 'error' => 'game_not_found']);
	}
	if ($result === 'conflict') {
		respond(409, ['success' => false, 'error' => 'conflict']);
	}

	// The DB write has committed; a backup failure must not block the client.
	$backupName = null;
	try {
		$backupName = write_backup($pdo, $backupDir, $userName);
	} catch (Throwable $e) {
		error_log('backup_failed: ' . $e->getMessage());
	}

	respond(200, [
		'success'  => true,
		'revision' => $result,
		'backup'   => $backupName,
	]);
}

/**
 * Save a compressed scorecard photo for one game into disputes/. The photo is
 * base64 JPEG in the JSON body; the filename is built server-side from the game
 * details resolved from the database. The draw is never modified.
 */
function handle_dispute(PDO $pdo, string $disputeDir): void
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

	$gameId = filter_int($input['gameId'] ?? null);
	if ($gameId === null || $gameId < 0) {
		respond(400, ['success' => false, 'error' => 'bad_body']);
	}

	$userName = sanitise_user($input['userName'] ?? '');
	$discipline = cap_str($input['discipline'] ?? '', 40);

	// Resolve the game details (and its results gate) from the database.
	$summary = game_summary($pdo, $gameId);
	if ($summary === null) {
		respond(400, ['success' => false, 'error' => 'game_not_found']);
	}
	// Defensive: the client also gates this, but a dispute needs saved results.
	if (!$summary['hasResults']) {
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
	$basename = build_dispute_filename(
		$userName,
		$summary['competition'],
		$summary['round'],
		$discipline,
		$summary['homeTeam'],
		$summary['awayTeam'],
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
	handle_load(open_db($SEED_FILE));
}

if ($method === 'POST' && $action === 'save') {
	handle_save(open_db($SEED_FILE), $BACKUP_DIR);
}

if ($method === 'POST' && $action === 'dispute') {
	handle_dispute(open_db($SEED_FILE), $DISPUTE_DIR);
}

respond(405, ['success' => false, 'error' => 'method_not_allowed']);
