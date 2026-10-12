# Security Hardening, Admin Password Gate & Accessibility Fixes

## Goal
Fix the reported end-to-end issues **H1–H4, M1–M4 and L1–L3** for the Ellesmere Interclub Score Sheet, and add a password gate to the results page. **L4 (unbounded backup growth) and L5 (no cross-page navigation) are explicitly OUT OF SCOPE and must not be changed.**

## Confirmed decisions (user)
- **Auth scope: admin only.** The password protects `results.php`, the disputes list (`api.php?action=disputes`) and dispute photos. The scorer sheet (`index.php`, `action=load`, `action=save`, `action=dispute`) stays public so scorers keep working without a password.
- **Password storage: hard-coded constant** in a PHP file.
- **Static-file protection: Apache `.htaccess`.** Production runs Apache.

## Issue → fix map
| Issue | Fix |
|---|---|
| H1 `draw.sqlite` downloadable | `.htaccess` deny (T8) |
| H2 `draw.json` / `backups/` downloadable | `.htaccess` deny (T8) |
| H3 `.git` exposed | `.htaccess` deny (T8) |
| H4 no auth | Password gate + session auth on the **admin surface only** (T2–T5) |
| M1 client-only validation | Server-side score/condition/team validation (T6) |
| M2 dispute photos/list public | Auth-gated `disputes` + new image endpoint (T4, T5) |
| M3 missing security headers | `security.php` shared headers (T1) |
| M4 unlabelled inputs / no h1 / error not announced | `aria-label`, `aria-describedby`, sr-only h1, `.sr-only` (T9) |
| L1 scorer name slugged | Store display name and slug separately (T7) |
| L2 favicon 404 | Inline SVG data-URI `<link rel="icon">` on both pages (T10) |
| L3 results name dialog has no close | Add Cancel button to results.php dialog (T11) |

## Out of scope / accepted risks
- **L4** backup growth: **not modified.** (H2 still denies HTTP access to `backups/` — that is confidentiality, not pruning.)
- **L5** navigation: **not modified.**
- No CSRF token on `save`/`dispute`: they remain public by explicit scope; cross-site JSON POST is blocked by CORS preflight (no CORS headers present). Login is covered by `SameSite=Strict` cookies.
- No brute-force/rate limiting on the password gate (kept simple as requested).
- `action=load` remains public and returns the whole draw (existing behaviour, not a reported finding).

## Files
- **New:** `security.php`, `auth.php`, `.htaccess` (repo root), `backups/.htaccess`, `disputes/.htaccess`, `.git/.htaccess`, `.history/.htaccess`, `.kilo/.htaccess`.
- **Changed:** `index.php`, `results.php`, `api.php`, `db.php`, `app.js`, `results.js`, `styles.css`.

> Repo root maps to the deployed `scoring/` directory, so the root `.htaccess` above is that directory's `.htaccess`.

**Dependencies:** T1 → T2 (auth uses the header include); T2 → T3/T4/T5 (they call the auth helpers). T6, T7, T8 are independent. T9–T11 are independent. Do not start T3/T4/T5 before T2 exists.

---

## Tasks (implement in order)

### T1 — Shared security headers (`security.php`)  [M3]
New `security.php`, required first by `index.php`, `results.php`, `api.php`:

```php
<?php
// Send only once, before any output.
if (!defined('SECURITY_HEADERS_SENT')) {
	define('SECURITY_HEADERS_SENT', true);
	header_remove('X-Powered-By');            // hide PHP version (also set expose_php=Off in php.ini)
	header('X-Content-Type-Options: nosniff');
	header('X-Frame-Options: DENY');
	header('Referrer-Policy: no-referrer');
	header(
		"Content-Security-Policy: default-src 'self'; " .
		"img-src 'self' data: blob:; " .          // favicon data URI + dispute preview data:/blob:
		"style-src 'self'; script-src 'self'; connect-src 'self'; " .
		"object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'"
	);
	if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
		// No includeSubDomains: the club may host other HTTP-only subdomains.
		header('Strict-Transport-Security: max-age=31536000');
	}
}
```

