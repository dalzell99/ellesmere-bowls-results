'use strict';

/*
 * Rooster Cup Score Sheet — client logic.
 *
 * Flow: load draw.json from api.php -> pick competition, round, fixture -> render
 * the sheet -> validate -> save the one game back through api.php. Unsaved inputs
 * are autosaved to localStorage as a per-fixture "draft" so a reload (including one
 * forced by a save conflict) never loses progress.
 */

const API = 'api.php';
const KNOWN_DISCIPLINES = ['singles', 'pairs', 'triples', 'fours'];
const DISCIPLINE_CLASS = {
	singles: 'disc--singles',
	pairs: 'disc--pairs',
	triples: 'disc--triples',
	fours: 'disc--fours',
};
const NAME_KEY = 'rooster.scorerName';
const DRAFT_PREFIX = 'rooster.draft.';
const COMPETITION_PARAM = 'competition'; // ?competition=<index or name> preselects a draw
const DRAFT_TTL_MS = 7 * 24 * 60 * 60 * 1000; // keep drafts for ~7 days
const AUTOSAVE_MS = 500;

const state = {
	draw: null,
	version: null,
	competitionIndex: -1,
	roundIndex: -1,
	gameIndex: -1,
	entries: [],        // filtered discipline entries, in original array order
	numPlayers: 0,
	loadedGame: null,   // baseline snapshot used for the same-fixture conflict check
	saveInFlight: false,
	forceReload: false, // set after a same-fixture conflict; blocks re-saving
};

let autosaveTimer = null;
let statusTimer = null;
let noticeEl = null;

const el = {
	scorerNameDisplay: document.getElementById('scorerNameDisplay'),
	changeScorerButton: document.getElementById('changeScorerButton'),
	competitionSelect: document.getElementById('competitionSelect'),
	roundSelect: document.getElementById('roundSelect'),
	fixtureSelect: document.getElementById('fixtureSelect'),
	banners: document.getElementById('banners'),
	sheet: document.getElementById('sheet'),
	sheetTitle: document.getElementById('sheetTitle'),
	sheetRound: document.getElementById('sheetRound'),
	sheetBody: document.getElementById('sheetBody'),
	saveButton: document.getElementById('saveButton'),
	saveStatus: document.getElementById('saveStatus'),
	emptyState: document.getElementById('emptyState'),
	nameDialog: document.getElementById('nameDialog'),
	nameForm: document.getElementById('nameForm'),
	nameInput: document.getElementById('nameInput'),
};

// ---------------------------------------------------------------------------
// Small helpers
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

function normalisePlayerCount(raw) {
	const value = Number(raw);
	if (Number.isInteger(value) && value > 0) {
		return Math.min(value, 64);
	}
	return 4; // fallback so the sheet still renders
}

