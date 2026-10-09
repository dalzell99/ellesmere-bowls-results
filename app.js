'use strict';

/*
 * Ellesmere Interclub Score Sheet — client logic.
 *
 * Flow: load the draw from api.php -> pick competition, round, fixture -> render
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
const NAME_KEY = 'scorerName';
const DRAFT_PREFIX = 'draft.';
// URL query params mirroring the selector state (values are option labels).
const COMPETITION_PARAM = 'competition';
const ROUND_PARAM = 'round';
const FIXTURE_PARAM = 'fixture';
const DRAFT_TTL_MS = 7 * 24 * 60 * 60 * 1000; // keep drafts for ~7 days
const AUTOSAVE_MS = 500;
const DISPUTE_MAX_EDGE = 1600; // longest edge of the uploaded photo, in px
const DISPUTE_QUALITY = 0.8;   // JPEG quality for the re-encoded photo
const DISPUTE_HEADER_BYTES = 256 * 1024;   // header slice read to find the photo's pixel size
const DISPUTE_PROCESS_TIMEOUT_MS = 30000;  // give up if a photo never finishes decoding

const state = {
	draw: null,
	competitionIndex: -1,
	roundIndex: -1,
	gameIndex: -1,
	gameId: null,        // database id of the selected fixture (from the draw)
	gameRevision: null,  // per-game optimistic-lock revision at load time
	entries: [],        // filtered discipline entries, in original array order
	numPlayers: 0,
	loadedGame: null,   // baseline snapshot used for the same-fixture conflict check
	baseline: null,     // sheet values as rendered/saved; edits are diffed against this
	saveInFlight: false,
	forceReload: false, // set after a same-fixture conflict; blocks re-saving
	disputeDisciplineName: '', // discipline name recorded with the next dispute
};

let autosaveTimer = null;
let noticeEl = null;
let disputeImage = null; // processed JPEG data URL awaiting submit, or null
let disputeBusy = false; // true while a photo is being compressed or uploaded
let disputeGeneration = 0; // bumped on reset so stale async results are discarded

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
	disputeDialog: document.getElementById('disputeDialog'),
	disputeSummary: document.getElementById('disputeSummary'),
	disputeUploadButton: document.getElementById('disputeUploadButton'),
	disputeUploadInput: document.getElementById('disputeUploadInput'),
	disputeCameraButton: document.getElementById('disputeCameraButton'),
	disputeCameraInput: document.getElementById('disputeCameraInput'),
	disputePreview: document.getElementById('disputePreview'),
	disputePreviewImg: document.getElementById('disputePreviewImg'),
	disputeStatus: document.getElementById('disputeStatus'),
	disputeSubmitButton: document.getElementById('disputeSubmitButton'),
	disputeCloseButton: document.getElementById('disputeCloseButton'),
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

	el.competitionSelect.addEventListener('change', () => { onCompetitionChange(); syncUrlParams(); });
	el.roundSelect.addEventListener('change', () => { onRoundChange(); syncUrlParams(); });
	el.fixtureSelect.addEventListener('change', () => { onFixtureChange(); syncUrlParams(); });
	el.saveButton.addEventListener('click', onSave);

	// Dispute dialog: the visible buttons trigger the hidden file inputs; both
	// inputs share one handler, and neither touches the draw or its drafts.
	el.disputeUploadButton.addEventListener('click', () => el.disputeUploadInput.click());
	el.disputeCameraButton.addEventListener('click', () => el.disputeCameraInput.click());
	el.disputeUploadInput.addEventListener('change', onDisputeFileChosen);
	el.disputeCameraInput.addEventListener('change', onDisputeFileChosen);
	el.disputeSubmitButton.addEventListener('click', onSubmitDispute);
	el.disputeCloseButton.addEventListener('click', closeDisputeDialog);
	el.disputeDialog.addEventListener('close', resetDisputeDialog);

	// Any edit in the sheet re-validates and (debounced) autosaves a draft.
	el.sheetBody.addEventListener('input', onSheetInput);
	el.sheetBody.addEventListener('change', onSheetInput);
	// The per-discipline Dispute buttons are rebuilt with the sheet, so delegate.
	el.sheetBody.addEventListener('click', onSheetBodyClick);

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
// Load draw data
// ---------------------------------------------------------------------------

async function loadDraw() {
	try {
		const res = await fetch(`${API}?action=load`, { headers: { Accept: 'application/json' } });
		const data = await res.json();
		if (!res.ok || !data.success) {
			throw new Error(data.error || `HTTP ${res.status}`);
		}
		state.draw = data.draw;
		populateCompetitionSelect();
		applyUrlSelection();
	} catch (err) {
		showNotice({
			variant: 'danger',
			text: `Could not load draw data: ${err.message}`,
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
 * Preselect competition, round and fixture from the URL params. Each value may
 * be an option value (the index) or a case-insensitive option label; unknown or
 * absent values leave that dropdown on its placeholder.
 */