Notes:
- No inline `<script>`/`<style>` or `style="..."` attributes exist in the pages (verified); JS only sets `col.style.width` via CSSOM, which CSP does **not** block. So the strict policy is safe.
- `api.php` already sets `Content-Type: application/json` and `Cache-Control: no-store`; keep those (they are per-response, not part of `security.php`).
- `form-action 'self'`: the login form posts to `results.php` (allowed). The `<form method="dialog">` dialogs do not navigate, so `form-action` does not block them; confirm in the regression sweep.
- All new PHP must stay **PHP 7.4-compatible** (no union types, `match`, nullsafe `?->`, constructor promotion, `mixed`). The snippets here already comply; `session_set_cookie_params([...])` needs 7.3+ and is fine.

### T2 — Session auth helper (`auth.php`)  [H4]
New `auth.php`. Hard-code the password as a **hash** (user chose hard-coded constant):

```php
<?php
require_once __DIR__ . '/security.php';

if (!defined('RESULTS_PASSWORD_HASH')) {
	// Generate with: php -r "echo password_hash('YOUR-PASSWORD', PASSWORD_BCRYPT), PHP_EOL;"
	// PASSWORD_BCRYPT is used explicitly so the hash verifies identically on the
	// PHP 7.4 production server and the PHP 8.3 dev machine.
	define('RESULTS_PASSWORD_HASH', '$2y$10$REPLACE_WITH_REAL_HASH');
}

function app_is_https(): bool {
	return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
		|| (($_SERVER['SERVER_PORT'] ?? '') === '443')
		|| (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function start_app_session(): void {
	if (session_status() === PHP_SESSION_ACTIVE) { return; }
	session_set_cookie_params([
		'lifetime' => 0,
		'path'     => '/',
		'httponly' => true,
		'samesite' => 'Strict',
		'secure'   => app_is_https(),
	]);
	session_name('ellesmere_results');
	session_start();
}

function is_results_authenticated(): bool {
	start_app_session();
	return !empty($_SESSION['results_auth']);
}

function results_authenticate(string $password): bool {
	if (RESULTS_PASSWORD_HASH === '' || strpos(RESULTS_PASSWORD_HASH, 'REPLACE_WITH_REAL_HASH') !== false) {
		return false; // refuse until a real hash is configured
	}
	if (!password_verify($password, RESULTS_PASSWORD_HASH)) {
		return false;
	}
	start_app_session();
	session_regenerate_id(true);           // prevent session fixation
	$_SESSION['results_auth'] = true;
	return true;
}

function results_logout(): void {
	start_app_session();
	$_SESSION = [];
	if (ini_get('session.use_cookies')) {
		$p = session_get_cookie_params();
		setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
	}
	session_destroy();
}

/** 401 JSON guard for API actions. */
function require_results_auth_json(): void {
	if (!is_results_authenticated()) {
		header('Content-Type: application/json; charset=utf-8');
		http_response_code(401);
		echo json_encode(['success' => false, 'error' => 'unauthorized']);
		exit;
	}
}
```

### T3 — Password field on `results.php`  [H4]
`results.php` defines `asset_url()` inside its opening `<?php ... ?>` block (lines 1–27). **Keep that definition first**, then add the auth logic after it and before the closing `?>` (the login page below calls `asset_url()`, so the order matters):

