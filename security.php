<?php
/**
 * Ellesmere Interclub Score Sheet — shared security response headers.
 *
 * Required first by every page and API entry point (index.php, results.php,
 * api.php) so one place defines the hardening headers. The define() guard makes
 * a repeat require a no-op, and the block must run before any output.
 *
 * PHP 7.4-compatible: no union types, match, nullsafe, constructor promotion.
 */

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
