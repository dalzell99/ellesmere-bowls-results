# Game Dispute Photo Workflow — Implementation Plan

Project: `C:\Users\dalze\Documents\ellesmere-bowls-results` (vanilla HTML/CSS/JS + PHP).

## Objective
For the currently viewed game (one game is shown at a time via Competition → Round →
Fixture), once that game has **saved results**, show a **Dispute** button next to Save.
The button opens a prompt that lets the user upload an existing scorecard photo, or (on a
mobile device) take a new one with the camera. The chosen photo is **compressed and resized
in the browser**, then uploaded to the server, which saves it in a `disputes/` folder under
a filesystem-safe filename that includes the scorer's name, the date/time and the game
details (competition, round, teams).

## Locked decisions
- **Storage:** server-side `disputes/` folder written by a new `POST api.php?action=dispute`
  (mirrors how `backups/` is produced). Not a device download, not browser storage.
- **Trigger:** the Dispute button is shown **only when the selected game already has saved
  results in `draw.json`**. "Has results" = its `scores` array has at least one entry with
  any of `homeScore`/`awayScore`/`homeEnds`/`awayEnds` non-null. Hidden on no selection, on
  a game with no results, and whenever nothing is rendered.
- **Capture UI:** two buttons in the prompt — **Upload photo** (always) using
  `<input type="file" accept="image/*">`, and **Take photo** (revealed only on a detected
  mobile/coarse-pointer device) using
  `<input type="file" accept="image/*" capture="environment">`.
- **No `draw.json` change:** the dispute is a standalone file plus an on-screen confirmation.
  `draw.json`, its version, the conflict flow and `normalise_game()` are **untouched** — this
  deliberately avoids coupling disputes to the md5 save-conflict model.
- **Compression:** done client-side before upload (spec below).

## Files to change
- `api.php` — add `dispute` action + helpers (slug/filename builder, image writer). No change
  to `load`/`save`.
- `app.js` — button gating, dispute dialog logic, image compression, upload.
- `index.html` — Dispute button in `.sheet__actions`; `disputeDialog` markup.
- `styles.css` — dialog preview/thumbnail, dispute button, status states.
- `.gitignore` — add `disputes/*` **and** `!disputes/.gitkeep`. With only `disputes/*`, the
  placeholder is ignored too (the same reason `backups/*` ignores `backups/.gitkeep`).
- `disputes/` — directory created at runtime, with a tracked `.gitkeep` placeholder.

## API: `POST api.php?action=dispute`
JSON request body:
```json
{
  "competitionIndex": 0,
  "roundIndex": 1,
  "gameIndex": 0,
  "userName": "Jane",
  "image": "data:image/jpeg;base64,<...>"
}
```
Behaviour:
1. Validate the JSON body and that `competitionIndex`/`roundIndex`/`gameIndex` are ints ≥ 0
   (reuse `filter_int`). Acquire the **shared** lock (`acquire_lock(..., LOCK_SH)`), read
   `draw.json` (same read path as `handle_load`), and resolve `competition`, `round`,
   `homeTeam`, `awayTeam` from those indices. Release the lock.
   - 400 `bad_body` / `bad_indices`; 400 `indices_out_of_range` if the game is absent.
   - 400 `no_results` if that game has no saved results (defensive; the client also gates).
2. Decode `image`: require exactly a `data:image/jpeg;base64,` prefix (the client always
   re-encodes to JPEG, so accepting JPEG only keeps the `.jpg` extension honest and avoids
   writing PNG bytes into a file named `.jpg`), `base64_decode` the payload, and verify JPEG
   magic bytes (`FF D8 FF`). Reject otherwise with 400 `bad_image`. Enforce a decoded size cap
   (e.g. 8 MB) → 413 `too_large`.
3. Build the **safe filename** server-side (never trust a client filename):
   `dispute_<scorer>_<competition>_R<round>_<home>-vs-<away>_<YYYYMMDD-HHMMSS>.jpg`
   - Each segment via a `sanitise_slug()` helper (like `sanitise_user`): keep
     `[A-Za-z0-9_-]`, replace runs of anything else with `-`, collapse/trim `-`, blank →
     `unknown`; cap each segment (scorer ≤ 40, teams ≤ 40, competition ≤ 40).
   - Round as the numeric value (`Number(round.round)` or index+1 fallback).
   - Timestamp from the **server** clock via `date('Ymd-His')` (authoritative).
   - Cap the whole basename to ~180 chars; the client always re-encodes to JPEG, so the
     extension is always `.jpg` regardless of the source format.
   - Collision naming matches `backup_file()`: append `-1`, `-2`, … (the atomic create in
     step 4 is the mechanism).