function applyUrlSelection() {
	const params = new URLSearchParams(location.search);

	const competition = matchOption(el.competitionSelect, params.get(COMPETITION_PARAM));
	if (competition === null) {
		return;
	}
	el.competitionSelect.value = competition;
	onCompetitionChange();

	const round = matchOption(el.roundSelect, params.get(ROUND_PARAM));
	if (round === null) {
		return;
	}
	el.roundSelect.value = round;
	onRoundChange();

	const fixture = matchOption(el.fixtureSelect, params.get(FIXTURE_PARAM));
	if (fixture === null) {
		return;
	}
	el.fixtureSelect.value = fixture;
	onFixtureChange();
}

/** Resolve a URL value to a matching <option> value, by exact value then label. */
function matchOption(select, raw) {
	if (raw == null) {
		return null;
	}
	const target = String(raw).trim();
	if (!target) {
		return null;
	}
	const lower = target.toLowerCase();
	let byLabel = null;
	for (const opt of select.options) {
		if (!opt.value) {
			continue; // placeholder
		}
		if (opt.value === target) {
			return opt.value;
		}
		if (byLabel === null && opt.textContent.trim().toLowerCase() === lower) {
			byLabel = opt.value;
		}
	}
	return byLabel;
}

/**
 * Mirror the current selector labels into the URL query string so the address
 * can be copied/shared. Uses replaceState, so it adds no history entries.
 */
function syncUrlParams() {
	const params = new URLSearchParams(location.search);
	setParamFromSelect(params, COMPETITION_PARAM, el.competitionSelect);
	setParamFromSelect(params, ROUND_PARAM, el.roundSelect);
	setParamFromSelect(params, FIXTURE_PARAM, el.fixtureSelect);
	const query = params.toString();
	history.replaceState(null, '', location.pathname + (query ? `?${query}` : '') + location.hash);
}

function setParamFromSelect(params, key, select) {
	const opt = select.selectedOptions[0];
	if (opt && opt.value) {
		params.set(key, opt.textContent.trim());
	} else {
		params.delete(key);
	}
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
	state.gameId = null;
	state.gameRevision = null;
	state.loadedGame = null;
	state.baseline = null;
	state.forceReload = false;
	clearAutosave();
	clearNotice();
	setStatus('', '');
	el.saveButton.textContent = 'Save';
	resetDisputeDialog();
	updateDisputeButtons();
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

	// Capture the database identity so a save can target this exact fixture.
	state.gameId = Number.isInteger(game.id) ? game.id : null;
	state.gameRevision = Number.isInteger(game.revision) ? game.revision : null;

	state.forceReload = false;

	el.sheetTitle.textContent = `${competition.competition} Score Sheet`;
	el.sheetRound.textContent = String(Number.isFinite(Number(round.round)) ? round.round : ri + 1);

	el.sheetBody.textContent = '';
	el.sheetBody.appendChild(buildDisciplineTable(game));
	el.sheetBody.appendChild(buildPlayerTable(game));

	// Baseline captured for the conflict check.
	state.loadedGame = deepCopy(game);
	// Snapshot what the sheet now shows, so later edits can be detected.
	state.baseline = sheetSnapshot();

	refreshDatalists(game.homeTeam, game.awayTeam);

	el.sheet.hidden = false;
	el.emptyState.hidden = true;
	setStatus('', '');
	el.saveButton.textContent = 'Save';

	clearNotice();
	showDraftBannerIfAny();
	updateSaveState();
	resetDisputeDialog();
	updateDisputeButtons();
}

