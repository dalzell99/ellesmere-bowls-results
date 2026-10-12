'use strict';

/*
 * Ellesmere Interclub Score Sheet — shared client validation.
 *
 * Loaded by index.php (before app.js) and results.php (before results.js) so
 * there is exactly ONE client-side copy of the score/condition rules and the
 * invalid-mark/aria wiring. Server-side db.php validate_game() MUST mirror the
 * rule functions here; a rule change must be applied in both places.
 */

const KNOWN_DISCIPLINES = ['singles', 'pairs', 'triples', 'fours'];

/**
 * Condition for a discipline in a competition's `conditions` map, or null when
 * the discipline has none (in which case any value is valid). Returns
 * { num, type } with type lowercased ("score" or "ends").
 */
function conditionFor(conditions, disciplineName) {
	if (!conditions || typeof conditions !== 'object') {
		return null;
	}
	const condition = conditions[disciplineName];
	if (!condition || typeof condition !== 'object') {
		return null;
	}
	const num = Number(condition.num);
	if (!Number.isFinite(num)) {
		return null;
	}
	return { num, type: String(condition.type || '').toLowerCase() };
}

/**
 * Condition violations for one discipline's values, as { field, message } pairs.
 * "score": each score is checked independently against the limit, and both sides
 * may not sit on the limit together. "ends": the two ends are a game total and
 * must not exceed the limit. An unknown type imposes no constraint.
 */
function conditionViolations(condition, values) {
	const { num, type } = condition;
	const violations = [];

	if (type === 'score') {
		for (const field of ['homeScore', 'awayScore']) {
			if (values[field] > num) {
				violations.push({ field, message: `Score can't exceed ${num}.` });
			}
		}
		if (values.homeScore === num && values.awayScore === num) {
			const message = `Both scores can't be ${num}.`;
			for (const field of ['homeScore', 'awayScore']) {
				if (!violations.some((violation) => violation.field === field)) {
					violations.push({ field, message });
				}
			}
		}
		return violations;
	}

	if (type === 'ends' && values.homeEnds + values.awayEnds > num) {
		const message = `Total ends can't exceed ${num}.`;
		violations.push({ field: 'homeEnds', message });
		violations.push({ field: 'awayEnds', message });
	}

	return violations;
}

/**
 * A team's score can never be less than the number of ends it won, since each
 * won end is worth at least one shot. Checked for both sides independently of
 * any competition condition. Returns { field, message } pairs.
 */
function scoreEndsViolations(values) {
	const violations = [];

	for (const side of ['home', 'away']) {
		const ends = values[`${side}Ends`];
		if (values[`${side}Score`] < ends) {
			const message = `Score can't be less than ends won (${ends}).`;
			violations.push({ field: `${side}Score`, message });
			violations.push({ field: `${side}Ends`, message });
		}
	}

	return violations;
}

/**
 * Toggle the invalid styling/message on every number input in a container from a
 * "discIdx|field" -> message map. When a message exists, an sr-only span
 * immediately after the input carries it for screen readers, linked via
 * aria-describedby; when cleared, the span and attributes are removed. `prefix`
 * keeps ids unique across surfaces (e.g. "sheetErr-", "editErr-", "viewErr-").
 */
function applyInvalidMarks(container, marks, prefix) {
	for (const input of container.querySelectorAll('input[data-disc-idx]')) {
		const key = `${input.dataset.discIdx}|${input.dataset.field}`;
		const message = marks.get(key);
		input.classList.toggle('is-invalid', message !== undefined);

		const messageId = `${prefix}${input.dataset.discIdx}-${input.dataset.field}`;
		let span = input.nextElementSibling;
		if (span && span.id !== messageId) {
			span = null;
		}

		if (message !== undefined) {
			input.setAttribute('aria-invalid', 'true');
			input.title = message;
			if (!span) {
				span = document.createElement('span');
				span.className = 'sr-only';
				span.id = messageId;
				input.insertAdjacentElement('afterend', span);
			}
			span.textContent = message;
			input.setAttribute('aria-describedby', messageId);
		} else {
			input.removeAttribute('aria-invalid');
			input.removeAttribute('title');
			input.removeAttribute('aria-describedby');
			if (span) {
				span.remove();
			}
		}
	}
}