4. `mkdir disputes/` if needed (0775, recursive), then create the file **atomically** with
   `fopen($path, 'x')` inside the collision loop (`x` fails when the name already exists) and
   write the decoded bytes. This closes the small race where two concurrent disputes could both
   pass a `file_exists()` check and overwrite each other; cap the retry loop (e.g. 100 attempts)
   and fail with `write_failed` rather than looping. Failure → 500 `write_failed` /
   `mkdir_failed`.
5. Respond `200 { "success": true, "file": "<stored basename>" }`. Errors use the existing
   `{ "success": false, "error": "<code>" }` shape. Every response keeps
   `Content-Type: application/json`.
- Dispatch exactly like `save`: `if ($method === 'POST' && $action === 'dispute')`.

## Client (`app.js`)
- New `el` refs: `disputeButton`, `disputeDialog`, `disputeForm`, `disputeSummary`,
  `disputeUploadButton`, `disputeUploadInput`, `disputeCameraButton`, `disputeCameraInput`,
  `disputePreview`, `disputePreviewImg`, `disputeStatus`, `disputeSubmitButton`,
  `disputeCloseButton`.
- New module state: `disputeImage` (data-URL string), `disputeBusy` (bool).
- `gameHasResults(game)`: `Array.isArray(game.scores) && game.scores.some(s => s && ['homeScore','awayScore','homeEnds','awayEnds'].some(f => s[f] !== null && s[f] !== undefined && s[f] !== ''))`.
- `updateDisputeButton()`:
  `el.disputeButton.hidden = !(state.baseline && !state.forceReload && gameHasResults(state.loadedGame))`.
  Call at the end of `renderSheet()`, after a successful `onSave()` (results now exist → show),
  and from `hideSheet()` (which nulls `state.baseline`, so the button ends up hidden). Also
  disable it while `state.saveInFlight`. Hiding it when `state.forceReload` is set avoids
  offering a dispute against a result a same-fixture conflict has already superseded.
- `openDisputeDialog()`: reject if the current game has no results; set the summary from the
  current `draw` game (`<competition> · Round <n> · <home> vs <away>`); reset preview/status;
  reveal the camera button when `typeof window.matchMedia === 'function' &&
  window.matchMedia('(pointer: coarse)').matches` (leave it hidden otherwise, so desktop never
  shows a camera prompt); open via `showModal()` with the `open`-attribute fallback used by
  `openNameDialog()`, and focus **Upload photo**.
- File selection (both inputs share one handler): reject non-`image/*` with an inline error;
  call `compressImage(file)`; on success store the data URL, show a thumbnail, enable
  **Submit dispute**; on failure show "Couldn't read that image — try JPEG or PNG." Clear the
  input value after reading so the same file can be re-picked.
- Submit: `POST ${API}?action=dispute` with
  `{ competitionIndex, roundIndex, gameIndex, userName: getScorerName(), image: disputeImage }`.
  Disable dialog buttons in flight. On `200 { success, file }` show a success view with the
  saved filename and a **Close** button (optionally a `showNotice` banner). On error show
  `data.error` inline. Never touches `draw.json`, `state.version`, or the draft.
- Reset on dialog `close` and on fixture change.

### Image compression (`compressImage`)
- Input: a `File`. Output: a `data:image/jpeg;base64,...` string.
- Decode with `createImageBitmap(file, { imageOrientation: 'from-image' })` so a phone photo's
  EXIF orientation is honoured (otherwise portrait shots can be saved sideways); fall back to
  `new Image()` + `URL.createObjectURL` (modern browsers auto-orient `<img>` when drawn) and
  revoke object URLs after use.
- Scale so the longest edge is ≤ **1600 px**, **downscaling only** (never upscale).
- Draw to an offscreen `<canvas>`; export `canvas.toBlob(cb, 'image/jpeg', 0.8)` (fallback
  `canvas.toDataURL('image/jpeg', 0.8)`), then convert to a data URL. Targets ≈ 200–600 KB
  for a typical phone photo, well under PHP's default `post_max_size` (8M).
- Comment that `post_max_size` covers the upload; note only if larger photos are needed.