function buildDisciplineTable(game) {
	const table = element('table', null, 'sheet-table');
	table.appendChild(buildColgroup(['22%', '16%', '31%', '31%']));

	const thead = document.createElement('thead');
	const headRow = document.createElement('tr');
	headRow.appendChild(element('th', '', 'team-head team-head--blank'));
	headRow.appendChild(element('th', '', 'metrics-header team-head--blank'));
	headRow.appendChild(element('th', String(game.homeTeam || ''), 'team-head'));
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
				const discCell = element('th', null, `disc ${discClass}`);
				discCell.rowSpan = rowspan;
				// The name and its Dispute button are stacked and centred together.
				const discInner = element('div', null, 'disc__inner');
				discInner.appendChild(element('span', capitalise(group.name), 'disc__name'));
				const disputeButton = element('button', 'Dispute', 'disc-dispute-button');
				disputeButton.type = 'button';
				disputeButton.dataset.discIdx = String(item.index);
				disputeButton.dataset.discipline = group.name;
				disputeButton.setAttribute('aria-label', `Dispute ${capitalise(group.name)} result`);
				disputeButton.hidden = true; // revealed by updateDisputeButtons()
				discInner.appendChild(disputeButton);
				discCell.appendChild(discInner);
				scoreRow.appendChild(discCell);
			}
			scoreRow.appendChild(element('td', 'Score', 'metric'));
			scoreRow.appendChild(numberCell(item.index, 'homeScore', saved.homeScore));
			scoreRow.appendChild(numberCell(item.index, 'awayScore', saved.awayScore));
			tbody.appendChild(scoreRow);

			const endsRow = document.createElement('tr');
			endsRow.appendChild(element('td', 'Ends', 'metric'));
			endsRow.appendChild(numberCell(item.index, 'homeEnds', saved.homeEnds));
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

/**
 * Condition for a discipline in the current competition, or null when the
 * discipline has none (in which case any value is valid). Returns { num, type }
 * with type lowercased ("score" or "ends").
 */
