'use strict';

/*
 * Results & Disputes — client logic for results.php.
 *
 * A standalone companion to the score sheet. It lists every game in the draw
 * with Competition/Round/Team filters, edits any game inline — scores only once
 * it has results, plus the round date and teams while it is still resultless —
 * through the admin-only api.php?action=update_game under the same per-game
 * revision lock as the sheet, resets a scored game's scores and players after
 * confirmation (api.php?action=reset_game), adds fixtures
 * (api.php?action=add_game), lists
 * dispute photos with a fullscreen viewer whose disputed discipline is editable,
 * lets a dispute be deleted (api.php?action=delete_dispute), and reports
 * per-person game counts and the Rooster-then-Chatterton flag. The three areas
 * are tabs whose active one is mirrored in the URL hash.
 *
 * The score/condition rules and the invalid-mark/aria wiring are shared with
 * app.js via validation.js (loaded before this file). The remaining small
 * helpers copied below — byText, capitalise, deepCopy, parseWholeNumber,
 * element, option — MUST stay in sync with app.js. Unlike app.js, input lookups
 * here are scoped per rendered block.
 */

const API = 'api.php';
const NAME_KEY = 'scorerName';
const SCORE_FIELDS = ['homeScore', 'awayScore', 'homeEnds', 'awayEnds'];
const FIELD_LABELS = {
	homeScore: 'Home score',
	awayScore: 'Away score',
	homeEnds: 'Home ends',
	awayEnds: 'Away ends',
};

// Tab names, in display order. The active tab is mirrored in the URL hash so a
// reload (or a resync) returns to it.
const TAB_NAMES = ['games', 'disputes', 'counts'];


// Flag rule inputs. "Rooster" and "Chatterton" are matched case-insensitively;
// a player is flagged after 3+ Rooster games followed by a Chatterton game on a
// strictly later date. See computeFlags() for the full rationale.
const ROOSTER = 'rooster';
const CHATTERTON = 'chatterton';
const ROOSTER_MIN = 3;

const state = {
	draw: null,
	disputes: [],
	gamesById: new Map(),
	rowsByKey: new Map(), // "compIdx|roundIdx|gameIdx" -> row, rebuilt with gamesById
	filters: { competition: '', round: '', team: '' },
	editingKey: null, // "compIdx|roundIdx|gameIdx" of the expanded inline editor
	activeTab: 'games',
	saveInFlight: false,
	addGameInFlight: false, // true while an add-game request is in flight
	deleteInFlight: false, // true while a dispute deletion request is in flight
	disputeOpenId: null,
	disputeGameId: null,
	disputeEntry: null, // { index, name } of the editable score in the viewer
};

let noticeEl = null;

const el = {
	scorerNameDisplay: document.getElementById('scorerNameDisplay'),
	changeScorerButton: document.getElementById('changeScorerButton'),
	reloadButton: document.getElementById('reloadButton'),
	banners: document.getElementById('banners'),
	tabs: Array.from(document.querySelectorAll('[role="tab"][data-tab]')),
	panels: Array.from(document.querySelectorAll('[role="tabpanel"]')),
	filterCompetition: document.getElementById('filterCompetition'),
	filterRound: document.getElementById('filterRound'),
	filterTeam: document.getElementById('filterTeam'),
	gamesBody: document.getElementById('gamesBody'),
	disputesList: document.getElementById('disputesList'),
	countsBody: document.getElementById('countsBody'),
	addGameButton: document.getElementById('addGameButton'),
	addGameDialog: document.getElementById('addGameDialog'),
	addGameForm: document.getElementById('addGameForm'),
	addGameCompetition: document.getElementById('addGameCompetition'),
	addGameRound: document.getElementById('addGameRound'),
	addGameHome: document.getElementById('addGameHome'),
	addGameAway: document.getElementById('addGameAway'),
	addGameStatus: document.getElementById('addGameStatus'),
	addGameCloseButton: document.getElementById('addGameCloseButton'),
	teamNamesList: document.getElementById('teamNamesList'),
	nameDialog: document.getElementById('nameDialog'),
	nameForm: document.getElementById('nameForm'),
	nameInput: document.getElementById('nameInput'),
	nameCloseButton: document.getElementById('nameCloseButton'),
	disputeViewer: document.getElementById('disputeViewer'),
	disputeImage: document.getElementById('disputeImage'),
	disputeViewerTitle: document.getElementById('disputeViewerTitle'),
	disputeViewerSummary: document.getElementById('disputeViewerSummary'),
	disputeViewerForm: document.getElementById('disputeViewerForm'),
	disputeViewerStatus: document.getElementById('disputeViewerStatus'),
	disputeSaveButton: document.getElementById('disputeSaveButton'),
	disputeCloseButton: document.getElementById('disputeCloseButton'),
};

// ---------------------------------------------------------------------------
// Small helpers (copied from app.js — keep in sync)
// ---------------------------------------------------------------------------

function byText(a, b) {
	return String(a).localeCompare(String(b), undefined, { sensitivity: 'base', numeric: true });
}

function capitalise(word) {
	return String(word).charAt(0).toUpperCase() + String(word).slice(1);
}

function deepCopy(value) {
	return JSON.parse(JSON.stringify(value));
}

function parseWholeNumber(raw) {
	const trimmed = String(raw == null ? '' : raw).trim();
	if (!/^\d+$/.test(trimmed)) {
		return null;
	}
	const value = Number(trimmed);
	return Number.isSafeInteger(value) ? value : null;
}

/** Create an element with text set safely (never innerHTML). */
function element(tag, text, className) {
	const node = document.createElement(tag);
	if (text != null) {
		node.textContent = String(text);
	}
	if (className) {
		node.className = className;
	}
	return node;
}

function option(value, label) {
	const opt = document.createElement('option');
	opt.value = value;
	opt.textContent = label;
	return opt;
}

// ---------------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------------

bindStaticEvents();
applyScorerName();

// Restore the tab named in the URL hash (default: games) before the first
// render, so a reload lands on the same section without re-fetching.
activateTab(tabFromHash(), { updateHash: false });

if (!getScorerName()) {
	openNameDialog();
}

loadAll();

function bindStaticEvents() {
	el.changeScorerButton.addEventListener('click', openNameDialog);

	el.nameForm.addEventListener('submit', () => {
		const name = el.nameInput.value.trim();
		try {
			if (name) {
				localStorage.setItem(NAME_KEY, name);
			} else {
				localStorage.removeItem(NAME_KEY);
			}
		} catch (err) {
			/* storage unavailable — the save still proceeds with an empty name */
		}
		applyScorerName();
	});

	el.reloadButton.addEventListener('click', () => loadAll());

	// Tab switching: a click activates; the arrow keys move focus per the ARIA
	// tabs pattern. A hash change (back/forward or a manual edit) follows too.
	for (const tab of el.tabs) {
		tab.addEventListener('click', () => activateTab(tab.dataset.tab, { updateHash: true }));
		tab.addEventListener('keydown', onTabsKeydown);
	}
	window.addEventListener('hashchange', () => {
		activateTab(tabFromHash(), { updateHash: false });
	});

	// Cancel closes the name dialog without submitting, so the stored name is
	// left untouched (type="button" keeps the submit handler from running).
	el.nameCloseButton.addEventListener('click', () => {
		if (typeof el.nameDialog.close === 'function') {
			el.nameDialog.close();
		} else {
			el.nameDialog.removeAttribute('open');
		}
	});

	el.filterCompetition.addEventListener('change', () => {
		state.filters.competition = el.filterCompetition.value;
		state.filters.round = '';
		populateRoundFilter();
		renderGames();
	});
	el.filterRound.addEventListener('change', () => {
		state.filters.round = el.filterRound.value;
		renderGames();
	});
	el.filterTeam.addEventListener('change', () => {
		state.filters.team = el.filterTeam.value;
		renderGames();
	});

	el.gamesBody.addEventListener('click', onGamesBodyClick);
	el.gamesBody.addEventListener('input', onGamesBodyInput);
	el.gamesBody.addEventListener('change', onGamesBodyInput);

	el.addGameButton.addEventListener('click', openAddGameDialog);
	el.addGameCloseButton.addEventListener('click', closeAddGameDialog);
	el.addGameForm.addEventListener('submit', onAddGameSubmit);
	el.addGameCompetition.addEventListener('change', () => {
		populateAddGameRounds();
		updateAddGameSaveState();
	});
	el.addGameRound.addEventListener('change', updateAddGameSaveState);
	el.addGameHome.addEventListener('input', updateAddGameSaveState);
	el.addGameAway.addEventListener('input', updateAddGameSaveState);

	el.disputesList.addEventListener('click', onDisputesClick);
	el.disputesList.addEventListener('keydown', onDisputesKeydown);

	el.disputeCloseButton.addEventListener('click', closeDisputeViewer);
	el.disputeViewer.addEventListener('close', onDisputeViewerClosed);
	el.disputeSaveButton.addEventListener('click', onSaveDispute);
	el.disputeViewerForm.addEventListener('input', updateDisputeSaveState);
	el.disputeViewerForm.addEventListener('change', updateDisputeSaveState);
}

