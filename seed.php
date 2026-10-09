<?php
declare(strict_types=1);

/**
 * CLI: force (re)import a JSON draw into draw.sqlite.
 *
 *   php seed.php [path]        # default: draw.json in this directory
 *   php seed.php backups/draw_<user>_<ts>.json
 *
 * Replaces everything currently in the database with the file's contents. Normal
 * running does not need this: api.php seeds automatically when the DB is empty.
 */

require __DIR__ . '/db.php';

// This script is destructive (it replaces the whole draw). Only ever run it from
// the command line — the project root is served by `php -S`, so without this
// guard a plain GET /seed.php would wipe live results.
if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit;
}

$path = $argv[1] ?? (__DIR__ . '/draw.json');
if (!is_file($path)) {
	fwrite(STDERR, "No such file: {$path}\n");
	exit(1);
}

$contents = file_get_contents($path);
$draw = $contents === false ? null : json_decode($contents, true);
if (!is_array($draw)) {
	fwrite(STDERR, "Invalid JSON: {$path}\n");
	exit(1);
}

try {
	$pdo = db();
	ensure_schema($pdo);

	$pdo->exec('BEGIN IMMEDIATE');
	try {
		// Cascades to disciplines/conditions/rounds/games/players/scores.
		$pdo->exec('DELETE FROM competitions');
		import_draw($pdo, $draw);
		// Mark the DB as seeded so an accidental later wipe is logged loudly.
		$pdo->prepare("INSERT OR REPLACE INTO meta (key, value) VALUES ('seeded', ?)")
			->execute([date('c')]);
		$pdo->exec('COMMIT');
	} catch (Throwable $e) {
		$pdo->exec('ROLLBACK');
		throw $e;
	}
} catch (Throwable $e) {
	fwrite(STDERR, 'Import failed: ' . $e->getMessage() . "\n");
	exit(1);
}

echo 'Imported ' . count($draw) . " competitions from {$path}\n";