function conditionFor(disciplineName) {
	const competition = state.draw && state.draw[state.competitionIndex];
	const conditions = competition && competition.conditions;
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

/** Toggle the invalid styling/message on every number input from a key->message map. */
function applyNumberInvalidMarks(invalidMarks) {
	for (const input of el.sheetBody.querySelectorAll('input[data-disc-idx]')) {
		const key = `${input.dataset.discIdx}|${input.dataset.field}`;
		const message = invalidMarks.get(key);
		input.classList.toggle('is-invalid', message !== undefined);
		if (message !== undefined) {
			input.setAttribute('aria-invalid', 'true');
			input.title = message;
		} else {
			input.removeAttribute('aria-invalid');
			input.removeAttribute('title');
		}
	}
}

function validateSheet() {
	let ok = true;

	// "discIdx|field" -> message, for inputs that fail a condition or the score-vs-ends rule.
	const invalidMarks = new Map();

	const scores = state.entries.map((entry) => {
		// position is the original discipline index, so the server can keep scores
		// aligned with the competition's discipline array even if names repeat.
		const result = { position: entry.index, discipline: entry.name };
		let allPresent = true;

		for (const field of ['homeScore', 'awayScore', 'homeEnds', 'awayEnds']) {
			const input = findInput(entry.index, field);
			const value = input ? parseWholeNumber(input.value) : null;
			result[field] = value;
			if (value === null) {
				ok = false;
				allPresent = false;
			}
		}

		if (allPresent) {
			const condition = conditionFor(entry.name);
			const violations = condition ? conditionViolations(condition, result) : [];
			// Score-vs-ends is a universal rule, so it applies even with no condition.
			violations.push(...scoreEndsViolations(result));
			for (const violation of violations) {
				ok = false;
				invalidMarks.set(`${entry.index}|${violation.field}`, violation.message);
			}
		}
		return result;
	});

	applyNumberInvalidMarks(invalidMarks);

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
	// A missing gameId means the fixture is gone from the draw (e.g. it reloaded
	// after an insert/delete); block the save until the sheet is re-rendered.
	el.saveButton.disabled = !ok || state.saveInFlight || state.forceReload || state.gameId === null;
}

function onSheetInput() {
	// Any edit invalidates the previous "Saved" state; the button returns to "Save".
	el.saveButton.textContent = 'Save';
	scheduleAutosave();
	updateSaveState();
}

/** Current sheet values, in the same shape as a draft/save snapshot. */
function sheetSnapshot() {
	const { scores, homePlayers, awayPlayers } = validateSheet();
	return { scores, homePlayers, awayPlayers };
}

/** True when the current sheet holds edits that differ from when it was rendered/saved. */
function hasUnsavedChanges() {
	if (el.sheet.hidden || !state.baseline || state.forceReload) {
		return false;
	}
	return !sameGame(sheetSnapshot(), state.baseline);
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
	el.saveStatus.textContent = text;
	el.saveStatus.className = 'save-status' + (variant ? ` save-status--${variant}` : '');
}

// ---------------------------------------------------------------------------
// Save
// ---------------------------------------------------------------------------

async function onSave() {
	if (state.saveInFlight || state.forceReload || state.gameId === null) {
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
	updateDisputeButtons();

	try {
		const res = await fetch(`${API}?action=save`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				gameId: state.gameId,
				revision: state.gameRevision,
				userName: getScorerName(),
				game,
			}),
		});

		const data = await res.json();

		if (res.status === 409) {
			handleConflict();
			return;
		}
		if (!res.ok || !data.success) {
			setStatus(`Save failed: ${data.error || res.status}`, 'error');
			return;
		}

		// Success: adopt the new revision, update the in-memory copy, drop the draft.
		if (Number.isInteger(data.revision)) {
			state.gameRevision = data.revision;
		}
		game.revision = state.gameRevision;
		state.draw[state.competitionIndex].rounds[state.roundIndex].games[state.gameIndex] = game;
		state.loadedGame = deepCopy(game);
		state.baseline = sheetSnapshot();
		removeDraft(draftKey());
		refreshDatalists(game.homeTeam, game.awayTeam);
		clearNotice();
		// Confirm via the button itself; it stays "Saved" until the sheet is edited.
		setStatus('', '');
		el.saveButton.textContent = 'Saved';
	} catch (err) {
		setStatus(`Save failed: ${err.message}`, 'error');
	} finally {
		state.saveInFlight = false;
		updateSaveState();
		updateDisputeButtons();
	}
}

/**
 * A per-game lock means a 409 is always *this* fixture: someone else saved it
 * first, so their result wins. Drop our draft and offer a reload.
 */
function handleConflict() {
	removeDraft(draftKey());
	state.forceReload = true;
	setStatus('Someone else saved this fixture.', 'error');
	showNotice({
		variant: 'danger',
		text: 'Someone else saved this fixture. Reload to see their result.',
		actions: [{ label: 'Reload', onClick: resync }],
	});
	updateSaveState();
	updateDisputeButtons();
}

