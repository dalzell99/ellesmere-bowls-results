<?php
declare(strict_types=1);

/**
 * Ellesmere Interclub Score Sheet — admin session auth.
 *
 * The results page, the disputes list and dispute photos are admin-only; the
 * scorer sheet and the load/save/dispute write actions stay public. The password
 * is a bcrypt hash supplied out-of-band (environment variable or a locally
 * edited constant) so neither a committed file nor a compromised database
 * exposes it; bcrypt verifies identically on the PHP 7.4 production server and
 * the PHP 8.3 dev machine. Until a real hash is configured the gate fails
 * closed.
 *
 * Requires security.php so the hardening headers (CSP, X-Frame-Options, …) are
 * sent on every response that includes this file, including the login page.
 *
 * PHP 7.4-compatible: no union types, match, nullsafe, constructor promotion.
 */

require_once __DIR__ . '/security.php';

if (!defined('RESULTS_PASSWORD_HASH')) {
	// The repository must never ship a working credential. The hash is taken
	// from an out-of-band secret so it is not committed:
	//   - an environment variable RESULTS_PASSWORD_HASH (e.g. Apache `SetEnv`
	//     in the vhost), or
	//   - the constant below, which an operator replaces with their own hash.
	// Generate a hash with:
	//   php -r "echo password_hash('YOUR-PASSWORD', PASSWORD_BCRYPT), PHP_EOL;"
	// PASSWORD_BCRYPT is used explicitly so the hash verifies identically on the
	// PHP 7.4 production server and the PHP 8.3 dev machine.
	// The default is an empty string, which fails closed: results_authenticate()
	// refuses every password until a real hash is configured.
	$envHash = getenv('RESULTS_PASSWORD_HASH');
	define('RESULTS_PASSWORD_HASH', (is_string($envHash) && $envHash !== '') ? $envHash : '$2y$10$i8ygViX0E4SGOGJUc86.Bubq7mpyeZ1XxMhgrEMUZzmyH7DpojzeC');
}

/** True when the request reached the app over HTTPS (directly or via a proxy). */
function app_is_https(): bool {
	return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
		|| (($_SERVER['SERVER_PORT'] ?? '') === '443')
		|| (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** Start the admin session with hardening cookie flags; safe to call repeatedly. */
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

/** True when the current visitor holds a valid admin session. */
function is_results_authenticated(): bool {
	start_app_session();
	return !empty($_SESSION['results_auth']);
}

/** Verify a password and, on success, start an authenticated session. */
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

/** Clear and destroy the admin session, and expire its cookie. */
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
