<?php
declare(strict_types=1);

/**
 * Ellesmere Interclub Score Sheet — API endpoint.
 *
 * Actions:
 *   GET  api.php?action=load     -> { success, draw }
 *   POST api.php?action=save     -> { success, revision, backup } or an error
 *   POST api.php?action=update_game -> { success, revision, backup } or an error (admin only)
 *   POST api.php?action=add_game -> { success, gameId, backup } or an error (admin only)
 *   POST api.php?action=reset_game -> { success, revision, backup } or an error (admin only)
 *   POST api.php?action=dispute  -> { success, file, id } or an error
 *   GET  api.php?action=disputes -> { success, disputes } or an error
 *   GET  api.php?action=dispute_image&id=N -> the stored photo (admin only)
 *   POST api.php?action=delete_dispute -> { success, id } or an error (admin only)
 *
 * The draw lives in a normalized SQLite database (draw.sqlite) managed by
 * db.php; draw.json is only the one-time seed. A save is a targeted update of
 * one game, guarded by a per-game revision (optimistic lock) so two people can
 * edit different fixtures concurrently. A post-save JSON export of the whole
 * draw is written to backups/.
 *
 * The public save action uses db.php save_game(), which never rewrites a game's
 * teams or its round date, so an anonymous score-sheet caller cannot rename a
 * team. The admin-only update_game action uses db.php update_game(), which may
 * rewrite the teams and the round date, but only while nothing is scored; the
 * admin-only add_game action appends a new resultless fixture to a round.
 *
 * Dispute writes a compressed scorecard photo into disputes/, named from the
 * server clock and the game details resolved from the database, and records a
 * row in the disputes table describing it. It never modifies the draw, its
 * revisions, results or backups. Deleting a dispute (admin only) removes its
 * row and its stored photo, and likewise never touches the draw.
 *
 * Run with: php -S localhost:8000   (then open http://localhost:8000/)
 */

require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';   // session auth + security headers (admin surface)

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

	// Server-side validation mirrors the client rules so a save cannot bypass
	// them. game_not_found is reported distinctly; every other failure is a
	// generic invalid_scores (the client re-validates its own display).
	$errors = validate_game($pdo, $gameId, $game);
	if ($errors === ['game_not_found']) {
		respond(400, ['success' => false, 'error' => 'game_not_found']);
	}
	if ($errors) {
		respond(400, ['success' => false, 'error' => 'invalid_scores']);
	}

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
 * Admin-only edit of one game from the results page (api.php?action=update_game).
 * Mirrors handle_save()'s shape and its 409/400 responses, but goes through the
 * db.php update_game() writer so the teams and (for a resultless game) the round
 * date may also change.
 *
 * Two extra guards beyond the score rules:
 *   - a discipline that already holds a stored result may not be blanked or left
 *     incomplete (the client mirrors this; enforced here against the stored
 *     positions so a crafted request cannot erase a result);
 *   - team/date changes on a scored game, and a round-date change once the round
 *     holds a result, are rejected by update_game() (400 teams_locked/date_locked).
 */