/** Re-fetch the draw and re-render the current fixture with the latest values. */
async function resync() {
	try {
		const res = await fetch(`${API}?action=load`, { headers: { Accept: 'application/json' } });
		const data = await res.json();
		if (!res.ok || !data.success) {
			throw new Error(data.error || `HTTP ${res.status}`);
		}
		state.draw = data.draw;
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
		baseVersion: state.gameRevision,
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

// ---------------------------------------------------------------------------
// Dispute (upload a scorecard photo for a saved result)
// ---------------------------------------------------------------------------

/** True when a single score entry has at least one saved score/ends value. */
function scoreEntryHasResults(entry) {
	if (!entry) {
		return false;
	}
	const fields = ['homeScore', 'awayScore', 'homeEnds', 'awayEnds'];
	return fields.some((field) => entry[field] !== null && entry[field] !== undefined && entry[field] !== '');
}

/** True when the given discipline has at least one saved score/ends value. */
function disciplineHasResults(disciplineIndex) {
	const scores = state.loadedGame && Array.isArray(state.loadedGame.scores)
		? state.loadedGame.scores
		: [];
	return scoreEntryHasResults(scores[disciplineIndex]);
}

/**
 * Show a Dispute button inside every discipline cell that already has saved
 * results, and enable it only when the sheet is idle enough to dispute.
 */
function updateDisputeButtons() {
	const ready = Boolean(state.baseline) && !state.forceReload && !state.saveInFlight;
	for (const button of el.sheetBody.querySelectorAll('.disc-dispute-button')) {
		const discIdx = Number(button.dataset.discIdx);
		const hasResults = disciplineHasResults(discIdx);
		button.hidden = !hasResults;
		button.disabled = !hasResults || !ready;
	}
}

/** Open the dispute dialog for whichever discipline button was clicked. */
function onSheetBodyClick(event) {
	const button = event.target instanceof Element
		? event.target.closest('.disc-dispute-button')
		: null;
	if (!button || button.hidden || button.disabled) {
		return;
	}
	openDisputeDialog(Number(button.dataset.discIdx), button.dataset.discipline || '');
}

function openDisputeDialog(disciplineIndex, disciplineName) {
	if (!state.baseline || !disciplineHasResults(disciplineIndex)) {
		return;
	}
	const game = state.loadedGame;
	const competition = state.draw[state.competitionIndex];
	const round = competition.rounds[state.roundIndex];
	const roundNumber = Number.isFinite(Number(round.round)) ? round.round : state.roundIndex + 1;
	const disciplineLabel = capitalise(disciplineName);
	el.disputeSummary.textContent =
		`${competition.competition} \u00b7 Round ${roundNumber} \u00b7 ${game.homeTeam || '?'} vs ${game.awayTeam || '?'} \u00b7 ${disciplineLabel}`;

	resetDisputeDialog();

	// Remember which game (discipline) this dispute belongs to; it is sent on
	// submit so the stored photo can be filed against that game.
	state.disputeDisciplineName = disciplineName;

	// Only offer the camera where one exists (a phone/tablet coarse pointer).
	const coarsePointer = typeof window.matchMedia === 'function'
		&& window.matchMedia('(pointer: coarse)').matches;
	el.disputeCameraButton.hidden = !coarsePointer;

	if (typeof el.disputeDialog.showModal === 'function') {
		el.disputeDialog.showModal();
	} else {
		el.disputeDialog.setAttribute('open', '');
	}
	el.disputeUploadButton.focus();
}

/** Clear the photo, preview and status left from a previous dispute attempt. */
function resetDisputeDialog() {
	// Invalidate any in-flight compress/upload so a late continuation cannot apply
	// its photo to the freshly reset dialog; the operation clears disputeBusy itself.
	disputeGeneration += 1;
	disputeImage = null;
	el.disputeUploadInput.value = '';
	el.disputeCameraInput.value = '';
	el.disputePreview.hidden = true;
	el.disputePreviewImg.onload = null;
	el.disputePreviewImg.onerror = null;
	el.disputePreviewImg.removeAttribute('src');
	el.disputeUploadButton.hidden = false;
	el.disputeCameraButton.hidden = false;
	el.disputeSubmitButton.hidden = false;
	el.disputeSubmitButton.textContent = 'Submit dispute';
	el.disputeSubmitButton.disabled = true;
	el.disputeCloseButton.textContent = 'Cancel';
	el.disputeCloseButton.disabled = false;
	el.disputeUploadButton.disabled = false;
	el.disputeCameraButton.disabled = false;
	setDisputeStatus('', '');
}

/** Drop any processed photo and hide its preview (used when a choice is rejected). */
function clearDisputeSelection() {
	disputeImage = null;
	el.disputePreview.hidden = true;
	el.disputePreviewImg.onload = null;
	el.disputePreviewImg.onerror = null;
	el.disputePreviewImg.removeAttribute('src');
	setDisputeControlsDisabled(false);
}

function closeDisputeDialog() {
	if (typeof el.disputeDialog.close === 'function') {
		el.disputeDialog.close();
	} else {
		el.disputeDialog.removeAttribute('open');
	}
}

function setDisputeStatus(text, variant) {
	el.disputeStatus.textContent = text;
	el.disputeStatus.className = 'save-status' + (variant ? ` save-status--${variant}` : '');
}

function setDisputeControlsDisabled(disabled) {
	el.disputeUploadButton.disabled = disabled;
	el.disputeCameraButton.disabled = disabled;
	el.disputeCloseButton.disabled = disabled;
	el.disputeSubmitButton.disabled = disabled || !disputeImage;
}

/** Shared handler for both file inputs: compress the chosen photo for preview. */
async function onDisputeFileChosen(event) {
	if (disputeBusy) {
		return;
	}
	const input = event.target;
	const file = input.files && input.files[0];
	// Clear the input so picking the same file again still fires "change".
	input.value = '';
	if (!file) {
		return;
	}
	if (!file.type || file.type.indexOf('image/') !== 0) {
		clearDisputeSelection();
		setDisputeStatus('Please choose an image file.', 'error');
		return;
	}

	disputeBusy = true;
	const generation = disputeGeneration;
	setDisputeControlsDisabled(true);
	setDisputeStatus('Processing photo\u2026', '');

	try {
		const dataUrl = await withTimeout(
			compressImage(file),
			DISPUTE_PROCESS_TIMEOUT_MS,
			'image processing timed out'
		);
		if (generation !== disputeGeneration) {
			return; // dialog was reset or closed while decoding; discard the result
		}
		disputeImage = dataUrl;
		// Reveal the preview only after the image itself has decoded, so an
		// empty or broken frame is never shown.
		el.disputePreviewImg.onload = () => {
			el.disputePreviewImg.onload = null;
			el.disputePreview.hidden = false;
		};
		el.disputePreviewImg.onerror = () => {
			el.disputePreviewImg.onerror = null;
			el.disputePreview.hidden = true;
		};
		el.disputePreviewImg.src = dataUrl;
		if (el.disputePreviewImg.complete && el.disputePreviewImg.naturalWidth > 0) {
			el.disputePreview.hidden = false;
		}
		setDisputeStatus('', '');
	} catch (err) {
		if (generation !== disputeGeneration) {
			return;
		}
		clearDisputeSelection();
		setDisputeStatus("Couldn't read that image \u2014 try JPEG or PNG.", 'error');
	} finally {
		disputeBusy = false;
		if (generation === disputeGeneration) {
			setDisputeControlsDisabled(false);
		}
	}
}

async function onSubmitDispute() {
	if (disputeBusy || !disputeImage) {
		return;
	}
	disputeBusy = true;
	const generation = disputeGeneration;
	setDisputeControlsDisabled(true);
	setDisputeStatus('Uploading\u2026', '');

	try {
		const res = await fetch(`${API}?action=dispute`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				gameId: state.gameId,
				discipline: state.disputeDisciplineName,
				userName: getScorerName(),
				image: disputeImage,
			}),
		});

		const data = await res.json();
		if (generation !== disputeGeneration) {
			return; // dialog was reset or closed while uploading; discard the result
		}
		if (!res.ok || !data.success) {
			setDisputeStatus(`Dispute failed: ${data.error || res.status}`, 'error');
			return;
		}

		// Success: confirm the save. The draw, its revisions and the drafts are
		// deliberately untouched, so no conflict/reload flow is involved.
		disputeImage = null;
		showDisputeSuccess();
	} catch (err) {
		if (generation !== disputeGeneration) {
			return;
		}
		setDisputeStatus(`Dispute failed: ${err.message}`, 'error');
	} finally {
		disputeBusy = false;
		if (generation === disputeGeneration) {
			setDisputeControlsDisabled(false);
		}
	}
}