function formatTimestamp(ms) {
	try {
		return new Date(ms).toLocaleString();
	} catch (err) {
		return 'earlier';
	}
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

/** Canonical (key-sorted) form, so conflict comparisons ignore key ordering. */
function canonical(value) {
	if (Array.isArray(value)) {
		return value.map(canonical);
	}
	if (value && typeof value === 'object') {
		return Object.keys(value)
			.sort()
			.reduce((acc, key) => {
				acc[key] = canonical(value[key]);
				return acc;
			}, {});
	}
	return value;
}

function sameGame(a, b) {
	return JSON.stringify(canonical(a)) === JSON.stringify(canonical(b));
}

/** Safely read draw[ci].rounds[ri].games[gi] without throwing on a bad shape. */
function gameAt(draw, ci, ri, gi) {
	try {
		return draw[ci].rounds[ri].games[gi];
	} catch (err) {
		return null;
	}
}

/** Build a <colgroup> from an array of CSS widths. */
function buildColgroup(widths) {
	const colgroup = document.createElement('colgroup');
	for (const width of widths) {
		const col = document.createElement('col');
		col.style.width = width;
		colgroup.appendChild(col);
	}
	return colgroup;
}

// ---------------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------------

bindStaticEvents();
purgeExpiredDrafts();
applyScorerNameToHeader();

if (!getScorerName()) {
	openNameDialog();
}

loadDraw();

function bindStaticEvents() {
	el.changeScorerButton.addEventListener('click', openNameDialog);

	el.nameForm.addEventListener('submit', (event) => {
		const name = el.nameInput.value.trim();
		if (!name) {
			event.preventDefault();
			return;
		}
		localStorage.setItem(NAME_KEY, name);
		applyScorerNameToHeader();
	});

	el.competitionSelect.addEventListener('change', onCompetitionChange);
	el.roundSelect.addEventListener('change', onRoundChange);
	el.fixtureSelect.addEventListener('change', onFixtureChange);
	el.saveButton.addEventListener('click', onSave);

	// Any edit in the sheet re-validates and (debounced) autosaves a draft.
	el.sheetBody.addEventListener('input', onSheetInput);
	el.sheetBody.addEventListener('change', onSheetInput);

	// Warn before reloading/closing with unsaved changes rather than silently
	// writing a draft (which would resurrect a discarded draft on every reload).
	window.addEventListener('beforeunload', onBeforeUnload);
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

function applyScorerNameToHeader() {
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
// Load draw.json
// ---------------------------------------------------------------------------

async function loadDraw() {
	try {
		const res = await fetch(`${API}?action=load`, { headers: { Accept: 'application/json' } });
		const data = await res.json();
		if (!res.ok || !data.success) {
			throw new Error(data.error || `HTTP ${res.status}`);
		}
		state.draw = data.draw;
		state.version = data.version;
		populateCompetitionSelect();
		applyCompetitionFromUrl();
	} catch (err) {
		showNotice({
			variant: 'danger',
			text: `Could not load draw.json: ${err.message}`,
			actions: [{ label: 'Retry', onClick: () => location.reload() }],
		});
	}
}

function populateCompetitionSelect() {
	const competitions = (Array.isArray(state.draw) ? state.draw : [])
		.map((competition, index) => ({ index, name: String(competition.competition || '') }))
		.sort((a, b) => byText(a.name, b.name));

	resetSelector(el.competitionSelect, 'Select\u2026', false);
	for (const competition of competitions) {
		el.competitionSelect.appendChild(option(String(competition.index), competition.name));
	}
}

function resetSelector(select, placeholder, disabled) {
	select.textContent = '';
	select.appendChild(option('', placeholder));
	select.value = '';
	select.disabled = disabled;
}

/**
 * Preselect the competition named (or numbered) by the ?competition= URL param.
 * Accepts either an option value (the draw index) or a case-insensitive name.
 * Unknown values are ignored, leaving the dropdown on its placeholder.
 */
function applyCompetitionFromUrl() {
	const wanted = new URLSearchParams(location.search).get(COMPETITION_PARAM);
	if (!wanted) {
		return;
	}
	const match = findCompetitionOption(wanted.trim());
	if (match === null) {
		return;
	}
	el.competitionSelect.value = match;
	onCompetitionChange();
}

/** Resolve a URL value to a competition <option> value (index), or null. */
function findCompetitionOption(target) {
	const lower = target.toLowerCase();
	let byName = null;
	for (const opt of el.competitionSelect.options) {
		if (!opt.value) {
			continue; // placeholder
		}
		if (opt.value === target) {
			return opt.value;
		}
		if (byName === null && opt.textContent.trim().toLowerCase() === lower) {
			byName = opt.value;
		}
	}
	return byName;
}

// ---------------------------------------------------------------------------
// Cascading selectors
// ---------------------------------------------------------------------------

function onCompetitionChange() {
	flushDraftForCurrentSheet();

	state.competitionIndex = parseInt(el.competitionSelect.value, 10);
	state.roundIndex = -1;
	state.gameIndex = -1;

	resetSelector(el.roundSelect, 'Select\u2026', true);
	resetSelector(el.fixtureSelect, 'Select\u2026', true);
	hideSheet();

	if (!Number.isInteger(state.competitionIndex) || state.competitionIndex < 0) {
		return;
	}

	const competition = state.draw[state.competitionIndex];
	const rounds = (competition.rounds || [])
		.map((round, index) => ({ index, number: Number(round.round) }))
		.sort((a, b) => a.number - b.number);

	for (const round of rounds) {
		const label = Number.isFinite(round.number) ? `Round ${round.number}` : `Round ${round.index + 1}`;
		el.roundSelect.appendChild(option(String(round.index), label));
	}
	el.roundSelect.disabled = rounds.length === 0;
}

function onRoundChange() {
	flushDraftForCurrentSheet();

	state.roundIndex = parseInt(el.roundSelect.value, 10);
	state.gameIndex = -1;

	resetSelector(el.fixtureSelect, 'Select\u2026', true);
	hideSheet();

	if (!Number.isInteger(state.roundIndex) || state.roundIndex < 0) {
		return;
	}

	const competition = state.draw[state.competitionIndex];
	const round = competition.rounds[state.roundIndex];
	const games = (round.games || []).map((game, index) => ({
		index,
		label: `${game.homeTeam || '?'} vs ${game.awayTeam || '?'}`,
	}));
	games.sort((a, b) => byText(a.label, b.label));

	for (const game of games) {
		el.fixtureSelect.appendChild(option(String(game.index), game.label));
	}
	el.fixtureSelect.disabled = games.length === 0;
}

function onFixtureChange() {
	flushDraftForCurrentSheet();

	state.gameIndex = parseInt(el.fixtureSelect.value, 10);
	if (!Number.isInteger(state.gameIndex) || state.gameIndex < 0) {
		hideSheet();
		return;
	}
	renderSheet();
}

function hideSheet() {
	el.sheet.hidden = true;
	el.emptyState.hidden = false;
	el.sheetBody.textContent = '';
	state.entries = [];
	state.numPlayers = 0;
	state.loadedGame = null;
	state.forceReload = false;
	clearAutosave();
	clearNotice();
	setStatus('', '');
}

// ---------------------------------------------------------------------------
// Render the sheet
// ---------------------------------------------------------------------------

function renderSheet() {
	const { competitionIndex: ci, roundIndex: ri, gameIndex: gi } = state;
	const competition = state.draw[ci];
	const round = competition.rounds[ri];
	const game = round.games[gi];

	// Build the discipline entry list, skipping unknown names.
	const declared = Array.isArray(competition.discipline) ? competition.discipline : [];
	state.entries = declared
		.map((name, index) => ({ name: String(name).toLowerCase(), index }))
		.filter((entry) => KNOWN_DISCIPLINES.includes(entry.name));
	state.numPlayers = normalisePlayerCount(competition.numPlayers);

	state.forceReload = false;

	el.sheetTitle.textContent = `${competition.competition} Score Sheet`;
	el.sheetRound.textContent = String(Number.isFinite(Number(round.round)) ? round.round : ri + 1);

	el.sheetBody.textContent = '';
	el.sheetBody.appendChild(buildDisciplineTable(game));
	el.sheetBody.appendChild(buildPlayerTable(game));

	// Baseline captured for the conflict check.
	state.loadedGame = deepCopy(game);

	refreshDatalists(game.homeTeam, game.awayTeam);

	el.sheet.hidden = false;
	el.emptyState.hidden = true;
	setStatus('', '');

	clearNotice();
	showDraftBannerIfAny();
	updateSaveState();
}

function buildDisciplineTable(game) {
	const table = element('table', null, 'sheet-table');
	table.appendChild(buildColgroup(['22%', '16%', '27%', '8%', '27%']));

	const thead = document.createElement('thead');
	const headRow = document.createElement('tr');
	headRow.appendChild(element('th', '', 'team-head team-head--blank'));
	headRow.appendChild(element('th', '', 'metrics-header team-head--blank'));
	headRow.appendChild(element('th', String(game.homeTeam || ''), 'team-head'));
	headRow.appendChild(element('th', '', 'team-head team-head--blank'));
	headRow.appendChild(element('th', String(game.awayTeam || ''), 'team-head'));
	thead.appendChild(headRow);
	table.appendChild(thead);

	const tbody = document.createElement('tbody');

	// Group entries by discipline, preserving first-appearance order.
	const groups = [];
	const byName = new Map();
	for (const entry of state.entries) {
		if (!byName.has(entry.name)) {
			const group = { name: entry.name, items: [] };
			byName.set(entry.name, group);
			groups.push(group);
		}
		byName.get(entry.name).items.push(entry);
	}

	const savedScores = Array.isArray(game.scores) ? game.scores : [];

	for (const group of groups) {
		const rowspan = group.items.length *  2;

		group.items.forEach((item, position) => {
			const saved = savedScores[item.index] || {};

			const scoreRow = document.createElement('tr');
			if (position === 0) {
				const discClass = DISCIPLINE_CLASS[group.name] || 'disc--unknown';
				const discCell = element('th', capitalise(group.name), `disc ${discClass}`);
				discCell.rowSpan = rowspan;
				scoreRow.appendChild(discCell);
			}
			scoreRow.appendChild(element('td', 'Score', 'metric'));
			scoreRow.appendChild(numberCell(item.index, 'homeScore', saved.homeScore));
			scoreRow.appendChild(dividerCell());
			scoreRow.appendChild(numberCell(item.index, 'awayScore', saved.awayScore));
			tbody.appendChild(scoreRow);

			const endsRow = document.createElement('tr');
			endsRow.appendChild(element('td', 'Ends', 'metric'));
			endsRow.appendChild(numberCell(item.index, 'homeEnds', saved.homeEnds));
			endsRow.appendChild(dividerCell());
			endsRow.appendChild(numberCell(item.index, 'awayEnds', saved.awayEnds));
			tbody.appendChild(endsRow);
		});
	}

	table.appendChild(tbody);
	return table;
}

function numberCell(disciplineIndex, field, value) {
	const td = element('td', null, 'cell');
	const input = document.createElement('input');
	input.type = 'number';
	input.min = '0';
	input.step = '1';
	input.setAttribute('inputmode', 'numeric');
	input.dataset.discIdx = String(disciplineIndex);
	input.dataset.field = field;
	if (value !== null && value !== undefined && value !== '') {
		input.value = String(value);
	}
	td.appendChild(input);
	return td;
}

function dividerCell() {
	return element('td', null, 'divider');
}

function buildPlayerTable(game) {
	const table = element('table', null, 'player-table');
	table.appendChild(buildColgroup(['22%', '39%', '39%']));

	const thead = document.createElement('thead');
	const headRow = document.createElement('tr');
	headRow.appendChild(element('th', '', 'team-head--blank'));
	headRow.appendChild(element('th', String(game.homeTeam || ''), 'team-head'));
	headRow.appendChild(element('th', String(game.awayTeam || ''), 'team-head'));
	thead.appendChild(headRow);
	table.appendChild(thead);

	const tbody = document.createElement('tbody');
	for (let i = 0; i < state.numPlayers; i++) {
		const row = document.createElement('tr');
		row.appendChild(element('th', `Player ${i + 1}`, 'row-label'));
		row.appendChild(playerCell('home', i, (game.homePlayers || [])[i]));
		row.appendChild(playerCell('away', i, (game.awayPlayers || [])[i]));
		tbody.appendChild(row);
	}
	table.appendChild(tbody);
	return table;
}

function playerCell(side, index, value) {
	const td = element('td', null, 'cell');
	const input = document.createElement('input');
	input.type = 'text';
	input.maxLength = 60;
	input.autocomplete = 'off';
	input.setAttribute('list', side === 'home' ? 'players-home' : 'players-away');
	input.dataset.playerSide = side;
	input.dataset.playerIndex = String(index);
	if (value != null && value !== '') {
		input.value = String(value);
	}
	td.appendChild(input);
	return td;
}

function findInput(disciplineIndex, field) {
	return el.sheetBody.querySelector(
		`input[data-disc-idx="${disciplineIndex}"][data-field="${field}"]`
	);
}

function findPlayerInput(side, index) {
	return el.sheetBody.querySelector(
		`input[data-player-side="${side}"][data-player-index="${index}"]`
	);
}

// ---------------------------------------------------------------------------
// Player autocomplete options
// ---------------------------------------------------------------------------

function refreshDatalists(homeTeam, awayTeam) {
	setDatalist('players-home', collectTeamPlayers(homeTeam));
	setDatalist('players-away', collectTeamPlayers(awayTeam));
}

function setDatalist(id, names) {
	let list = document.getElementById(id);
	if (!list) {
		list = document.createElement('datalist');
		list.id = id;
		document.body.appendChild(list);
	}
	list.textContent = '';
	for (const name of names) {
		list.appendChild(option(name, name));
	}
}

/** Names ever saved for a team, across the whole draw, de-duped and sorted. */
function collectTeamPlayers(team) {
	if (!team || !Array.isArray(state.draw)) {
		return [];
	}
	const seen = new Map(); // lowercase -> first spelling seen
	for (const competition of state.draw) {
		for (const round of competition.rounds || []) {
			for (const game of round.games || []) {
				let names = null;
				if (game.homeTeam === team) {
					names = game.homePlayers;
				} else if (game.awayTeam === team) {
					names = game.awayPlayers;
				}
				if (!Array.isArray(names)) {
					continue;
				}
				for (const raw of names) {
					const name = String(raw == null ? '' : raw).trim();
					if (name && !seen.has(name.toLowerCase())) {
						seen.set(name.toLowerCase(), name);
					}
				}
			}
		}
	}
	return [...seen.values()].sort(byText);
}

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

function validateSheet() {
	let ok = true;

	const scores = state.entries.map((entry) => {
		const result = { discipline: entry.name };

		for (const field of ['homeScore', 'awayScore', 'homeEnds', 'awayEnds']) {
			const input = findInput(entry.index, field);
			const value = input ? parseWholeNumber(input.value) : null;
			result[field] = value;
			if (value === null) {
				ok = false;
			}
		}
		return result;
	});

	const homePlayers = [];
	const awayPlayers = [];
	for (let i = 0; i < state.numPlayers; i++) {
		const homeInput = findPlayerInput('home', i);
		const awayInput = findPlayerInput('away', i);
		const homeName = homeInput ? homeInput.value.trim() : '';
		const awayName = awayInput ? awayInput.value.trim() : '';
		if (!homeName || !awayName) {
			ok = false;
		}
		homePlayers.push(homeName);
		awayPlayers.push(awayName);
	}

	return { ok, scores, homePlayers, awayPlayers };
}

function updateSaveState() {
	const { ok } = validateSheet();
	el.saveButton.disabled = !ok || state.saveInFlight || state.forceReload;
}

function onSheetInput() {
	scheduleAutosave();
	updateSaveState();
}

/** True when the current sheet holds edits that differ from the last saved game. */
function hasUnsavedChanges() {
	if (el.sheet.hidden || !state.entries.length || !state.loadedGame || state.forceReload) {
		return false;
	}
	const { scores, homePlayers, awayPlayers } = validateSheet();
	const current = Object.assign({}, state.loadedGame, { scores, homePlayers, awayPlayers });
	return !sameGame(current, state.loadedGame);
}

/** Ask the browser to confirm before a reload/close that would drop unsaved edits. */
function onBeforeUnload(event) {
	if (!hasUnsavedChanges()) {
		return;
	}
	event.preventDefault();
	event.returnValue = '';
}

function setStatus(text, variant) {
	clearTimeout(statusTimer);
	statusTimer = null;
	el.saveStatus.textContent = text;
	el.saveStatus.className = 'save-status' + (variant ? ` save-status--${variant}` : '');
	// The success confirmation is transient: hide it shortly after it appears.
	if (text === 'Saved') {
		statusTimer = setTimeout(() => {
			el.saveStatus.textContent = '';
			el.saveStatus.className = 'save-status';
			statusTimer = null;
		}, 5000);
	}
}

// ---------------------------------------------------------------------------
// Save
// ---------------------------------------------------------------------------

async function onSave() {
	if (state.saveInFlight || state.forceReload) {
		return;
	}
	const { ok, scores, homePlayers, awayPlayers } = validateSheet();
	if (!ok) {
		return;
	}

	clearAutosave();

	// Preserve team names and any other keys from the loaded game.
	const game = deepCopy(state.loadedGame || {});
	game.homePlayers = homePlayers;
	game.awayPlayers = awayPlayers;
	game.scores = scores;

	state.saveInFlight = true;
	updateSaveState();
	setStatus('Saving\u2026', '');

	try {
		const res = await fetch(`${API}?action=save`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				version: state.version,
				userName: getScorerName(),
				competitionIndex: state.competitionIndex,
				roundIndex: state.roundIndex,
				gameIndex: state.gameIndex,
				game,
			}),
		});

		const data = await res.json();

		if (res.status === 409) {
			handleConflict(data);
			return;
		}
		if (!res.ok || !data.success) {
			setStatus(`Save failed: ${data.error || res.status}`, 'error');
			return;
		}

		// Success: adopt the new version, update the in-memory copy, drop the draft.
		state.version = data.version;
		state.draw[state.competitionIndex].rounds[state.roundIndex].games[state.gameIndex] = game;
		state.loadedGame = deepCopy(game);
		removeDraft(draftKey());
		refreshDatalists(game.homeTeam, game.awayTeam);
		clearNotice();
		setStatus('Saved', 'ok');
	} catch (err) {
		setStatus(`Save failed: ${err.message}`, 'error');
	} finally {
		state.saveInFlight = false;
		updateSaveState();
	}
}

