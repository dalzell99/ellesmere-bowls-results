# AGENTS.md

## Environment

- The production/deployed server runs **PHP 7.4**. Write and review all PHP code for PHP 7.4 compatibility.
- Do NOT use PHP 8.0+ syntax. Notably:
	- No union type declarations (e.g. `int|string`).
	- No `match` expressions.
	- No constructor property promotion.
	- No named arguments.
	- No nullsafe operator (`?->`).
	- No `mixed` type, no `static` return type.
	- No trailing comma in function parameter lists (PHP 7.4 allows it in calls only).
- PHP 7.4 features that ARE allowed: arrow functions (`fn() =>`), typed properties, spread in arrays, null coalescing assignment (`??=`).
- The local CLI PHP is 8.3, so `php -l` locally will NOT catch PHP 8-only syntax. Manually verify against the PHP 7.4 rules above.

## Layout

- The repo root maps to the deployed `scoring/` directory on the server (e.g. local `db.php` is served as `scoring/db.php`).

## Local development

- Run the local web server on port **8002** with PHP's built-in server from the repo root:
	```
	php -S localhost:8002
	```
	The site is then available at http://localhost:8002/.