```php
<?php
// (keep the existing file docblock and asset_url() definition here)

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
		<title>Results &amp; Disputes — Sign in</title>
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
// ...existing results.php admin markup continues unchanged...
```
(Use `h1` here, not `h2`, since it is the page's only heading when gated.)

Add a **Log out** link in the existing `.admin-header__actions` (`<a class="link-button" href="results.php?logout=1">Log out</a>`). This is inside results.php and does **not** add cross-page navigation (L5 unaffected).

> Note: `auth.php` requires `security.php`, so the CSP/`X-Frame-Options` headers are already sent before this page emits HTML. `results.php` does not currently include `security.php` directly.

### T4 — Gate admin API actions and add the image endpoint  [H4, M2]
In `api.php`:
- Add `require __DIR__ . '/auth.php';` near the `require db.php` line. (`auth.php` pulls in `security.php`, so the security headers are sent for every API response; the existing `header('Content-Type: application/json')` / `Cache-Control: no-store` lines still run and apply.)
- In `handle_disputes()` (the `GET action=disputes` branch), call `require_results_auth_json();` at the top.
- Add a new handler:

```php
function handle_dispute_image(PDO $pdo, string $disputeDir): void
{
	require_results_auth_json();

	$id = filter_int($_GET['id'] ?? null);
	if ($id === null || $id < 1) {
		respond(400, ['success' => false, 'error' => 'bad_id']);
	}
	$filename = dispute_filename($pdo, $id);   // new db.php helper
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
```
- Add the dispatch branch: `if ($method === 'GET' && $action === 'dispute_image') { handle_dispute_image(open_db($SEED_FILE), $DISPUTE_DIR); }`.
- New `db.php` helper:

```php
function dispute_filename(PDO $pdo, int $id): ?string
{
	$stmt = $pdo->prepare('SELECT filename FROM disputes WHERE id = ?');
	$stmt->execute([$id]);
	$row = $stmt->fetch();
	return $row === false ? null : (string) $row['filename'];
}
```
- `handle_dispute()` (upload) stays public (scorer flow), unchanged except T7.

### T5 — Point the viewer at the guarded image URL  [M2]
In `results.js`:
- `openDisputeViewer(id)`: replace `el.disputeImage.src = 'disputes/' + dispute.filename;` with `el.disputeImage.src = API + '?action=dispute_image&id=' + encodeURIComponent(String(dispute.id));` (cookie is sent same-origin automatically).
- `loadAll()` / `resync()`: when `disputes` fetch returns HTTP 401, show a notice `Session expired — reload to sign in.` with a Reload action, instead of the generic error.

### T6 — Server-side validation  [M1]
In `db.php`, add a validation context + validator (PHP 7.4 syntax):

```php
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

/** Mirrors app.js rules. Returns a list of error strings (empty = valid). */
function validate_game(PDO $pdo, int $gameId, array $game): array
{
	$ctx = game_validation_context($pdo, $gameId);
	if ($ctx === null) { return ['game_not_found']; }

	// Index submitted scores by position; require all four fields present.
	$byPos = [];
	foreach ((isset($game['scores']) && is_array($game['scores'])) ? $game['scores'] : [] as $entry) {
		if (!is_array($entry)) { continue; }
		$pos = filter_int($entry['position'] ?? null);
		if ($pos === null || $pos < 0) { return ['bad_scores']; }
		$byPos[$pos] = $entry;
	}

	$errors = [];
	foreach ($ctx['disciplines'] as $pos => $name) {
		if (!in_array($name, ['singles', 'pairs', 'triples', 'fours'], true)) { continue; }
		$entry = $byPos[$pos] ?? null;
		if ($entry === null) { $errors[] = "missing_scores:{$name}"; continue; }
		$vals = [];
		foreach (['homeScore', 'awayScore', 'homeEnds', 'awayEnds'] as $f) {
			$v = score_value($entry[$f] ?? null);
			if ($v === null) { $errors[] = "missing_field:{$name}:{$f}"; }
			$vals[$f] = $v;
		}
		if (in_array(null, $vals, true)) { continue; }

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
```

In `api.php` `handle_save()`, after `$game = normalise_game($input['game']);`:
```php
$errors = validate_game($pdo, $gameId, $game);
if ($errors === ['game_not_found']) {
	respond(400, ['success' => false, 'error' => 'game_not_found']);
}
if ($errors) {
	respond(400, ['success' => false, 'error' => 'invalid_scores']);
}
```
(Keep `save_game()`'s own `not_found` handling as a race backstop.)

Make team names immutable in `db.php` `save_game()`: change the `UPDATE games ...` to `SET revision = revision + 1, updated_at = ?` (drop `home_team`/`away_team` and their two bind values). Players/scores are still replaced. Update the function docblock accordingly. Clients already send the unchanged teams, so there is no functional change.

### T7 — Store the display name, not the slug  [L1]
In `db.php`:
- Add a display-name sanitiser (preserves spaces/case; strips control chars):

```php
function sanitise_display_name($value): string
{
	$name = cap_str($value, 60);
	$name = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '';
	$name = trim($name);
	return $name === '' ? 'unknown' : $name;
}
```
- `insert_dispute()` is unchanged in shape; it already stores the `$userName` argument into `disputes.user_name`.

In `api.php` `handle_dispute()`:
- Replace `$userName = sanitise_user($input['userName'] ?? '');` with `$userName = sanitise_display_name($input['userName'] ?? '');`.
- `build_dispute_filename($userName, ...)` already calls `sanitise_slug()` internally, so the stored filename stays a safe slug; the DB row now holds the human name.
- `insert_dispute(...)` stores the display name. No schema change; `user_name` is already `TEXT`.

Note: rows created by `backfill_disputes()` (pre-existing photos) keep their slug-derived name — acceptable and unavoidable.

### T8 — Block static files and VCS metadata  [H1, H2, H3]
Create `.htaccess` in the repo root (deployed `scoring/`):

```apache
# Apache 2.4 (requires mod_authz_core, enabled by default)
# If AllowOverride does not include `Options`, DELETE the `Options -Indexes`
# line below (it would otherwise 500 the whole directory) and rely on the
# host's directory-index setting.
Options -Indexes

<FilesMatch "\.(sqlite|sqlite-wal|sqlite-shm|sqlite-journal)$">
	Require all denied
</FilesMatch>

<FilesMatch "^(draw\.json|db\.php|auth\.php|security\.php|seed\.php)$">
	Require all denied
</FilesMatch>
# Apache 2.2 fallback: use `Deny from all` instead of `Require all denied`.
```

Create `backups/.htaccess`, `disputes/.htaccess` and `.git/.htaccess` (repo root), each containing:

```apache
Require all denied
```

Also add `.history/.htaccess` and `.kilo/.htaccess` with the same content for hygiene. Directory-level `.htaccess` is docroot-position independent and only affects HTTP, so PHP can still read `disputes/` filesystem files for T4.

`index.php` and `results.php` must remain reachable; `api.php` must remain reachable.

### T9 — Accessibility  [M4]
- `app.js`:
  - `numberCell(disciplineIndex, field, value, label)` — accept a context label and call `input.setAttribute('aria-label', label)`.
  - In `buildDisciplineTable()`, pass labels:
    - Score row home: `` `${game.homeTeam} ${capitalise(group.name)} score` ``; away: `` `${game.awayTeam} ${capitalise(group.name)} score` ``.
    - Ends row home: `` `${game.homeTeam} ${capitalise(group.name)} ends` ``; away likewise.
  - `playerCell(side, index, value, team)` — accept team and set `aria-label` = `` `${team} player ${index + 1}` ``. Pass `game.homeTeam` / `game.awayTeam` from `buildPlayerTable()`.
  - `applyNumberInvalidMarks()`: when a message exists, create/reuse a `<span id="sheetErr-<discIdx>-<field>" class="sr-only">message</span>` immediately after the input and set `input.setAttribute('aria-describedby', id)`; when cleared, remove the span and the attribute (keep the existing `aria-invalid` and `title`).
- `results.js`: give `applyInvalidMarks(container, marks, prefix)` the same error-span behaviour and update its two call sites to pass a distinct prefix per surface (`'editErr-'` for the inline editor, `'viewErr-'` for the dispute viewer) so ids never collide when an editor and the viewer are open at once. (Those inputs already have names via their wrapping `<label>`.)
- `index.php`: add `<h1 class="sr-only">Ellesmere Interclub Score Sheet</h1>` as the first element inside `<main class="page">`. (There is currently no `h1`; `results.php` already has one.)
- `styles.css`: add the standard visually-hidden utility:

```css
.sr-only {
	position: absolute;
	width: 1px; height: 1px;
	padding: 0; margin: -1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
	white-space: nowrap;
	border: 0;
}
```

### T10 — Favicon  [L2]
Add to the `<head>` of both `index.php` and `results.php`:

```html
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Ccircle cx='16' cy='16' r='14' fill='%231f6feb'/%3E%3C/svg%3E" />
```
The CSP `img-src` already allows `data:`.

### T11 — results.php name dialog Cancel  [L3]
- In `results.php` `#nameDialog`, add `<button type="button" id="nameCloseButton" class="secondary-button">Cancel</button>` beside Continue.
- In `results.js`: add `nameCloseButton` to the `el` map and a listener `el.nameCloseButton.addEventListener('click', () => { if (typeof el.nameDialog.close === 'function') el.nameDialog.close(); else el.nameDialog.removeAttribute('open'); });`.
- Use `type="button"` (not submit) so it does not run the name-saving submit handler (a Cancel must not clear the stored name).

---

## Verification

Setup: `php -r "echo password_hash('changeme', PASSWORD_BCRYPT), PHP_EOL;"` → paste into `auth.php`.

1. **Login gate (H4/M2)**
   - `GET /results.php` unauthenticated → password form only (no admin markup/data in HTML).
   - Wrong password → inline error (`role="alert"`), still gated. Correct → admin page; `?logout=1` → gated again.
   - `GET api.php?action=disputes` without cookie → `401 {"error":"unauthorized"}`; with the session cookie → data.
   - `GET api.php?action=dispute_image&id=1` unauthenticated → `401`; authenticated → `image/jpeg`; `id=999999` → `404`; `id=1&...` cannot escape `disputes/` (id-only lookup).
   - Dispute viewer image renders from `api.php?action=dispute_image&id=...` (Network tab).
2. **Server validation (M1)**
   - `POST action=save` with `singles homeScore=999` → `400 invalid_scores`; valid scores → `200`.
   - `POST action=save` with a changed `homeTeam` → `200`, then `action=load` shows the **original** team name.
   - Regression: score sheet and results inline editor still save valid results (also re-run the two-simultaneous-saves race → one `200`, one `409`).
3. **Static files (H1–H3)** — under Apache (note: `php -S` ignores `.htaccess`, so verify on the Apache host/VHOST): `/draw.sqlite`, `/draw.json`, `/backups/<file>.json`, `/disputes/<file>.jpg`, `/.git/config`, `/db.php`, `/auth.php`, `/security.php` → all `403`; `/`, `/results.php`, `/api.php` → normal.
4. **Headers (M3)**: responses include CSP, `X-Frame-Options: DENY`, `Referrer-Policy`, `X-Content-Type-Options`; `X-Powered-By` absent; HSTS present only over HTTPS.
5. **Accessibility (M4)**: every sheet score/player input has an `aria-label`; invalid inputs gain `aria-describedby` pointing at an `.sr-only` message; `index.php` has one `h1`; keyboard: name dialog opens focus-trapped, Escape closes and returns focus.
6. **L1**: file a dispute with "QA Tester" → Disputes table shows `QA Tester` (spaces preserved); stored filename still `..._QA-Tester_...`.
7. **L2**: no `favicon.ico` 404 in the console on either page.
8. **L3**: results name dialog Cancel closes it and does **not** clear the stored name.
9. **Regression sweep**: index save/dispute, results filters/inline edit/dispute viewer/counts/flags, mobile 375px (no page-level horizontal overflow).

## Risks & rollback
- **`.htaccess` needs `AllowOverride`** at the `scoring/` path; if it is off, H1–H3 remain exposed, **and M2 is only partially fixed** (the new image endpoint is gated, but `disputes/*.jpg` would still be directly reachable). Verify in step 3; if not honoured, the fallback is to move `draw.sqlite`/`draw.json`/`backups/`/`disputes/` above the docroot (out of scope here — flag to ops).
- **`php -S` ignores `.htaccess`**, so the static-file denies cannot be validated by the built-in server; test on the Apache host/VHOST (or a local Apache). The auth gate, validation and headers can all be tested with `php -S`.
- **CSP**: strict policy was chosen after confirming no inline scripts/styles; if a future asset needs `data:` scripts, it must be added explicitly. Dispute preview uses `data:`/`blob:` (allowed).
- **Sessions**: PHP session storage must be writable (default file handler is fine). Cookie `path` is `/`; if the app is mounted under a subpath, the cookie is still sent.
- **Hard-coded hash**: changing the password requires editing `auth.php` and redeploying (accepted). The hash is committed to source (accepted).
- **Validation strictness**: `validate_game()` requires every declared known discipline to carry all four fields. The current clients always save all declared disciplines, so this is safe; however any legacy row holding *partial* results (a discipline with no score entry) would be rejected on its next save. Check the live draw before deploying; if such rows exist, relax the rule to "validate only the disciplines present" (still applying conditions and score≥ends per present entry).
- **Rollback**: all changes are additive except the `save_game()` team-name columns (trivial to restore) and the `results.js` image URL; revert per-file via git.

## Do-not-touch
- **L4** backup rotation/pruning — not implemented.
- **L5** cross-page navigation links — not added.