/** Switch the dialog to a finished state confirming the photo was saved. */
function showDisputeSuccess() {
	el.disputePreview.hidden = true;
	el.disputeUploadButton.hidden = true;
	el.disputeCameraButton.hidden = true;
	el.disputeSubmitButton.hidden = true;
	el.disputeCloseButton.textContent = 'Close';
	setDisputeStatus('Dispute saved.', 'ok');
}

/**
 * Decode a File for canvas drawing. Prefers createImageBitmap with EXIF
 * orientation so phone photos are not saved sideways; falls back to an <img>
 * element (modern browsers auto-orient it when drawn) and revokes the object URL.
 */
async function decodeImage(file) {
	if (typeof createImageBitmap === 'function') {
		try {
			const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
			return {
				image: bitmap,
				width: bitmap.width,
				height: bitmap.height,
				release: () => bitmap.close(),
			};
		} catch (err) {
			/* fall through to the <img> path */
		}
	}

	const url = URL.createObjectURL(file);
	try {
		const img = await loadImage(url);
		return {
			image: img,
			width: img.naturalWidth || img.width,
			height: img.naturalHeight || img.height,
			release: () => URL.revokeObjectURL(url),
		};
	} catch (err) {
		URL.revokeObjectURL(url);
		throw err;
	}
}