// ---------------------------------------------------------------------------
// Tabs
// ---------------------------------------------------------------------------

/** The tab name named by the URL hash, falling back to "games". */
function tabFromHash() {
	const raw = String(window.location.hash || '').replace(/^#/, '').toLowerCase();
	return TAB_NAMES.includes(raw) ? raw : 'games';
}

/**
 * Show one tab and its panel, hide the others, and (unless suppressed) mirror
 * the choice in the URL hash. Rendering is never triggered here: every panel is
 * always rendered and the tab only toggles `hidden`, so switching tabs cannot
 * drop the open editor or re-fetch the draw.
 */
function activateTab(name, options) {
	const opts = options || {};
	const target = TAB_NAMES.includes(name) ? name : 'games';
	state.activeTab = target;

	for (const tab of el.tabs) {
		const active = tab.dataset.tab === target;
		tab.classList.toggle('is-active', active);
		tab.setAttribute('aria-selected', active ? 'true' : 'false');
		tab.tabIndex = active ? 0 : -1;
		if (active && opts.focus) {
			tab.focus();
		}
	}
	for (const panel of el.panels) {
		panel.hidden = panel.id !== `panel${capitalise(target)}`;
	}

	if (opts.updateHash !== false && window.location.hash !== `#${target}`) {
		window.location.hash = target;
	}
}

/** Move focus between tabs with the arrow keys (and Home/End), per ARIA. */
function onTabsKeydown(event) {
	const key = event.key;
	let next = null;

	if (key === 'ArrowRight' || key === 'ArrowDown') {
		next = TAB_NAMES[(TAB_NAMES.indexOf(state.activeTab) + 1) % TAB_NAMES.length];
	} else if (key === 'ArrowLeft' || key === 'ArrowUp') {
		next = TAB_NAMES[(TAB_NAMES.indexOf(state.activeTab) - 1 + TAB_NAMES.length) % TAB_NAMES.length];
	} else if (key === 'Home') {
		next = TAB_NAMES[0];
	} else if (key === 'End') {
		next = TAB_NAMES[TAB_NAMES.length - 1];
	} else {
		return;
	}

	event.preventDefault();
	activateTab(next, { focus: true });
}

// ---------------------------------------------------------------------------
// Scorer name
// ---------------------------------------------------------------------------

function getScorerName() {
	try {
		return localStorage.getItem(NAME_KEY) || '';
	} catch (err) {
		return '';
	}
}

function applyScorerName() {
	el.scorerNameDisplay.textContent = getScorerName() || '\u2014';
}

function openNameDialog() {
	el.nameInput.value = getScorerName();
	if (typeof el.nameDialog.showModal === 'function') {
		el.nameDialog.showModal();
	} else {
		el.nameDialog.setAttribute('open', '');
	}
	el.nameInput.focus();
}

// ---------------------------------------------------------------------------
// Load / reload
// ---------------------------------------------------------------------------

async function fetchJson(url) {
	const res = await fetch(url, { headers: { Accept: 'application/json' } });
	let data = null;
	try {
		data = await res.json();
	} catch (err) {
		data = null;
	}
	return { status: res.status, ok: res.ok, data };
}

/** Fetch the draw and disputes together, then render everything. */
async function loadAll() {
	try {
		const [drawRes, disputesRes] = await Promise.all([
			fetchJson(`${API}?action=load`),
			fetchJson(`${API}?action=disputes`),
		]);
		// Only action=disputes is auth-gated (action=load is public), so a 401
		// always means the admin session expired. Reload the page so results.php
		// renders the sign-in gate again.
		if (disputesRes.status === 401) {
			showNotice({
				variant: 'danger',
				text: 'Session expired \u2014 reload to sign in.',
				actions: [{ label: 'Reload', onClick: () => window.location.reload() }],
			});
			return;
		}
		if (!drawRes.ok || !drawRes.data || !drawRes.data.success) {
			throw new Error((drawRes.data && drawRes.data.error) || `HTTP ${drawRes.status}`);
		}
		if (!disputesRes.ok || !disputesRes.data || !disputesRes.data.success) {
			throw new Error((disputesRes.data && disputesRes.data.error) || `HTTP ${disputesRes.status}`);
		}

		state.draw = drawRes.data.draw;
		state.disputes = Array.isArray(disputesRes.data.disputes) ? disputesRes.data.disputes : [];
		state.editingKey = null;
		rebuildGamesById();
		populateFilters();
		refreshTeamDatalist();
		renderAll();
		clearNotice();
	} catch (err) {
		showNotice({
			variant: 'danger',
			text: `Could not load data: ${err.message}`,
			actions: [{ label: 'Retry', onClick: () => loadAll() }],
		});
	}
}

/** Re-fetch just the draw and re-render, keeping the current filters. */
async function resync() {
	try {
		const res = await fetchJson(`${API}?action=load`);
		if (!res.ok || !res.data || !res.data.success) {
			throw new Error((res.data && res.data.error) || `HTTP ${res.status}`);
		}
		state.draw = res.data.draw;
		state.editingKey = null;
		rebuildGamesById();
		populateFilters();
		refreshTeamDatalist();
		renderAll();
		clearNotice();

		if (state.disputeOpenId !== null) {
			const dispute = state.disputes.find((d) => d.id === state.disputeOpenId);
			if (dispute) {
				renderViewerForm(dispute);
			}
		}
	} catch (err) {
		showNotice({
			variant: 'danger',
			text: `Reload failed: ${err.message}`,
			actions: [{ label: 'Retry', onClick: () => resync() }],
		});
	}
}

function rebuildGamesById() {
	const byId = new Map();
	const byKey = new Map();
	for (const row of flattenGames()) {
		byKey.set(rowKey(row), row);
		if (row.game && Number.isInteger(row.game.id)) {
			byId.set(row.game.id, row);
		}
	}
	state.gamesById = byId;
	state.rowsByKey = byKey;
}

function flattenGames() {
	const rows = [];
	const draw = Array.isArray(state.draw) ? state.draw : [];
	draw.forEach((competition, compIdx) => {
		(competition.rounds || []).forEach((round, roundIdx) => {
			(round.games || []).forEach((game, gameIdx) => {
				rows.push({ compIdx, roundIdx, gameIdx, competition, round, game });
			});
		});
	});
	return rows;
}

function rowKey(row) {
	return `${row.compIdx}|${row.roundIdx}|${row.gameIdx}`;
}

function findRowByKey(key) {
	return state.rowsByKey.get(key) || null;
}

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------

function populateFilters() {
	const draw = Array.isArray(state.draw) ? state.draw : [];

	const competitions = draw
		.map((competition, index) => ({ index, name: String(competition.competition || '') }))
		.sort((a, b) => byText(a.name, b.name));
	el.filterCompetition.textContent = '';
	el.filterCompetition.appendChild(option('', 'All competitions'));
	for (const competition of competitions) {
		el.filterCompetition.appendChild(option(String(competition.index), competition.name));
	}
	el.filterCompetition.value = state.filters.competition;

	// Team list derives every distinct name across both sides of the whole draw.
	el.filterTeam.textContent = '';
	el.filterTeam.appendChild(option('', 'All teams'));
	for (const name of teamNames()) {
		el.filterTeam.appendChild(option(name, name));
	}
	el.filterTeam.value = state.filters.team;

	populateRoundFilter();
}

/**
 * Distinct team names across both sides of the whole draw, de-duped
 * case-insensitively (first spelling seen wins) and sorted. Shared by the Team
 * filter and the add-game datalist so both offer exactly the same names.
 */
function teamNames() {
	const teams = new Map(); // lowercase -> first spelling seen
	const draw = Array.isArray(state.draw) ? state.draw : [];
	for (const competition of draw) {
		for (const round of competition.rounds || []) {
			for (const game of round.games || []) {
				for (const raw of [game.homeTeam, game.awayTeam]) {
					const name = String(raw == null ? '' : raw).trim();
					if (name && !teams.has(name.toLowerCase())) {
						teams.set(name.toLowerCase(), name);
					}
				}
			}
		}
	}
	return [...teams.values()].sort(byText);
}

/** Rebuild the add-game team-name datalist from the current draw. */
function refreshTeamDatalist() {
	if (!el.teamNamesList) {
		return;
	}
	el.teamNamesList.textContent = '';
	for (const name of teamNames()) {
		const opt = document.createElement('option');
		opt.value = name;
		el.teamNamesList.appendChild(opt);
	}
}

/** Round options cascade from the chosen competition; disabled until one is picked. */
function populateRoundFilter() {
	const compIdx = parseInt(el.filterCompetition.value, 10);

	el.filterRound.textContent = '';
	el.filterRound.appendChild(option('', 'All rounds'));

	if (!Number.isInteger(compIdx) || compIdx < 0 || !state.draw[compIdx]) {
		el.filterRound.disabled = true;
		el.filterRound.value = '';
		return;
	}

	const competition = state.draw[compIdx];
	const rounds = (competition.rounds || [])
		.map((round, index) => ({ index, number: Number(round.round) }))
		.sort((a, b) => a.number - b.number);

	for (const round of rounds) {
		const label = Number.isFinite(round.number) ? `Round ${round.number}` : `Round ${round.index + 1}`;
		el.filterRound.appendChild(option(String(round.index), label));
	}
	el.filterRound.disabled = rounds.length === 0;
	el.filterRound.value = state.filters.round;
	if (el.filterRound.value !== state.filters.round) {
		el.filterRound.value = '';
		state.filters.round = '';
	}
}

function filteredRows() {
	const f = state.filters;
	return flattenGames().filter((row) => {
		if (f.competition !== '' && String(row.compIdx) !== f.competition) {
			return false;
		}
		if (f.competition !== '' && f.round !== '' && String(row.roundIdx) !== f.round) {
			return false;
		}
		if (f.team !== '' && row.game.homeTeam !== f.team && row.game.awayTeam !== f.team) {
			return false;
		}
		return true;
	});
}

function roundLabel(round, roundIdx) {
	return Number.isFinite(Number(round.round)) ? `Round ${round.round}` : `Round ${roundIdx + 1}`;
}

// ---------------------------------------------------------------------------
// Games table
// ---------------------------------------------------------------------------

const COLUMN_COUNT = 7; // Competition, Round, Date, Home, Away, Result, Edit

function renderAll() {
	renderGames();
	renderDisputes();
	renderCounts();
}

function renderGames() {
	const rows = filteredRows();
	el.gamesBody.textContent = '';

	if (!rows.length) {
		el.gamesBody.appendChild(element('p', 'No games match these filters.', 'empty-state'));
		return;
	}

	const table = element('table', null, 'admin-table games-table');
	const thead = document.createElement('thead');
	const headRow = document.createElement('tr');
	for (const label of ['Competition', 'Round', 'Date', 'Home', 'Away', 'Result', 'Edit']) {
		headRow.appendChild(element('th', label));
	}
	thead.appendChild(headRow);
	table.appendChild(thead);

	const tbody = document.createElement('tbody');
	for (const row of rows) {
		const key = rowKey(row);

		const tr = document.createElement('tr');
		tr.className = 'admin-row';
		tr.appendChild(element('td', String(row.competition.competition || '')));
		tr.appendChild(element('td', roundLabel(row.round, row.roundIdx)));
		tr.appendChild(element('td', row.round.date ? String(row.round.date) : '\u2014'));
		tr.appendChild(element('td', String(row.game.homeTeam || '')));
		tr.appendChild(element('td', String(row.game.awayTeam || '')));

		const resultCell = element('td', null, 'result-cell');
		const lines = resultLines(row.game);
		if (lines.length) {
			for (const line of lines) {
				resultCell.appendChild(element('div', line, 'result-line'));
			}
		} else {
			resultCell.appendChild(element('span', '\u2014', 'muted'));
		}
		tr.appendChild(resultCell);

		const editCell = element('td', null, 'edit-cell');
		// Every game is editable: a scored game offers score-only editing, a
		// resultless one also offers its round date and teams.
		const toggle = element('button', state.editingKey === key ? 'Close' : 'Edit', 'secondary-button');
		toggle.type = 'button';
		toggle.dataset.action = 'toggle-edit';
		toggle.dataset.key = key;
		editCell.appendChild(toggle);
		tr.appendChild(editCell);
		tbody.appendChild(tr);

		if (state.editingKey === key) {
			const editRow = document.createElement('tr');
			editRow.className = 'admin-row admin-row--edit';
			const cell = document.createElement('td');
			cell.colSpan = COLUMN_COUNT;
			cell.appendChild(buildEditor(row));
			editRow.appendChild(cell);
			tbody.appendChild(editRow);
		}
	}

	table.appendChild(tbody);
	el.gamesBody.appendChild(table);
}

/** True when any discipline score has at least one saved score/ends value. */
function gameHasResults(game) {
	const scores = Array.isArray(game.scores) ? game.scores : [];
	return scores.some((entry) => scoreEntryHasResults(entry));
}

function scoreEntryHasResults(entry) {
	if (!entry) {
		return false;
	}
	return SCORE_FIELDS.some((field) => entry[field] !== null && entry[field] !== undefined && entry[field] !== '');
}

/** One display line per scored discipline, e.g. "Singles 21-12 (ends 9-4)". */
function resultLines(game) {
	const scores = Array.isArray(game.scores) ? game.scores : [];
	const lines = [];
	for (const entry of scores) {
		if (!scoreEntryHasResults(entry)) {
			continue;
		}
		let line = `${capitalise(entry.discipline)} ${showNum(entry.homeScore)}\u2013${showNum(entry.awayScore)}`;
		if (hasEnds(entry)) {
			line += ` (ends ${showNum(entry.homeEnds)}\u2013${showNum(entry.awayEnds)})`;
		}
		lines.push(line);
	}
	return lines;
}

function hasEnds(entry) {
	return (entry.homeEnds !== null && entry.homeEnds !== undefined)
		|| (entry.awayEnds !== null && entry.awayEnds !== undefined);
}

function showNum(value) {
	return value === null || value === undefined || value === '' ? '\u2013' : String(value);
}

// ---------------------------------------------------------------------------
// Inline editor
// ---------------------------------------------------------------------------

function knownDisciplines(competition) {
	const declared = Array.isArray(competition.discipline) ? competition.discipline : [];
	return declared
		.map((name, index) => ({ name: String(name).toLowerCase(), index }))
		.filter((entry) => KNOWN_DISCIPLINES.includes(entry.name));
}

function findScoreByName(scores, name) {
	return scores.find((entry) => String(entry.discipline || '').toLowerCase() === name) || null;
}

/**
 * True when any game in the round already holds a result. Drives the round-date
 * guard: the date is a round-level field, so it is editable only while the round
 * has no results anywhere.
 */
function roundHasResults(compIdx, roundIdx) {
	const competition = Array.isArray(state.draw) ? state.draw[compIdx] : null;
	const round = competition ? (competition.rounds || [])[roundIdx] : null;
	if (!round) {
		return false;
	}
	return (round.games || []).some((game) => gameHasResults(game));
}

function buildEditor(row) {
	const wrap = element('div', null, 'game-edit');
	const scored = gameHasResults(row.game);
	const entries = knownDisciplines(row.competition);

	// A scored game whose disciplines this page cannot render has nothing to edit
	// here, since only its scores would be editable.
	if (scored && !entries.length) {
		wrap.appendChild(element('p', 'This competition has no scoreable disciplines.', 'game-edit__note'));
		return wrap;
	}

	if (scored) {
		wrap.appendChild(
			element('p', 'This game already has results, so only its scores can be edited here.', 'game-edit__note')
		);
	} else {
		wrap.appendChild(buildDetailsBlock(row));
	}

	if (!entries.length) {
		wrap.appendChild(element('p', 'This competition has no scoreable disciplines.', 'game-edit__note'));
	}
	const savedScores = Array.isArray(row.game.scores) ? row.game.scores : [];
	for (const entry of entries) {
		const saved = savedScores[entry.index] || findScoreByName(savedScores, entry.name) || null;
		wrap.appendChild(buildDisciplineBlock(entry, row.game, saved));
	}

	const actions = element('div', null, 'game-edit__actions');
	const save = element('button', 'Save', 'primary-button');
	save.type = 'button';
	save.dataset.action = 'save-game';
	actions.appendChild(save);
	// Reset sits beside Save and is offered only for a game that already holds
	// results, since it clears exactly those (its scores and players).
	if (scored) {
		const reset = element('button', 'Reset', 'secondary-button danger-button');
		reset.type = 'button';
		reset.dataset.action = 'reset-game';
		reset.setAttribute('aria-label', 'Reset this game, removing its scores and players');
		actions.appendChild(reset);
	}
	const cancel = element('button', 'Close', 'secondary-button');
	cancel.type = 'button';
	cancel.dataset.action = 'cancel-edit';
	const status = element('span', '', 'save-status');
	status.dataset.role = 'edit-status';
	actions.appendChild(cancel);
	actions.appendChild(status);
	wrap.appendChild(actions);

	updateEditorSaveState(wrap);
	return wrap;
}

/**
 * The date/team fields shown only for a resultless game. The date edits the
 * round (shared by every game in it) and is disabled — with a note — once any
 * game in the round has a result. The team inputs seed from the current fixture.
 */
function buildDetailsBlock(row) {
	const block = element('div', null, 'edit-details');
	const grid = element('div', null, 'edit-details__grid');

	const dateDisabled = roundHasResults(row.compIdx, row.roundIdx);
	const dateInput = document.createElement('input');
	dateInput.type = 'text';
	dateInput.maxLength = 40;
	dateInput.placeholder = 'YYYY-MM-DD';
	dateInput.autocomplete = 'off';
	dateInput.dataset.role = 'round-date';
	if (row.round.date) {
		dateInput.value = String(row.round.date);
	}
	if (dateDisabled) {
		dateInput.disabled = true;
	}

	const homeInput = document.createElement('input');
	homeInput.type = 'text';
	homeInput.maxLength = 120;
	homeInput.autocomplete = 'off';
	homeInput.dataset.role = 'home-team';
	homeInput.value = String(row.game.homeTeam || '');

	const awayInput = document.createElement('input');
	awayInput.type = 'text';
	awayInput.maxLength = 120;
	awayInput.autocomplete = 'off';
	awayInput.dataset.role = 'away-team';
	awayInput.value = String(row.game.awayTeam || '');

	const fields = [['Date', dateInput], ['Home team', homeInput], ['Away team', awayInput]];
	for (const pair of fields) {
		const fieldWrap = element('label', null, 'edit-field');
		fieldWrap.appendChild(element('span', pair[0], 'edit-field__label'));
		fieldWrap.appendChild(pair[1]);
		grid.appendChild(fieldWrap);
	}
	block.appendChild(grid);

	if (dateDisabled) {
		block.appendChild(
			element(
				'p',
				'The round date is locked because another game in this round already has a result.',
				'game-edit__note'
			)
		);
	}

	return block;
}

/** One discipline's score/ends block: players for context plus four inputs. */
function buildDisciplineBlock(entry, game, saved) {
	const block = element('div', null, 'edit-disc');
	block.dataset.discIdx = String(entry.index);
	block.dataset.discipline = entry.name;

	const head = element('div', null, 'edit-disc__head');
	head.appendChild(element('span', capitalise(entry.name), 'edit-disc__name'));
	block.appendChild(head);

	const players = element('div', null, 'edit-disc__players');
	players.appendChild(element('div', `Home: ${playerList(game.homePlayers)}`, 'edit-disc__players-line'));
	players.appendChild(element('div', `Away: ${playerList(game.awayPlayers)}`, 'edit-disc__players-line'));
	block.appendChild(players);

	const grid = element('div', null, 'edit-disc__grid');
	for (const field of SCORE_FIELDS) {
		const fieldWrap = element('label', null, 'edit-field');
		fieldWrap.appendChild(element('span', FIELD_LABELS[field], 'edit-field__label'));
		const input = document.createElement('input');
		input.type = 'number';
		input.min = '0';
		input.step = '1';
		input.setAttribute('inputmode', 'numeric');
		input.dataset.discIdx = String(entry.index);
		input.dataset.field = field;
		const value = saved ? saved[field] : null;
		if (value !== null && value !== undefined && value !== '') {
			input.value = String(value);
		}
		fieldWrap.appendChild(input);
		grid.appendChild(fieldWrap);
	}
	block.appendChild(grid);

	return block;
}

function playerList(names) {
	if (!Array.isArray(names) || !names.length) {
		return '\u2014';
	}
	return names.join(', ');
}

function onGamesBodyClick(event) {
	const button = event.target instanceof Element ? event.target.closest('button[data-action]') : null;
	if (!button) {
		return;
	}
	const action = button.dataset.action;

	if (action === 'toggle-edit') {
		const key = button.dataset.key;
		state.editingKey = state.editingKey === key ? null : key;
		renderGames();
	} else if (action === 'cancel-edit') {
		state.editingKey = null;
		renderGames();
	} else if (action === 'save-game') {
		const editorEl = button.closest('.game-edit');
		const row = state.editingKey ? findRowByKey(state.editingKey) : null;
		if (editorEl && row) {
			onSaveRow(row, editorEl);
		}
	} else if (action === 'reset-game') {
		const row = state.editingKey ? findRowByKey(state.editingKey) : null;
		if (row) {
			onResetRow(row);
		}
	}
}

function onGamesBodyInput(event) {
	const editorEl = event.target instanceof Element ? event.target.closest('.game-edit') : null;
	if (editorEl) {
		updateEditorSaveState(editorEl);
	}
}

// ---------------------------------------------------------------------------
// Validation (rules and invalid-mark wiring live in validation.js; the shared
// helpers are used here, scoped to one container)
// ---------------------------------------------------------------------------

/**
 * Read and validate the given discipline entries out of a container. Returns
 * { ok, values } where values maps the entry index to
 * { position, discipline, homeScore, awayScore, homeEnds, awayEnds }.
 *
 * `mode` controls how an incomplete discipline is treated:
 *   - 'per-discipline' (the games editor): a discipline is either fully blank
 *     (skipped) or fully filled; some-but-not-all filled is invalid;
 *   - 'require-all' (the dispute viewer, and the default): every field must be
 *     filled, so a blank entry can never clear a disputed result.
 * A fully filled discipline is always rule-checked (condition + score-vs-ends).
 */
function validateEntries(container, entries, competition, prefix, mode) {
	const requireAll = mode !== 'per-discipline';
	let ok = true;
	const marks = new Map(); // "idx|field" -> message
	const values = new Map();
	const conditions = competition ? competition.conditions : null;

	for (const entry of entries) {
		const result = { position: entry.index, discipline: entry.name };
		const blanks = [];

		for (const field of SCORE_FIELDS) {
			const input = container.querySelector(
				`input[data-disc-idx="${entry.index}"][data-field="${field}"]`
			);
			const value = input ? parseWholeNumber(input.value) : null;
			result[field] = value;
			if (value === null) {
				blanks.push(field);
			}
		}

		const allPresent = blanks.length === 0;
		const allBlank = blanks.length === SCORE_FIELDS.length;

		if (requireAll) {
			// Unchanged behaviour: a missing field blocks the save with no mark.
			if (!allPresent) {
				ok = false;
			}
		} else if (!allBlank && !allPresent) {
			// A discipline is all-or-nothing: flag the fields still to fill.
			ok = false;
			for (const field of blanks) {
				marks.set(`${entry.index}|${field}`, 'Complete all four fields or leave the discipline blank.');
			}
		}

		if (allPresent) {
			const condition = conditionFor(conditions, entry.name);
			const violations = condition ? conditionViolations(condition, result) : [];
			// Score-vs-ends is universal, so it applies even with no condition.
			violations.push(...scoreEndsViolations(result));
			for (const violation of violations) {
				ok = false;
				marks.set(`${entry.index}|${violation.field}`, violation.message);
			}
		}
		values.set(entry.index, result);
	}

	applyInvalidMarks(container, marks, prefix || 'editErr-');
	return { ok, values };
}

/**
 * True when a discipline that already holds a stored result is left blank or
 * incomplete in the editor. The client mirror of the server's result_removed
 * guard: a correction may change a saved score, never erase it.
 */
function editorBlanksStoredResult(row, values) {
	const savedScores = Array.isArray(row.game.scores) ? row.game.scores : [];
	for (const entry of knownDisciplines(row.competition)) {
		const saved = savedScores[entry.index] || findScoreByName(savedScores, entry.name) || null;
		if (!saved || !scoreEntryHasResults(saved)) {
			continue;
		}
		const result = values.get(entry.index);
		if (!result || SCORE_FIELDS.some((field) => result[field] === null)) {
			return true;
		}
	}
	return false;
}

function updateEditorSaveState(editorEl) {
	const saveButton = editorEl.querySelector('button[data-action="save-game"]');
	if (!saveButton) {
		return;
	}
	const row = state.editingKey ? findRowByKey(state.editingKey) : null;
	if (!row) {
		saveButton.disabled = true;
		return;
	}
	const { ok, values } = validateEntries(
		editorEl,
		knownDisciplines(row.competition),
		row.competition,
		'editErr-',
		'per-discipline'
	);
	saveButton.disabled = !ok || editorBlanksStoredResult(row, values) || state.saveInFlight;
}

// ---------------------------------------------------------------------------
// Save (shared by the inline editor and the dispute viewer)
// ---------------------------------------------------------------------------

/**
 * POST one game's edit through the admin-only update_game action. `roundDate`
 * is sent only for a resultless game with an editable date; omitting it leaves
 * the stored round date untouched.
 */
async function postGameUpdate(gameId, revision, game, roundDate) {
	const body = {
		gameId,
		revision,
		userName: getScorerName(),
		game,
	};
	if (roundDate !== null && roundDate !== undefined) {
		body.roundDate = roundDate;
	}
	const res = await fetch(`${API}?action=update_game`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify(body),
	});
	let data = null;
	try {
		data = await res.json();
	} catch (err) {
		data = null;
	}
	return { status: res.status, ok: res.ok, data };
}

