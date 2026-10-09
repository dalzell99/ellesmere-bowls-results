<?php
/**
 * Cache-busting: stamp each asset URL with the file's last-modified time so a
 * new deploy is never served from a mobile browser's cached copy. Falls back to
 * the current time if the file cannot be stat-ed.
 */
function asset_url(string $file): string
{
	$path = __DIR__ . '/' . $file;
	$version = @filemtime($path) ?: time();
	return $file . '?v=' . $version;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
	<title>Ellesmere Interclub Score Sheet</title>
	<link rel="stylesheet" href="<?= asset_url('styles.css') ?>" />
</head>
<body>
	<div class="scorer">
		<span>Scorer: <strong id="scorerNameDisplay">&mdash;</strong></span>
		<button type="button" id="changeScorerButton" class="link-button">change</button>
	</div>

	<main class="page">
		<section class="selectors" aria-label="Fixture selection">
			<label class="field">
				<span class="field__label">Competition</span>
				<select id="competitionSelect">
					<option value="">Select&hellip;</option>
				</select>
			</label>

			<label class="field">
				<span class="field__label">Round</span>
				<select id="roundSelect" disabled>
					<option value="">Select&hellip;</option>
				</select>
			</label>

			<label class="field">
				<span class="field__label">Fixture</span>
				<select id="fixtureSelect" disabled>
					<option value="">Select&hellip;</option>
				</select>
			</label>
		</section>

		<!-- Conflict / draft notices are rendered here. -->
		<div id="banners" class="banners" aria-live="polite"></div>

		<section id="sheet" class="sheet" hidden>
			<div class="sheet__title">
				<h2 id="sheetTitle">Score Sheet</h2>
			</div>

			<div class="sheet__round">
				<span class="sheet__round-label">Round</span>
				<span id="sheetRound" class="round-badge"></span>
			</div>

			<!-- Discipline table, team headers and player table are built here. -->
			<div id="sheetBody"></div>

			<div class="sheet__actions">
				<button type="button" id="saveButton" class="primary-button" disabled>Save</button>
				<span id="saveStatus" class="save-status" role="status"></span>
			</div>
		</section>

		<p id="emptyState" class="empty-state">
			Select a competition, round and fixture to enter results.
		</p>
	</main>

	<!-- First-visit (and "change") scorer-name dialog. -->
	<dialog id="nameDialog" class="dialog">
		<form method="dialog" id="nameForm" class="dialog__form">
			<h2 class="dialog__title">Who is scoring?</h2>
			<p class="dialog__hint">
				Your name is stored on this device and recorded against every save.
			</p>
			<label class="field">
				<span class="field__label">Your name</span>
				<input type="text" id="nameInput" autocomplete="name" maxlength="60" required />
			</label>
			<div class="dialog__actions">
				<button type="submit" class="primary-button">Continue</button>
			</div>
		</form>
	</dialog>

	<!-- Dispute a saved result: attach or (on mobile) capture a scorecard photo. -->
	<dialog id="disputeDialog" class="dialog">
		<div id="disputeForm" class="dialog__form">
			<h2 class="dialog__title">Dispute this result</h2>
			<p id="disputeSummary" class="dispute-summary"></p>
			<p class="dialog__hint">
				Attach a photo of the signed scorecard. It is uploaded to the club server for review.
			</p>
			<div class="dispute-capture">
				<button type="button" id="disputeUploadButton" class="secondary-button">Upload photo</button>
				<button type="button" id="disputeCameraButton" class="secondary-button" hidden>Take photo</button>
				<input type="file" id="disputeUploadInput" accept="image/*" hidden aria-label="Upload a scorecard photo" />
				<input type="file" id="disputeCameraInput" accept="image/*" capture="environment" hidden aria-label="Take a scorecard photo with the camera" />
			</div>
			<div id="disputePreview" class="dispute-preview" hidden>
				<img id="disputePreviewImg" alt="Selected scorecard photo preview" />
			</div>
			<p id="disputeStatus" class="save-status" role="status"></p>
			<div class="dialog__actions">
				<button type="button" id="disputeSubmitButton" class="primary-button" disabled>Submit dispute</button>
				<button type="button" id="disputeCloseButton" class="secondary-button">Cancel</button>
			</div>
		</div>
	</dialog>

	<script src="<?= asset_url('app.js') ?>"></script>
</body>
</html>