function handle_update_game(PDO $pdo, string $backupDir): void
{
	require_results_auth_json();

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

	// A fixture always has two team names; refuse a payload that would blank one
	// (only reachable for a resultless game, whose teams are editable).
	if ($game['homeTeam'] === '' || $game['awayTeam'] === '') {
		respond(400, ['success' => false, 'error' => 'bad_teams']);
	}

	// The round date is optional: present only when the client edited a
	// resultless game's date. A blank string clears it; an absent/null value
	// leaves the stored date untouched.
	$roundDate = null;
	if (array_key_exists('roundDate', $input) && $input['roundDate'] !== null) {
		$roundDate = cap_str($input['roundDate'], 40);
	}

	// Never blank a stored result: every position that already holds a result
	// must still hold a complete one after the edit. (validate_game() skips
	// blank/partial rows, so this is checked separately.)
	$storedPositions = game_result_positions($pdo, $gameId);
	if ($storedPositions) {
		$submitted = [];
		$scores = (isset($game['scores']) && is_array($game['scores'])) ? $game['scores'] : [];
		foreach ($scores as $entry) {
			if (!is_array($entry)) { continue; }
			$pos = filter_int($entry['position'] ?? null);
			if ($pos === null || $pos < 0) { continue; }
			$submitted[$pos] = $entry;
		}
		foreach (array_keys($storedPositions) as $pos) {
			$entry = $submitted[$pos] ?? null;
			$complete = $entry !== null;
			if ($complete) {
				foreach (['homeScore', 'awayScore', 'homeEnds', 'awayEnds'] as $field) {
					if (!isset($entry[$field]) || $entry[$field] === null) {
						$complete = false;
						break;
					}
				}
			}
			if (!$complete) {
				respond(400, ['success' => false, 'error' => 'result_removed']);
			}
		}
	}

	// Server-side validation mirrors the client rules; game_not_found is
	// reported distinctly, every other failure is a generic invalid_scores.
	$errors = validate_game($pdo, $gameId, $game);
	if ($errors === ['game_not_found']) {
		respond(400, ['success' => false, 'error' => 'game_not_found']);
	}
	if ($errors) {
		respond(400, ['success' => false, 'error' => 'invalid_scores']);
	}

	try {
		$result = update_game($pdo, $gameId, $revision, $game, $roundDate);
	} catch (Throwable $e) {
		error_log('update_game_failed: ' . $e->getMessage());
		respond(500, ['success' => false, 'error' => 'write_failed']);
	}

	if ($result === 'not_found') {
		respond(400, ['success' => false, 'error' => 'game_not_found']);
	}
	if ($result === 'conflict') {
		respond(409, ['success' => false, 'error' => 'conflict']);
	}
	if ($result === 'teams_locked') {
		respond(400, ['success' => false, 'error' => 'teams_locked']);
	}
	if ($result === 'date_locked') {
		respond(400, ['success' => false, 'error' => 'date_locked']);
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
 * Admin-only append of one resultless fixture to an existing round
 * (api.php?action=add_game). Both team names are required and capped; the round
 * must already exist. No players or scores are written at creation.
 */
function handle_add_game(PDO $pdo, string $backupDir): void
{
	require_results_auth_json();

	$raw = file_get_contents('php://input');
	if ($raw === false || trim($raw) === '') {
		respond(400, ['success' => false, 'error' => 'empty_body']);
	}

	$input = json_decode($raw, true);
	if (!is_array($input)) {
		respond(400, ['success' => false, 'error' => 'bad_body']);
	}

	$roundId = filter_int($input['roundId'] ?? null);
	if ($roundId === null || $roundId < 1) {
		respond(400, ['success' => false, 'error' => 'bad_body']);
	}

	$homeTeam = cap_str($input['homeTeam'] ?? '', 120);
	$awayTeam = cap_str($input['awayTeam'] ?? '', 120);
	if ($homeTeam === '' || $awayTeam === '') {
		respond(400, ['success' => false, 'error' => 'bad_teams']);
	}
	$userName = sanitise_user($input['userName'] ?? '');

	try {
		$gameId = insert_game($pdo, $roundId, $homeTeam, $awayTeam);
	} catch (RuntimeException $e) {
		if ($e->getMessage() === 'round_not_found') {
			respond(400, ['success' => false, 'error' => 'round_not_found']);
		}
		error_log('add_game_failed: ' . $e->getMessage());
		respond(500, ['success' => false, 'error' => 'write_failed']);
	} catch (Throwable $e) {
		error_log('add_game_failed: ' . $e->getMessage());
		respond(500, ['success' => false, 'error' => 'write_failed']);
	}

	$backupName = null;
	try {
		$backupName = write_backup($pdo, $backupDir, $userName);
	} catch (Throwable $e) {
		error_log('backup_failed: ' . $e->getMessage());
	}

	respond(200, ['success' => true, 'gameId' => $gameId, 'backup' => $backupName]);
}

/**
 * Admin-only reset of one game from the results page
 * (api.php?action=reset_game). Removes the game's scores and players via
 * db.php reset_game(), leaving the fixture itself in place. Unlike update_game()
 * this deliberately erases a stored result; the results page confirms before
 * calling it. Same per-game revision lock, so a reset cannot race a save.
 */
function handle_reset_game(PDO $pdo, string $backupDir): void
{
	require_results_auth_json();

	$raw = file_get_contents('php://input');
	if ($raw === false || trim($raw) === '') {
		respond(400, ['success' => false, 'error' => 'empty_body']);
	}

	$input = json_decode($raw, true);
	if (!is_array($input)) {
		respond(400, ['success' => false, 'error' => 'bad_body']);
	}

	$gameId = filter_int($input['gameId'] ?? null);
	$revision = filter_int($input['revision'] ?? null);
	if ($gameId === null || $gameId < 0 || $revision === null || $revision < 1) {
		respond(400, ['success' => false, 'error' => 'bad_body']);
	}

	$userName = sanitise_user($input['userName'] ?? '');

	try {
		$result = reset_game($pdo, $gameId, $revision);
	} catch (Throwable $e) {
		error_log('reset_game_failed: ' . $e->getMessage());
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
 * Save a compressed scorecard photo for one game into disputes/, and record it
 * in the disputes table. The photo is base64 JPEG in the JSON body; the filename
 * is built server-side from the game details resolved from the database. The
 * draw is never modified.
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

	$userName = sanitise_display_name($input['userName'] ?? '');
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

	// Record the dispute in the database, keyed to the game and describing the
	// stored photo. The write is best-effort: the photo is already safely on
	// disk, so a failure here is logged and reported as a null id rather than
	// failing the upload (which would invite the client to retry and file a
	// duplicate photo).
	$disputeId = null;
	try {
		$disputeId = insert_dispute($pdo, $gameId, $userName, $discipline, $summary, $stored);
	} catch (Throwable $e) {
		error_log('dispute_record_failed: ' . $e->getMessage());
	}

	respond(200, ['success' => true, 'file' => $stored, 'id' => $disputeId]);
}

/**
 * List every recorded dispute (newest first). Read-only: the page resolves each
 * row's game_id against the draw it already holds, so a stale id simply means
 * the game is no longer editable there.
 */
function handle_disputes(PDO $pdo): void
{
	require_results_auth_json();

	try {
		$rows = load_disputes($pdo);
	} catch (Throwable $e) {
		error_log('disputes_failed: ' . $e->getMessage());
		respond(500, ['success' => false, 'error' => 'data_unreadable']);
	}

	respond(200, ['success' => true, 'disputes' => $rows]);
}

/**
 * Stream one dispute photo by id (admin only). The filename is resolved from
 * the database and re-checked against the stored naming shape, so the caller
 * can never traverse out of disputes/ — the id is the only input.
 */
function handle_dispute_image(PDO $pdo, string $disputeDir): void
{
	require_results_auth_json();

	$id = filter_int($_GET['id'] ?? null);
	if ($id === null || $id < 1) {
		respond(400, ['success' => false, 'error' => 'bad_id']);
	}
	$filename = dispute_filename($pdo, $id);
	if ($filename === null || basename($filename) !== $filename
		|| preg_match('/^dispute_[A-Za-z0-9._-]+\.jpg$/', $filename) !== 1) {
		respond(404, ['success' => false, 'error' => 'not_found']);
	}
	$path = $disputeDir . '/' . $filename;
	if (!is_file($path)) {
		respond(404, ['success' => false, 'error' => 'not_found']);
	}
	header('Content-Type: image/jpeg');        // overrides the global JSON header
	header('Cache-Control: no-store');
	header('X-Content-Type-Options: nosniff');
	// No Content-Length: output compression (mod_deflate) would make it wrong.
	readfile($path);
	exit;
}

/**
 * Delete one recorded dispute: its database row and its stored photo (admin
 * only, destructive). The id is read from the JSON body; the filename is
 * resolved from the row before it is removed, so the row and its file go
 * together and an unknown id is a 404.
 */
function handle_delete_dispute(PDO $pdo, string $disputeDir): void
{
	require_results_auth_json();

	$raw = file_get_contents('php://input');
	$input = ($raw === false || trim($raw) === '') ? [] : json_decode($raw, true);
	if (!is_array($input)) {
		respond(400, ['success' => false, 'error' => 'bad_body']);
	}

	$id = filter_int($input['id'] ?? null);
	if ($id === null || $id < 1) {
		respond(400, ['success' => false, 'error' => 'bad_id']);
	}

	try {
		$filename = delete_dispute($pdo, $id);
	} catch (Throwable $e) {
		error_log('dispute_delete_failed: ' . $e->getMessage());
		respond(500, ['success' => false, 'error' => 'delete_failed']);
	}

	if ($filename === null) {
		respond(404, ['success' => false, 'error' => 'not_found']);
	}

	// The row is already gone, so removing the photo is best-effort: an orphaned
	// file is harmless (never listed once its row is gone). Re-check the name
	// shape first so a corrupt row can never unlink anything outside disputes/.
	if (basename($filename) === $filename
		&& preg_match('/^dispute_[A-Za-z0-9._-]+\.jpg$/', $filename) === 1) {
		@unlink($disputeDir . '/' . $filename);
	}

	respond(200, ['success' => true, 'id' => $id]);
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

// Note the plural action is a distinct GET branch from the POST dispute above;
// keep both so a plural/singular mix-up still 405s rather than uploading.
if ($method === 'GET' && $action === 'disputes') {
	handle_disputes(open_db($SEED_FILE));
}

if ($method === 'GET' && $action === 'dispute_image') {
	handle_dispute_image(open_db($SEED_FILE), $DISPUTE_DIR);
}

// Keep the delete a distinct POST branch: only an admin session may reach it,
// and it is never served for the plural read action above.
if ($method === 'POST' && $action === 'delete_dispute') {
	handle_delete_dispute(open_db($SEED_FILE), $DISPUTE_DIR);
}

// Admin-only draw writes from results.php: a distinct branch per action, each
// gated by require_results_auth_json() inside its handler. The public `save`
// action above is deliberately separate (and calls the teams/date-immutable
// save_game()), so an anonymous score-sheet caller can never reach these.
if ($method === 'POST' && $action === 'update_game') {
	handle_update_game(open_db($SEED_FILE), $BACKUP_DIR);
}

if ($method === 'POST' && $action === 'add_game') {
	handle_add_game(open_db($SEED_FILE), $BACKUP_DIR);
}

if ($method === 'POST' && $action === 'reset_game') {
	handle_reset_game(open_db($SEED_FILE), $BACKUP_DIR);
}

respond(405, ['success' => false, 'error' => 'method_not_allowed']);