function setSaveBusy(busy) {
	state.saveInFlight = busy;
	if (busy) {
		for (const button of el.gamesBody.querySelectorAll('button[data-action="save-game"], button[data-action="reset-game"]')) {
			button.disabled = true;
		}
		el.disputeSaveButton.disabled = true;
		return;
	}
	for (const editorEl of el.gamesBody.querySelectorAll('.game-edit')) {
		updateEditorSaveState(editorEl);
	}
	for (const button of el.gamesBody.querySelectorAll('button[data-action="reset-game"]')) {
		button.disabled = false;
	}
	updateDisputeSaveState();
}

async function onSaveRow(row, editorEl) {
	if (state.saveInFlight) {
		return;
	}
	const scored = gameHasResults(row.game);
	const entries = knownDisciplines(row.competition);
	const { ok, values } = validateEntries(editorEl, entries, row.competition, 'editErr-', 'per-discipline');
	if (!ok || editorBlanksStoredResult(row, values)) {
		return;
	}
	const status = editorEl.querySelector('[data-role="edit-status"]');

	// Preserve players and any other keys; the scores are replaced wholesale with
	// the fully-filled disciplines (blank ones are dropped, not stored as nulls).
	const game = deepCopy(row.game);
	game.scores = entries
		.map((entry) => values.get(entry.index))
		.filter((result) => SCORE_FIELDS.every((field) => result[field] !== null));

	// Only a resultless game may change its teams and (guard permitting) its
	// round date; a scored game keeps the stored values untouched.
	let roundDate = null;
	if (!scored) {
		const homeInput = editorEl.querySelector('[data-role="home-team"]');
		const awayInput = editorEl.querySelector('[data-role="away-team"]');
		const dateInput = editorEl.querySelector('[data-role="round-date"]');
		game.homeTeam = homeInput ? homeInput.value.trim() : game.homeTeam;
		game.awayTeam = awayInput ? awayInput.value.trim() : game.awayTeam;
		if (!game.homeTeam || !game.awayTeam) {
			setStatusEl(status, 'Home and away teams are required.', 'error');
			return;
		}
		// A disabled date (another game in the round has a result) is never sent,
		// so the server's date guard is not tripped by an unchanged value.
		if (dateInput && !dateInput.disabled) {
			roundDate = dateInput.value.trim();
		}
	}

	setSaveBusy(true);
	try {
		const { status: code, ok: httpOk, data } = await postGameUpdate(row.game.id, row.game.revision, game, roundDate);
		if (code === 401) {
			showNotice({
				variant: 'danger',
				text: 'Session expired \u2014 reload to sign in.',
				actions: [{ label: 'Reload', onClick: () => window.location.reload() }],
			});
			return;
		}
		if (code === 409) {
			state.editingKey = null;
			renderGames();
			showNotice({
				variant: 'danger',
				text: 'Someone else saved this fixture. Reload to see their result.',
				actions: [{ label: 'Reload', onClick: () => resync() }],
			});
			return;
		}
		if (!httpOk || !data || !data.success) {
			setStatusEl(status, `Save failed: ${(data && data.error) || code}`, 'error');
			return;
		}

		game.revision = Number.isInteger(data.revision) ? data.revision : row.game.revision + 1;
		const round = state.draw[row.compIdx].rounds[row.roundIdx];
		round.games[row.gameIdx] = game;
		if (roundDate !== null) {
			if (roundDate === '') {
				delete round.date;
			} else {
				round.date = roundDate;
			}
		}
		rebuildGamesById();
		state.editingKey = null;
		renderGames();
		renderCounts();
		refreshTeamDatalist();
		showNotice({ text: 'Saved.', autoClearMs: 3000 });
	} catch (err) {
		setStatusEl(status, `Save failed: ${err.message}`, 'error');
	} finally {
		setSaveBusy(false);
	}
}