function loadImage(url) {
	return new Promise((resolve, reject) => {
		const img = new Image();
		img.onload = () => resolve(img);
		img.onerror = () => reject(new Error('image decode failed'));
		img.src = url;
	});
}

/**
 * Compress a chosen photo to a JPEG data URL: downscale so the longest edge is at
 * most DISPUTE_MAX_EDGE (never upscaling), then re-encode. Targets roughly
 * 200-600 KB, well under PHP's default post_max_size (8M); raise the limits only
 * if larger photos are ever needed.
 */
async function compressImage(file) {
	// Read the pixel size from the file header first so the browser can be asked
	// to decode straight to the target size. Decoding a phone photo at full
	// resolution (a 50 MP shot is ~200 MB once decoded) can exhaust a mobile
	// tab's memory and reload the page, losing the dialog with no error shown.
	const dimensions = await readImageDimensions(file);
	if (!dimensions) {
		// Without a readable size we cannot guarantee a bounded decode, so decline
		// rather than risk materialising an enormous bitmap at full resolution.
		throw new Error('unmeasurable image');
	}

	if (Math.max(dimensions.width, dimensions.height) > DISPUTE_MAX_EDGE) {
		const bitmap = await decodeDownscaled(file, dimensions);
		try {
			const fitted = fitWithin(bitmap.width, bitmap.height, DISPUTE_MAX_EDGE);
			return drawToJpeg(bitmap, fitted.width, fitted.height);
		} finally {
			bitmap.close();
		}
	}

	const source = await decodeImage(file);
	try {
		const fitted = fitWithin(source.width, source.height, DISPUTE_MAX_EDGE);
		return drawToJpeg(source.image, fitted.width, fitted.height);
	} finally {
		if (typeof source.release === 'function') {
			source.release();
		}
	}
}

/**
 * Read an image's pixel dimensions from its file header without decoding it.
 * Supports JPEG (Start-Of-Frame) and PNG (IHDR); anything else returns null so
 * the caller falls back to a normal decode. The size is the raw (un-oriented)
 * one, which is all that is needed to choose a resize target.
 */
async function readImageDimensions(file) {
	const bytes = new Uint8Array(await file.slice(0, DISPUTE_HEADER_BYTES).arrayBuffer());
	if (bytes.length < 24) {
		return null;
	}
	// PNG signature, then width/height in the IHDR chunk at bytes 16 and 20.
	if (bytes[0] === 0x89 && bytes[1] === 0x50 && bytes[2] === 0x4e && bytes[3] === 0x47) {
		const width = readUint32BE(bytes, 16);
		const height = readUint32BE(bytes, 20);
		return width && height ? { width, height } : null;
	}
	// JPEG: walk the marker segments up to the Start-Of-Frame.
	if (bytes[0] === 0xff && bytes[1] === 0xd8) {
		return readJpegDimensions(bytes);
	}
	return null;
}

