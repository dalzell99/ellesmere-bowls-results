<?php
/**
 * Results & Disputes — standalone admin page.
 *
 * Lists every game in the draw with Competition/Round/Team filters, lets any
 * game be edited inline (saved through the admin-only api.php?action=update_game
 * under the same per-game revision lock as the sheet), lets a scored game's
 * scores and players be cleared after confirmation (api.php?action=reset_game),
 * lets an admin add a new fixture to an existing round
 * (api.php?action=add_game), lists dispute photos
 * with a fullscreen scorecard viewer whose disputed discipline is editable, and
 * reports per-person game counts plus the Rooster-then-Chatterton flag. Not
 * linked from index.php; this page is deliberately self-contained so the
 * deployed score sheet is untouched.
 *
 * The three areas are tabs (Games / Disputes / Games played); the active tab is
 * remembered in the URL hash. Data comes from api.php?action=load (draw) and
 * api.php?action=disputes.
 */

/**
 * Cache-busting: stamp each asset URL with the file's last-modified time so a
 * new deploy is never served from a cached copy. Copied from index.php so this
 * page needs no shared include. Falls back to the current time if the file
 * cannot be stat-ed.
 */
function asset_url(string $file): string
{
	$path = __DIR__ . '/' . $file;
	$version = @filemtime($path) ?: time();
	return $file . '?v=' . $version;
}

require __DIR__ . '/auth.php';   // sends the security headers via security.php

// Log out.
if (($_GET['logout'] ?? '') === '1') {
	results_logout();
	header('Location: results.php');
	exit;
}

// Handle the login POST; on success redirect (POST-redirect-GET).
$loginError = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['password'])) {
	if (results_authenticate((string) $_POST['password'])) {
		header('Location: results.php');
		exit;
	}
	$loginError = 'Incorrect password.';
}