// ---------------------------------------------------------------------------
// Reset game
// ---------------------------------------------------------------------------

/** POST one game's reset through the admin-only reset_game action. */
async function postResetGame(gameId, revision) {
	const res = await fetch(`${API}?action=reset_game`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({
			gameId,
			revision,
			userName: getScorerName(),
		}),
	});
	let data = null;
	try {
		data = await res.json();
	} catch (err) {
		data = null;
	}
	return { status: res.status, ok: res.ok, data };
}

/**
 * Reset one game after confirmation: delete its scores and players
 * (api.php?action=reset_game, admin only). Unlike a save, this deliberately
 * erases stored results, so it must be confirmed first. On success the local
 * game is cleared in place and the editor re-renders as a resultless one (the
 * teams and round date are left untouched, so the fixture itself survives).
 */
async function onResetRow(row) {
	if (state.saveInFlight || !row.game || !Number.isInteger(row.game.id)) {
		return;
	}
	const label =
		`${row.competition.competition || ''} \u00b7 ${roundLabel(row.round, row.roundIdx)} ` +
		`\u00b7 ${row.game.homeTeam} vs ${row.game.awayTeam}`;
	if (!window.confirm(`Reset this game?\n\n${label}\n\nIts scores and players will be removed. This cannot be undone.`)) {
		return;
	}

	setSaveBusy(true);
	try {
		const { status: code, ok: httpOk, data } = await postResetGame(row.game.id, row.game.revision);
		if (code === 401) {
			showNotice({
				variant: 'danger',
				text: 'Session expired \u2014 reload to sign in.',
				actions: [{ label: 'Reload', onClick: () => window.location.reload() }],
			});
			return;
		}
		if (code === 409) {
			state.editingKey = null;
			renderGames();
			showNotice({
				variant: 'danger',
				text: 'Someone else saved this fixture. Reload to see their result.',
				actions: [{ label: 'Reload', onClick: () => resync() }],
			});
			return;
		}
		if (!httpOk || !data || !data.success) {
			showNotice({ variant: 'danger', text: `Reset failed: ${(data && data.error) || code}` });
			return;
		}

		// Mirror the reset locally: no scores, no players, new revision. The editor
		// key is kept so the row re-renders as the resultless editor.
		row.game.revision = Number.isInteger(data.revision) ? data.revision : row.game.revision + 1;
		row.game.homePlayers = [];
		row.game.awayPlayers = [];
		row.game.scores = [];
		rebuildGamesById();
		renderGames();
		renderCounts();
		refreshTeamDatalist();
		showNotice({ text: 'Game reset.', autoClearMs: 3000 });
	} catch (err) {
		showNotice({ variant: 'danger', text: `Reset failed: ${err.message}` });
	} finally {
		setSaveBusy(false);
	}
}