function readUint32BE(bytes, offset) {
	return ((bytes[offset] << 24) | (bytes[offset + 1] << 16)
		| (bytes[offset + 2] << 8) | bytes[offset + 3]) >>> 0;
}

function readJpegDimensions(bytes) {
	let offset = 2; // skip the SOI marker
	while (offset + 9 < bytes.length) {
		if (bytes[offset] !== 0xff) {
			offset += 1; // resynchronise on the next marker
			continue;
		}
		const marker = bytes[offset + 1];
		offset += 2;
		// Markers that carry no length payload.
		if (marker === 0x01 || (marker >= 0xd0 && marker <= 0xd9)) {
			continue;
		}
		const length = (bytes[offset] << 8) | bytes[offset + 1];
		if (length < 2) {
			break;
		}
		const isSOF = (marker >= 0xc0 && marker <= 0xc3)
			|| (marker >= 0xc5 && marker <= 0xc7)
			|| (marker >= 0xc9 && marker <= 0xcb)
			|| (marker >= 0xcd && marker <= 0xcf);
		if (isSOF) {
			const height = (bytes[offset + 3] << 8) | bytes[offset + 4];
			const width = (bytes[offset + 5] << 8) | bytes[offset + 6];
			return width && height ? { width, height } : null;
		}
		offset += length;
	}
	return null;
}

/**
 * Ask the browser to decode the file at a reduced size. resizeWidth/-Height
 * preserve the aspect ratio but force the given dimension, so cap the longer
 * side reported by the header; the caller then trims any remaining excess.
 * imageOrientation keeps EXIF-rotated phone photos upright.
 */
function decodeDownscaled(file, dimensions) {
	const options = { imageOrientation: 'from-image', resizeQuality: 'high' };
	if (dimensions.width >= dimensions.height) {
		options.resizeWidth = DISPUTE_MAX_EDGE;
	} else {
		options.resizeHeight = DISPUTE_MAX_EDGE;
	}
	return createImageBitmap(file, options);
}

/** Scale a size down so its longest edge is at most maxEdge; never upscales. */
function fitWithin(width, height, maxEdge) {
	const longest = Math.max(width, height);
	if (longest <= maxEdge) {
		return { width: Math.max(1, width), height: Math.max(1, height) };
	}
	const scale = maxEdge / longest;
	return {
		width: Math.max(1, Math.round(width * scale)),
		height: Math.max(1, Math.round(height * scale)),
	};
}

/** Draw a decoded image at the given size to a JPEG data URL. */
function drawToJpeg(image, width, height) {
	const canvas = document.createElement('canvas');
	canvas.width = width;
	canvas.height = height;
	const ctx = canvas.getContext('2d');
	if (!ctx) {
		throw new Error('canvas unsupported');
	}
	ctx.drawImage(image, 0, 0, width, height);
	return canvasToJpegDataUrl(canvas);
}

/** Reject if a promise does not settle in time, so a stalled decode cannot wedge the dialog. */
function withTimeout(promise, ms, message) {
	return new Promise((resolve, reject) => {
		const timer = setTimeout(() => reject(new Error(message)), ms);
		promise.then(
			(value) => { clearTimeout(timer); resolve(value); },
			(error) => { clearTimeout(timer); reject(error); }
		);
	});
}

function canvasToJpegDataUrl(canvas) {
	if (typeof canvas.toBlob === 'function') {
		return new Promise((resolve, reject) => {
			canvas.toBlob((blob) => {
				if (!blob) {
					reject(new Error('encode failed'));
					return;
				}
				const reader = new FileReader();
				reader.onload = () => resolve(String(reader.result));
				reader.onerror = () => reject(new Error('encode failed'));
				reader.readAsDataURL(blob);
			}, 'image/jpeg', DISPUTE_QUALITY);
		});
	}
	try {
		return Promise.resolve(canvas.toDataURL('image/jpeg', DISPUTE_QUALITY));
	} catch (err) {
		return Promise.reject(err);
	}
}