function handleConflict(data) {
	const { competitionIndex: ci, roundIndex: ri, gameIndex: gi } = state;
	const serverGame = gameAt(data.draw || [], ci, ri, gi);

	// Make sure the user's current entries are stored before anything else.
	writeDraft();

	if (serverGame && !sameGame(serverGame, state.loadedGame)) {
		// Same fixture was changed by someone else: their result wins.
		removeDraft(draftKey());
		state.forceReload = true;
		setStatus('Someone else saved this fixture.', 'error');
		showNotice({
			variant: 'danger',
			text: 'Someone else saved this fixture. Reload to see their result.',
			actions: [{ label: 'Reload', onClick: resync }],
		});
		updateSaveState();
		return;
	}

	// A different fixture changed: keep the user's entries and let them re-save.
	state.draw = data.draw;
	state.version = data.version;
	setStatus('Your entries were kept.', '');
	showNotice({
		text: 'Someone else saved another fixture. Your unsaved entries have been kept \u2014 you can save again.',
		actions: [
			{ label: 'Reload latest', onClick: resync },
			{ label: 'Discard mine', onClick: () => { removeDraft(draftKey()); resync(); } },
		],
	});
}

/** Re-fetch draw.json and re-render the current fixture with the latest values. */
async function resync() {
	try {
		const res = await fetch(`${API}?action=load`, { headers: { Accept: 'application/json' } });
		const data = await res.json();
		if (!res.ok || !data.success) {
			throw new Error(data.error || `HTTP ${res.status}`);
		}
		state.draw = data.draw;
		state.version = data.version;
		clearNotice();

		if (state.competitionIndex >= 0 && state.roundIndex >= 0 && state.gameIndex >= 0) {
			renderSheet();
		}
	} catch (err) {
		setStatus(`Reload failed: ${err.message}`, 'error');
	}
}