// ---------------------------------------------------------------------------
// Add game
// ---------------------------------------------------------------------------

/** Open the Add-game dialog, rebuilding its selects and datalist from the draw. */
function openAddGameDialog() {
	populateAddGameCompetitions();
	populateAddGameRounds();
	el.addGameHome.value = '';
	el.addGameAway.value = '';
	setStatusEl(el.addGameStatus, '', '');
	refreshTeamDatalist();
	updateAddGameSaveState();

	if (typeof el.addGameDialog.showModal === 'function') {
		el.addGameDialog.showModal();
	} else {
		el.addGameDialog.setAttribute('open', '');
	}
	el.addGameCompetition.focus();
}

function closeAddGameDialog() {
	if (typeof el.addGameDialog.close === 'function') {
		el.addGameDialog.close();
	} else {
		el.addGameDialog.removeAttribute('open');
	}
}

/** Fill the competition select from the draw in the same order as the filters. */
function populateAddGameCompetitions() {
	const draw = Array.isArray(state.draw) ? state.draw : [];
	const competitions = draw
		.map((competition, index) => ({ index, name: String(competition.competition || '') }))
		.sort((a, b) => byText(a.name, b.name));

	el.addGameCompetition.textContent = '';
	for (const competition of competitions) {
		el.addGameCompetition.appendChild(option(String(competition.index), competition.name));
	}
	if (competitions.length) {
		el.addGameCompetition.value = String(competitions[0].index);
	}
}