## UI markup / styles
- `index.html`: add
  `<button type="button" id="disputeButton" class="secondary-button" hidden>Dispute result</button>`
  inside `.sheet__actions` (after the Save button + status). Add a
  `<dialog id="disputeDialog" class="dialog">` following the `nameDialog` pattern: title
  "Dispute this result", a summary line, a hint, the two hidden file inputs triggered by
  visible buttons, a hidden preview block with `<img id="disputePreviewImg">`, an inline
  status line, and an actions row with **Submit dispute** (primary, `type="button"`, disabled
  until a photo is processed) and **Cancel/Close** (`type="button"`, calls `dialog.close()`).
  Give the hidden file inputs `<label>`/`aria-label`s and the preview `<img>` non-empty `alt`
  text. Do **not** use a `method="dialog"` form for the primary action, so pressing Enter can
  never close the dialog instead of submitting.
- `styles.css`: reuse `.dialog*` classes; add `.dispute-preview` (bordered thumbnail, max
  width), `.dispute-summary` (bold game line) and a `.save-status`-style inline error/success
  variant for `disputeStatus`.

## Tasks (ordered)
1. `api.php`: add `sanitise_slug()`, `build_dispute_filename()`, `handle_dispute()` and the
   dispatch branch. Verify the filename builder on names with spaces, slashes, quotes and
   Unicode, and that collisions get a `-1` suffix.
2. `.gitignore` (`disputes/*` + `!disputes/.gitkeep`) and `disputes/.gitkeep`; confirm with
   `git check-ignore disputes/.gitkeep` that the placeholder is **not** ignored.
3. `index.html`: Dispute button + dispute dialog markup.
4. `styles.css`: dialog/preview/status styling.
5. `app.js`: `gameHasResults` + button gating wired into render/save/hide; dialog open/reset;
   `compressImage`; upload handler + success/error states. Keep all DOM writes via
   `textContent`/`value` (never `innerHTML`), matching the existing code.
6. Manual verification (below).

## Verification
- Run `php -S localhost:8000`; open `http://localhost:8000/`.
- A game with no saved results shows **no** Dispute button; save a result and it appears
  immediately after the successful save; switching to an unsaved game hides it again.
- Reloading a page whose game already has results shows the button on first render.
- Clicking Dispute opens the dialog with the correct competition/round/teams summary.
- Desktop: **Upload photo** opens the file picker and no camera button is shown; mobile (or a
  coarse-pointer emulation): **Take photo** appears and opens the camera.
- A large phone photo is downscaled (longest edge ≤ 1600) and re-encoded to JPEG before
  upload; the preview shows the processed image.
- Submit writes `disputes/dispute_<name>_<competition>_R<n>_<home>-vs-<away>_<YYYYMMDD-HHMMSS>.jpg`;
  the confirmation shows the exact filename and the file opens as a valid JPEG.
- A second dispute of the same game the same second gets a `-1` suffix (no overwrite).
- `draw.json` is byte-for-byte unchanged after a dispute (version, results, backups untouched).
- Scorer/team/competition names containing `/`, `\`, `..`, spaces, quotes or `<script>` yield
  a safe filename and never affect the path outside `disputes/`.
- A non-image upload and an oversized image are rejected with a clear inline error.
- `{}` / missing `image` / out-of-range indices return the documented 4xx and do not crash.
- A portrait phone photo is saved upright (EXIF orientation honoured), not rotated.
- Pressing Enter inside the dialog does not close it (only **Cancel/Close** does).
- `git check-ignore disputes/.gitkeep` reports nothing (the placeholder is tracked).
- `disputes/` is created on the first dispute and reused for later ones.

## Out of scope
- Viewing, listing or downloading saved disputes from the UI (files live in `disputes/` for
  manual review only).
- Recording a dispute reference in `draw.json` (explicitly declined by the user).
- Authentication beyond the self-declared scorer name.
- De-duplicating or pruning dispute files.
- Editing/annotating the photo before upload.

## Risks / notes
- `disputes/` must be writable by the PHP process, same as `backups/`.
- Base64-in-JSON adds ~33% overhead; the compression target keeps payloads far below the
  default `post_max_size`. Raise it only if the limits are changed.
- Some phone formats (e.g. HEIC) may not decode in every browser; surface a clear error and
  ask for JPEG/PNG rather than failing silently.
- Server-side resolution of game details means the filename reflects the current `draw.json`
  for those indices; if the draw were restructured to shift indices the name could point at a
  different game, which is acceptable given the existing index-based save model.
- `disputes/` sits under the web root, so a saved photo is fetchable by anyone who knows its
  URL. If disputes must stay private, deny direct access (e.g. an `.htaccess`/server rule) or
  store the folder behind the PHP handler — out of scope unless requested.
- The atomic `fopen($path, 'x')` create still treats a name collision as a retry, so the loop
  must be capped (e.g. 100 attempts) and then fail with `write_failed` rather than spin.