// ---------------------------------------------------------------------------
// Drafts (per-fixture unsaved input, kept in localStorage)
// ---------------------------------------------------------------------------

function draftKey() {
	const { competitionIndex: ci, roundIndex: ri, gameIndex: gi } = state;
	if (ci < 0 || ri < 0 || gi < 0 || !state.draw) {
		return null;
	}
	const competition = state.draw[ci];
	const round = competition.rounds[ri];
	const game = round.games[gi];
	return DRAFT_PREFIX + [competition.competition, round.round, game.homeTeam, game.awayTeam].join('|');
}

function scheduleAutosave() {
	clearAutosave();
	autosaveTimer = window.setTimeout(writeDraft, AUTOSAVE_MS);
}

function clearAutosave() {
	if (autosaveTimer) {
		window.clearTimeout(autosaveTimer);
		autosaveTimer = null;
	}
}

function writeDraft() {
	const key = draftKey();
	if (!key) {
		return;
	}
	const { scores, homePlayers, awayPlayers } = validateSheet();
	const draft = {
		savedAt: Date.now(),
		baseVersion: state.version,
		scores,
		homePlayers,
		awayPlayers,
	};
	try {
		localStorage.setItem(key, JSON.stringify(draft));
	} catch (err) {
		/* storage unavailable or full — drafts are best-effort */
	}
}