/** Cascade the round select from the chosen competition. */
function populateAddGameRounds() {
	const compIdx = parseInt(el.addGameCompetition.value, 10);
	el.addGameRound.textContent = '';

	if (!Number.isInteger(compIdx) || compIdx < 0 || !state.draw || !state.draw[compIdx]) {
		el.addGameRound.disabled = true;
		return;
	}

	const competition = state.draw[compIdx];
	const rounds = (competition.rounds || [])
		.map((round, index) => ({ index, number: Number(round.round) }))
		.sort((a, b) => a.number - b.number);

	for (const round of rounds) {
		const label = Number.isFinite(round.number) ? `Round ${round.number}` : `Round ${round.index + 1}`;
		el.addGameRound.appendChild(option(String(round.index), label));
	}
	el.addGameRound.disabled = rounds.length === 0;
}

function updateAddGameSaveState() {
	const compIdx = parseInt(el.addGameCompetition.value, 10);
	const roundIdx = parseInt(el.addGameRound.value, 10);
	const hasRound = Number.isInteger(compIdx) && compIdx >= 0
		&& Number.isInteger(roundIdx) && roundIdx >= 0
		&& Boolean(state.draw && state.draw[compIdx] && (state.draw[compIdx].rounds || [])[roundIdx]);
	const home = el.addGameHome.value.trim();
	const away = el.addGameAway.value.trim();
	const addButton = el.addGameForm.querySelector('button[type="submit"]');
	if (addButton) {
		addButton.disabled = !hasRound || !home || !away || state.addGameInFlight;
	}
}

async function postAddGame(roundId, homeTeam, awayTeam) {
	const res = await fetch(`${API}?action=add_game`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({
			roundId,
			homeTeam,
			awayTeam,
			userName: getScorerName(),
		}),
	});
	let data = null;
	try {
		data = await res.json();
	} catch (err) {
		data = null;
	}
	return { status: res.status, ok: res.ok, data };
}

/**
 * Submit the Add-game dialog: append a resultless fixture to the chosen round.
 * On success the new game is pushed locally (so the Games table and counts
 * update without a reload) and the dialog closes. A round that vanished between
 * load and submit comes back as an error and is surfaced in the dialog.
 */
async function onAddGameSubmit(event) {
	event.preventDefault();
	if (state.addGameInFlight) {
		return;
	}

	const compIdx = parseInt(el.addGameCompetition.value, 10);
	const roundIdx = parseInt(el.addGameRound.value, 10);
	const competition = state.draw && Number.isInteger(compIdx) ? state.draw[compIdx] : null;
	const round = competition ? (competition.rounds || [])[roundIdx] : null;
	const homeTeam = el.addGameHome.value.trim();
	const awayTeam = el.addGameAway.value.trim();

	if (!round || !Number.isInteger(round.id)) {
		setStatusEl(el.addGameStatus, 'Choose an existing competition and round.', 'error');
		return;
	}
	if (!homeTeam || !awayTeam) {
		setStatusEl(el.addGameStatus, 'Enter both team names.', 'error');
		return;
	}

	state.addGameInFlight = true;
	updateAddGameSaveState();
	try {
		const { status: code, ok, data } = await postAddGame(round.id, homeTeam, awayTeam);
		if (code === 401) {
			showNotice({
				variant: 'danger',
				text: 'Session expired \u2014 reload to sign in.',
				actions: [{ label: 'Reload', onClick: () => window.location.reload() }],
			});
			return;
		}
		if (!ok || !data || !data.success) {
			setStatusEl(el.addGameStatus, `Could not add game: ${(data && data.error) || code}`, 'error');
			return;
		}

		round.games = Array.isArray(round.games) ? round.games : [];
		round.games.push({
			id: data.gameId,
			revision: 1,
			homeTeam,
			awayTeam,
			homePlayers: [],
			awayPlayers: [],
			scores: [],
		});
		rebuildGamesById();
		renderGames();
		renderCounts();
		refreshTeamDatalist();
		closeAddGameDialog();
		showNotice({ text: 'Game added.', autoClearMs: 3000 });
	} catch (err) {
		setStatusEl(el.addGameStatus, `Could not add game: ${err.message}`, 'error');
	} finally {
		state.addGameInFlight = false;
		updateAddGameSaveState();
	}
}

// ---------------------------------------------------------------------------
// Disputes list
// ---------------------------------------------------------------------------

function renderDisputes() {
	el.disputesList.textContent = '';
	const list = Array.isArray(state.disputes) ? state.disputes : [];

	if (!list.length) {
		el.disputesList.appendChild(element('p', 'No disputes recorded.', 'empty-state'));
		return;
	}

	const table = element('table', null, 'admin-table disputes-table');
	const thead = document.createElement('thead');
	const headRow = document.createElement('tr');
	for (const label of ['Created', 'Competition', 'Round', 'Fixture', 'Discipline', 'Scorer', 'Actions']) {
		headRow.appendChild(element('th', label));
	}
	thead.appendChild(headRow);
	table.appendChild(thead);

	const tbody = document.createElement('tbody');
	for (const dispute of list) {
		const tr = document.createElement('tr');
		tr.className = 'admin-row admin-row--clickable';
		tr.tabIndex = 0;
		tr.dataset.disputeId = String(dispute.id);
		tr.appendChild(element('td', String(dispute.created_at || '')));
		tr.appendChild(element('td', String(dispute.competition || '')));
		tr.appendChild(element('td', `Round ${dispute.round_number}`));
		tr.appendChild(element('td', `${dispute.home_team} vs ${dispute.away_team}`));
		tr.appendChild(element('td', capitalise(dispute.discipline)));
		tr.appendChild(element('td', String(dispute.user_name || '')));

		// Delete lives inside the clickable row, so its click must not also open
		// the viewer (handled in onDisputesClick via the button's data-action).
		const actionsCell = element('td', null, 'row-actions');
		const deleteButton = element('button', 'Delete', 'secondary-button danger-button');
		deleteButton.type = 'button';
		deleteButton.dataset.action = 'delete-dispute';
		deleteButton.dataset.disputeId = String(dispute.id);
		deleteButton.setAttribute(
			'aria-label',
			`Delete dispute: ${dispute.home_team} vs ${dispute.away_team}, ` +
			`Round ${dispute.round_number} ${capitalise(dispute.discipline)}`
		);
		actionsCell.appendChild(deleteButton);
		tr.appendChild(actionsCell);

		tbody.appendChild(tr);
	}
	table.appendChild(tbody);
	el.disputesList.appendChild(table);
}