// Gate: unauthenticated visitors get ONLY the login page — no admin markup or data.
if (!is_results_authenticated()) {
	?>
	<!DOCTYPE html>
	<html lang="en">
	<head>
		<meta charset="UTF-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
		<title>Results &amp; Disputes &mdash; Sign in</title>
		<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Ccircle cx='16' cy='16' r='14' fill='%231f6feb'/%3E%3C/svg%3E" />
		<link rel="stylesheet" href="<?= asset_url('styles.css') ?>" />
	</head>
	<body>
		<main class="page">
			<section class="sheet" aria-label="Sign in">
				<h1 class="admin-title">Results &amp; Disputes</h1>
				<form method="post" action="results.php" class="dialog__form">
					<label class="field">
						<span class="field__label">Password</span>
						<input type="password" name="password" required autofocus
							autocomplete="current-password" maxlength="200" />
					</label>
					<?php if ($loginError !== ''): ?>
						<p class="save-status save-status--error" role="alert"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></p>
					<?php endif; ?>
					<div class="dialog__actions">
						<button type="submit" class="primary-button">Continue</button>
					</div>
				</form>
			</section>
		</main>
	</body>
	</html>
	<?php
	exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
	<title>Results &amp; Disputes</title>
	<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Ccircle cx='16' cy='16' r='14' fill='%231f6feb'/%3E%3C/svg%3E" />
	<link rel="stylesheet" href="<?= asset_url('styles.css') ?>" />
</head>
<body>
	<header class="admin-header">
		<h1 class="admin-title">Results &amp; Disputes</h1>
		<div class="admin-header__actions">
			<span class="scorer">
				<span>Scorer: <strong id="scorerNameDisplay">&mdash;</strong></span>
				<button type="button" id="changeScorerButton" class="link-button">change</button>
			</span>
			<button type="button" id="reloadButton" class="secondary-button">Reload</button>
			<a class="link-button" href="results.php?logout=1">Log out</a>
		</div>
	</header>

	<!-- Load / conflict notices are rendered here. -->
	<div id="banners" class="banners" aria-live="polite"></div>

	<main class="admin-page">
		<!-- Tabs: the active tab is mirrored in the URL hash (#games/#disputes/#counts). -->
		<div class="tabs" role="tablist" aria-label="Results sections">
			<button type="button" class="tab is-active" id="tabGames" role="tab"
				aria-selected="true" aria-controls="panelGames" data-tab="games">Games</button>
			<button type="button" class="tab" id="tabDisputes" role="tab"
				aria-selected="false" aria-controls="panelDisputes" data-tab="disputes"
				tabindex="-1">Disputes</button>
			<button type="button" class="tab" id="tabCounts" role="tab"
				aria-selected="false" aria-controls="panelCounts" data-tab="counts"
				tabindex="-1">Games played</button>
		</div>

		<section class="admin-section" id="panelGames" role="tabpanel"
			aria-labelledby="tabGames" tabindex="0">
			<h2 class="admin-section__title" id="gamesHeading">Games</h2>
			<div class="games-toolbar">
				<div class="filters" aria-label="Filter games">
					<label class="field">
						<span class="field__label">Competition</span>
						<select id="filterCompetition">
							<option value="">All competitions</option>
						</select>
					</label>
					<label class="field">
						<span class="field__label">Round</span>
						<select id="filterRound" disabled>
							<option value="">All rounds</option>
						</select>
					</label>
					<label class="field">
						<span class="field__label">Team</span>
						<select id="filterTeam">
							<option value="">All teams</option>
						</select>
					</label>
				</div>
				<button type="button" id="addGameButton" class="primary-button">Add game</button>
			</div>
			<div id="gamesBody" class="table-scroll"></div>
		</section>

		<section class="admin-section" id="panelDisputes" role="tabpanel"
			aria-labelledby="tabDisputes" tabindex="0" hidden>
			<h2 class="admin-section__title" id="disputesHeading">Disputes</h2>
			<div id="disputesList" class="table-scroll"></div>
		</section>

		<section class="admin-section" id="panelCounts" role="tabpanel"
			aria-labelledby="tabCounts" tabindex="0" hidden>
			<h2 class="admin-section__title" id="countsHeading">Games played</h2>
			<p class="admin-section__hint">
				Counts and flags are computed from every game in the draw and are not
				affected by the filters above.
			</p>
			<div id="countsBody" class="table-scroll"></div>
		</section>
	</main>

	<!-- Add-game dialog: appends a resultless fixture to an existing round. -->
	<dialog id="addGameDialog" class="dialog">
		<form id="addGameForm" class="dialog__form">
			<h2 class="dialog__title">Add game</h2>
			<p class="dialog__hint">
				Adds a fixture to an existing round. Enter the two team names; scores
				are entered later.
			</p>
			<label class="field">
				<span class="field__label">Competition</span>
				<select id="addGameCompetition"></select>
			</label>
			<label class="field">
				<span class="field__label">Round</span>
				<select id="addGameRound" disabled></select>
			</label>
			<label class="field">
				<span class="field__label">Home team</span>
				<input type="text" id="addGameHome" maxlength="120" list="teamNamesList"
					autocomplete="off" />
			</label>
			<label class="field">
				<span class="field__label">Away team</span>
				<input type="text" id="addGameAway" maxlength="120" list="teamNamesList"
					autocomplete="off" />
			</label>
			<datalist id="teamNamesList"></datalist>
			<p id="addGameStatus" class="save-status" role="status"></p>
			<div class="dialog__actions">
				<button type="submit" class="primary-button">Add</button>
				<button type="button" id="addGameCloseButton" class="secondary-button">Cancel</button>
			</div>
		</form>
	</dialog>

	<!-- First-visit (and "change") scorer-name dialog. -->
	<dialog id="nameDialog" class="dialog">
		<form method="dialog" id="nameForm" class="dialog__form">
			<h2 class="dialog__title">Who is reviewing?</h2>
			<p class="dialog__hint">
				Your name is stored on this device and recorded against every save.
				You can close this without entering one.
			</p>
			<label class="field">
				<span class="field__label">Your name</span>
				<input type="text" id="nameInput" autocomplete="name" maxlength="60" />
			</label>
			<div class="dialog__actions">
				<button type="submit" class="primary-button">Continue</button>
				<button type="button" id="nameCloseButton" class="secondary-button">Cancel</button>
			</div>
		</form>
	</dialog>

	<!-- Fullscreen dispute viewer: scorecard photo + the disputed discipline. -->
	<dialog id="disputeViewer" class="dialog dispute-viewer">
		<div class="dispute-viewer__layout">
			<div class="dispute-viewer__image-wrap">
				<img id="disputeImage" alt="Disputed scorecard photo" />
			</div>
			<div class="dispute-viewer__panel">
				<h2 class="dialog__title" id="disputeViewerTitle">Dispute</h2>
				<p class="dispute-summary" id="disputeViewerSummary"></p>
				<div id="disputeViewerForm" class="dispute-viewer__form"></div>
				<p id="disputeViewerStatus" class="save-status" role="status"></p>
				<div class="dialog__actions">
					<button type="button" id="disputeSaveButton" class="primary-button" disabled>Save</button>
					<button type="button" id="disputeCloseButton" class="secondary-button">Close</button>
				</div>
			</div>
		</div>
	</dialog>

	<script src="<?= asset_url('validation.js') ?>"></script>
	<script src="<?= asset_url('results.js') ?>"></script>
</body>
</html>