function flushDraftForCurrentSheet() {
	if (el.sheet.hidden || !state.entries.length || !hasUnsavedChanges()) {
		return;
	}
	writeDraft();
}

function loadDraft(key) {
	try {
		const raw = localStorage.getItem(key);
		if (!raw) {
			return null;
		}
		const draft = JSON.parse(raw);
		if (!draft || typeof draft.savedAt !== 'number') {
			return null;
		}
		if (Date.now() - draft.savedAt > DRAFT_TTL_MS) {
			localStorage.removeItem(key);
			return null;
		}
		return draft;
	} catch (err) {
		return null;
	}
}

function removeDraft(key) {
	if (!key) {
		return;
	}
	try {
		localStorage.removeItem(key);
	} catch (err) {
		/* ignore */
	}
}

function purgeExpiredDrafts() {
	try {
		const now = Date.now();
		for (let i = localStorage.length - 1; i >= 0; i--) {
			const key = localStorage.key(i);
			if (!key || !key.startsWith(DRAFT_PREFIX)) {
				continue;
			}
			try {
				const draft = JSON.parse(localStorage.getItem(key));
				if (!draft || typeof draft.savedAt !== 'number' || now - draft.savedAt > DRAFT_TTL_MS) {
					localStorage.removeItem(key);
				}
			} catch (err) {
				localStorage.removeItem(key);
			}
		}
	} catch (err) {
		/* ignore */
	}
}