function onDisputesClick(event) {
	if (!(event.target instanceof Element)) {
		return;
	}
	// The Delete button sits inside the clickable row: act on it and stop, so
	// the row's open-viewer handler never sees the click.
	const button = event.target.closest('button[data-action="delete-dispute"]');
	if (button) {
		onDeleteDispute(Number(button.dataset.disputeId));
		return;
	}
	const tr = event.target.closest('tr[data-dispute-id]');
	if (tr) {
		openDisputeViewer(Number(tr.dataset.disputeId));
	}
}

function onDisputesKeydown(event) {
	if (event.key !== 'Enter' && event.key !== ' ') {
		return;
	}
	// A focused Delete button activates itself; do not also open the viewer.
	if (event.target instanceof Element && event.target.closest('button')) {
		return;
	}
	const tr = event.target instanceof Element ? event.target.closest('tr[data-dispute-id]') : null;
	if (!tr) {
		return;
	}
	event.preventDefault();
	openDisputeViewer(Number(tr.dataset.disputeId));
}

/**
 * Delete one dispute after confirmation: the row and its stored photo are
 * removed server-side (api.php?action=delete_dispute, admin only). On success
 * the local list is updated in place, so the table reflects the removal without
 * a full reload.
 */
async function onDeleteDispute(id) {
	const dispute = state.disputes.find((entry) => entry.id === id);
	if (!dispute || state.deleteInFlight) {
		return;
	}
	const label = `${dispute.home_team} vs ${dispute.away_team} \u00b7 ${capitalise(dispute.discipline)}`;
	if (!window.confirm(`Delete this dispute and its photo?\n\n${label}\n\nThis cannot be undone.`)) {
		return;
	}

	state.deleteInFlight = true;
	try {
		const res = await fetch(`${API}?action=delete_dispute`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ id }),
		});
		let data = null;
		try {
			data = await res.json();
		} catch (err) {
			data = null;
		}

		if (res.status === 401) {
			showNotice({
				variant: 'danger',
				text: 'Session expired \u2014 reload to sign in.',
				actions: [{ label: 'Reload', onClick: () => window.location.reload() }],
			});
			return;
		}
		if (!res.ok || !data || !data.success) {
			showNotice({
				variant: 'danger',
				text: `Delete failed: ${(data && data.error) || res.status}`,
			});
			return;
		}

		state.disputes = state.disputes.filter((entry) => entry.id !== id);
		// Guard the (modal-only, so normally unreachable) case where its viewer
		// is open: drop the stale viewer with the deleted dispute.
		if (state.disputeOpenId === id) {
			closeDisputeViewer();
		}
		renderDisputes();
		showNotice({ text: 'Dispute deleted.', autoClearMs: 3000 });
	} catch (err) {
		showNotice({ variant: 'danger', text: `Delete failed: ${err.message}` });
	} finally {
		state.deleteInFlight = false;
	}
}

// ---------------------------------------------------------------------------
// Dispute viewer
// ---------------------------------------------------------------------------

function openDisputeViewer(id) {
	const dispute = state.disputes.find((entry) => entry.id === id);
	if (!dispute) {
		return;
	}
	state.disputeOpenId = id;
	state.disputeGameId = dispute.game_id;

	el.disputeImage.src = API + '?action=dispute_image&id=' + encodeURIComponent(String(dispute.id));
	el.disputeImage.alt =
		`Scorecard for ${dispute.home_team} vs ${dispute.away_team}, ` +
		`Round ${dispute.round_number} ${capitalise(dispute.discipline)}`;
	el.disputeViewerTitle.textContent = `${dispute.competition} \u00b7 Round ${dispute.round_number}`;
	el.disputeViewerSummary.textContent =
		`${dispute.home_team} vs ${dispute.away_team} \u00b7 ${capitalise(dispute.discipline)} ` +
		`\u00b7 disputed by ${dispute.user_name || 'unknown'}`;

	renderViewerForm(dispute);

	if (typeof el.disputeViewer.showModal === 'function') {
		el.disputeViewer.showModal();
	} else {
		el.disputeViewer.setAttribute('open', '');
	}
	el.disputeCloseButton.focus();
}

/** True when the dispute's denormalised details still match the resolved game. */
function disputeMatchesGame(dispute, row) {
	return String(dispute.competition || '') === String(row.competition.competition || '')
		&& Number(dispute.round_number) === Number(row.round.round)
		&& String(dispute.home_team || '') === String(row.game.homeTeam || '')
		&& String(dispute.away_team || '') === String(row.game.awayTeam || '');
}

function renderViewerForm(dispute) {
	el.disputeViewerForm.textContent = '';
	state.disputeEntry = null;
	setViewerStatus('', '');

	const row = state.gamesById.get(dispute.game_id);
	if (!row) {
		el.disputeViewerForm.appendChild(
			element('p', 'This game is no longer in the draw \u2014 the score is not editable.', 'game-edit__note')
		);
		updateDisputeSaveState();
		return;
	}

	// After a re-seed, ids restart and a stored game_id can point at a different
	// game. Only trust it when the denormalised details still agree.
	if (!disputeMatchesGame(dispute, row)) {
		el.disputeViewerForm.appendChild(
			element(
				'p',
				'This dispute no longer matches the draw for its game id (the draw may have been re-seeded) \u2014 the score is not editable.',
				'game-edit__note'
			)
		);
		updateDisputeSaveState();
		return;
	}

	const scores = Array.isArray(row.game.scores) ? row.game.scores : [];
	const index = scores.findIndex(
		(entry) => String(entry.discipline || '').toLowerCase() === String(dispute.discipline || '').toLowerCase()
	);
	if (index < 0) {
		el.disputeViewerForm.appendChild(
			element('p', `This game has no ${capitalise(dispute.discipline)} score entry to edit.`, 'game-edit__note')
		);
		updateDisputeSaveState();
		return;
	}

	state.disputeEntry = { index, name: String(scores[index].discipline || dispute.discipline).toLowerCase() };
	el.disputeViewerForm.appendChild(element('p', 'Only the disputed discipline can be edited here.', 'game-edit__note'));
	el.disputeViewerForm.appendChild(buildDisciplineBlock(state.disputeEntry, row.game, scores[index]));
	updateDisputeSaveState();
}

function updateDisputeSaveState() {
	const row = state.disputeGameId === null ? null : state.gamesById.get(state.disputeGameId);
	if (!row || !state.disputeEntry) {
		el.disputeSaveButton.disabled = true;
		return;
	}
	const { ok } = validateEntries(el.disputeViewerForm, [state.disputeEntry], row.competition, 'viewErr-', 'require-all');
	el.disputeSaveButton.disabled = !ok || state.saveInFlight;
}

async function onSaveDispute() {
	if (state.saveInFlight || state.disputeGameId === null || !state.disputeEntry) {
		return;
	}
	const row = state.gamesById.get(state.disputeGameId);
	if (!row) {
		return;
	}
	const entry = state.disputeEntry;
	const { ok, values } = validateEntries(el.disputeViewerForm, [entry], row.competition, 'viewErr-', 'require-all');
	if (!ok) {
		return;
	}
	const result = values.get(entry.index);

	// Preserve every other discipline and the players; replace just this score.
	const game = deepCopy(row.game);
	const scores = Array.isArray(game.scores) ? game.scores : [];
	game.scores = scores.map((score, index) => ({
		position: index,
		discipline: score ? score.discipline : entry.name,
		homeScore: score ? score.homeScore : null,
		awayScore: score ? score.awayScore : null,
		homeEnds: score ? score.homeEnds : null,
		awayEnds: score ? score.awayEnds : null,
	}));
	if (entry.index < 0 || entry.index >= game.scores.length) {
		return;
	}
	game.scores[entry.index] = {
		position: entry.index,
		discipline: game.scores[entry.index].discipline || entry.name,
		homeScore: result.homeScore,
		awayScore: result.awayScore,
		homeEnds: result.homeEnds,
		awayEnds: result.awayEnds,
	};

	setSaveBusy(true);
	try {
		const { status: code, ok: httpOk, data } = await postGameUpdate(row.game.id, row.game.revision, game, null);
		if (code === 409) {
			setViewerStatus('Someone else saved this fixture \u2014 reload to see their result.', 'error');
			showNotice({
				variant: 'danger',
				text: 'Someone else saved this fixture. Reload to see their result.',
				actions: [{ label: 'Reload', onClick: () => resync() }],
			});
			return;
		}
		if (!httpOk || !data || !data.success) {
			setViewerStatus(`Save failed: ${(data && data.error) || code}`, 'error');
			return;
		}

		game.revision = Number.isInteger(data.revision) ? data.revision : row.game.revision + 1;
		state.draw[row.compIdx].rounds[row.roundIdx].games[row.gameIdx] = game;
		rebuildGamesById();
		renderGames();
		renderCounts();

		// Re-render the form against the adopted revision, then confirm.
		const dispute = state.disputes.find((d) => d.id === state.disputeOpenId);
		if (dispute) {
			renderViewerForm(dispute);
		}
		setViewerStatus('Saved.', 'ok');
	} catch (err) {
		setViewerStatus(`Save failed: ${err.message}`, 'error');
	} finally {
		setSaveBusy(false);
	}
}