function showDraftBannerIfAny() {
	const key = draftKey();
	if (!key) {
		return;
	}
	const draft = loadDraft(key);
	if (!draft) {
		return;
	}
	showNotice({
		text: `Unsaved changes from ${formatTimestamp(draft.savedAt)} were kept.`,
		actions: [
			{
				label: 'Restore my changes',
				onClick: () => {
					applyDraft(draft);
					clearNotice();
				},
			},
			{
				label: 'Discard',
				onClick: () => {
					removeDraft(key);
					clearNotice();
				},
			},
		],
	});
}

function applyDraft(draft) {
	if (Array.isArray(draft.scores)) {
		draft.scores.forEach((entry, position) => {
			const target = state.entries[position];
			if (!target || !entry) {
				return;
			}
			for (const field of ['homeScore', 'awayScore', 'homeEnds', 'awayEnds']) {
				const input = findInput(target.index, field);
				if (!input) {
					continue;
				}
				const value = entry[field];
				input.value = (value === null || value === undefined) ? '' : String(value);
			}
		});
	}
	setPlayerInputs('home', draft.homePlayers);
	setPlayerInputs('away', draft.awayPlayers);
	updateSaveState();
}

function setPlayerInputs(side, names) {
	if (!Array.isArray(names)) {
		return;
	}
	for (let i = 0; i < state.numPlayers; i++) {
		const input = findPlayerInput(side, i);
		if (input) {
			input.value = names[i] == null ? '' : String(names[i]);
		}
	}
}

// ---------------------------------------------------------------------------
// Notices
// ---------------------------------------------------------------------------

function showNotice({ text, variant = '', actions = [] }) {
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
}

function clearNotice() {
	if (noticeEl) {
		noticeEl.remove();
		noticeEl = null;
	}
}