function closeDisputeViewer() {
	if (typeof el.disputeViewer.close === 'function') {
		el.disputeViewer.close();
	} else {
		el.disputeViewer.removeAttribute('open');
	}
}

function onDisputeViewerClosed() {
	state.disputeOpenId = null;
	state.disputeGameId = null;
	state.disputeEntry = null;
	el.disputeViewerForm.textContent = '';
	setViewerStatus('', '');
	el.disputeImage.removeAttribute('src');
}

function setViewerStatus(text, variant) {
	el.disputeViewerStatus.textContent = text;
	el.disputeViewerStatus.className = 'save-status' + (variant ? ` save-status--${variant}` : '');
}

// ---------------------------------------------------------------------------
// Counts + flag
// ---------------------------------------------------------------------------

/** Distinct people in one game, de-duped case-insensitively; once per game. */
function gamePeople(game) {
	const people = new Map(); // lowercase -> first spelling seen
	for (const side of ['homePlayers', 'awayPlayers']) {
		const names = Array.isArray(game[side]) ? game[side] : [];
		for (const raw of names) {
			const name = String(raw == null ? '' : raw).trim();
			if (name && !people.has(name.toLowerCase())) {
				people.set(name.toLowerCase(), name);
			}
		}
	}
	return people;
}

function computeCounts(draw) {
	const competitionNames = [];
	const counts = new Map(); // lowercase -> { display, counts: { comp -> n } }

	for (const competition of draw) {
		const name = String(competition.competition || '');
		if (name && !competitionNames.includes(name)) {
			competitionNames.push(name);
		}
	}

	for (const competition of draw) {
		const name = String(competition.competition || '');
		for (const round of competition.rounds || []) {
			for (const game of round.games || []) {
				for (const [lower, display] of gamePeople(game)) {
					let record = counts.get(lower);
					if (!record) {
						record = { display, counts: {} };
						counts.set(lower, record);
					}
					record.counts[name] = (record.counts[name] || 0) + 1;
				}
			}
		}
	}

	return { competitionNames, counts };
}

/**
 * Hard-coded flag rule: anyone with 3+ Rooster games and then a Chatterton game
 * on a STRICTLY later date. All Rooster and Chatterton rounds run on the same
 * dates (simultaneously), so "then" cannot mean "same day": a same-day
 * Rooster+Chatterton appearance would be a data-entry error, and this rule (the
 * 3-game rule) does not flag it. Dates are the round `date` strings
 * (YYYY-MM-DD), so a lexical compare is valid; a round with no date is unknown
 * and can neither form nor satisfy the threshold. Each game counts once per
 * person, even if the name is listed on both sides.
 */
function computeFlags(draw) {
	const people = new Map(); // lowercase -> { display, roosterDates: [], chatterton: [{date, round}] }

	const ensure = (lower, display) => {
		let record = people.get(lower);
		if (!record) {
			record = { display, roosterDates: [], chatterton: [] };
			people.set(lower, record);
		}
		return record;
	};

	for (const competition of draw) {
		const name = String(competition.competition || '').trim().toLowerCase();
		const isRooster = name === ROOSTER;
		const isChatterton = name === CHATTERTON;
		if (!isRooster && !isChatterton) {
			continue;
		}
		for (const round of competition.rounds || []) {
			const date = typeof round.date === 'string' ? round.date : '';
			const roundNumber = Number.isFinite(Number(round.round)) ? Number(round.round) : null;
			for (const game of round.games || []) {
				for (const [lower, display] of gamePeople(game)) {
					const record = ensure(lower, display);
					if (isRooster) {
						record.roosterDates.push(date);
					} else {
						record.chatterton.push({ date, round: roundNumber });
					}
				}
			}
		}
	}

	const flags = new Map();
	for (const [lower, record] of people) {
		const validDates = record.roosterDates.filter((date) => date).sort();
		if (validDates.length < ROOSTER_MIN) {
			continue;
		}
		const threshold = validDates[ROOSTER_MIN - 1];
		const offending = record.chatterton.filter((entry) => entry.date && entry.date > threshold);
		if (!offending.length) {
			continue;
		}
		const rounds = offending.map((entry) => entry.round).filter((round) => round !== null);
		flags.set(lower, { reason: flagReason(rounds) });
	}
	return flags;
}

function flagReason(rounds) {
	const suffix = rounds.length ? ` ${rounds.map((round) => `R${round}`).join(', ')}` : '';
	return `3 Rooster games then Chatterton${suffix}`;
}

function renderCounts() {
	const draw = Array.isArray(state.draw) ? state.draw : [];
	const { competitionNames, counts } = computeCounts(draw);
	const flags = computeFlags(draw);

	el.countsBody.textContent = '';
	if (!counts.size) {
		el.countsBody.appendChild(element('p', 'No player appearances recorded.', 'empty-state'));
		return;
	}

	const table = element('table', null, 'admin-table counts-table');
	const thead = document.createElement('thead');
	const headRow = document.createElement('tr');
	headRow.appendChild(element('th', 'Person'));
	for (const name of competitionNames) {
		headRow.appendChild(element('th', name));
	}
	headRow.appendChild(element('th', 'Total'));
	headRow.appendChild(element('th', 'Flag'));
	thead.appendChild(headRow);
	table.appendChild(thead);

	const tbody = document.createElement('tbody');
	const people = [...counts.entries()].sort((a, b) => byText(a[1].display, b[1].display));
	for (const [lower, record] of people) {
		const tr = document.createElement('tr');
		tr.className = 'admin-row';
		const flag = flags.get(lower);
		if (flag) {
			tr.classList.add('admin-row--flagged');
		}

		tr.appendChild(element('td', record.display));
		let total = 0;
		for (const name of competitionNames) {
			const value = record.counts[name] || 0;
			total += value;
			tr.appendChild(element('td', value ? String(value) : ''));
		}
		tr.appendChild(element('td', String(total), 'total-cell'));

		const flagCell = element('td', null, 'flag-cell');
		if (flag) {
			flagCell.appendChild(element('span', flag.reason, 'flag-badge'));
		}
		tr.appendChild(flagCell);

		tbody.appendChild(tr);
	}
	table.appendChild(tbody);
	el.countsBody.appendChild(table);
}

// ---------------------------------------------------------------------------
// Notices
// ---------------------------------------------------------------------------

function showNotice({ text, variant = '', actions = [], autoClearMs = 0 }) {
	clearNotice();

	noticeEl = element('div', null, 'notice' + (variant === 'danger' ? ' notice--danger' : ''));
	noticeEl.appendChild(element('div', text, 'notice__text'));

	if (actions.length) {
		const actionsEl = element('div', null, 'notice__actions');
		for (const action of actions) {
			const button = element('button', action.label, 'secondary-button');
			button.type = 'button';
			button.addEventListener('click', action.onClick);
			actionsEl.appendChild(button);
		}
		noticeEl.appendChild(actionsEl);
	}

	el.banners.appendChild(noticeEl);

	if (autoClearMs > 0) {
		// Capture this specific node so a later notice replacing it is not
		// cleared early by this timer.
		const created = noticeEl;
		window.setTimeout(() => {
			if (created.parentNode === el.banners) {
				created.remove();
				if (noticeEl === created) {
					noticeEl = null;
				}
			}
		}, autoClearMs);
	}
}

function clearNotice() {
	if (noticeEl) {
		noticeEl.remove();
		noticeEl = null;
	}
}

function setStatusEl(node, text, variant) {
	if (!node) {
		return;
	}
	node.textContent = text;
	node.className = 'save-status' + (variant ? ` save-status--${variant}` : '');
}
