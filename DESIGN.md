# WebGrader Design

WebGrader is a Tsugi tool for grading introductory HTML, CSS, JavaScript, and accessibility exercises in the browser. This document describes how the program is put together: data model, runtime, tests, and boundaries.

Usage and instructor setup live in [README.md](README.md). Udemy packaging lives in [UDEMY_EXPORT.md](UDEMY_EXPORT.md).

## Overview

Each assignment is one versioned JSON object stored in the existing Tsugi placement field (`lti_link.json`). No WebGrader-specific assignment tables are required.

The learner workflow is:

1. Edit HTML, CSS, and/or JavaScript.
2. Press **Run / Restart**.
3. Interact with the rendered page when the assignment requires it.
4. Inspect preview and console output.
5. Press **Grade**.

HTML, CSS, and JavaScript are three logical source files assembled into one student page in one iframe. The parent page owns the editors, console, test runner, and grade submission.

## Relationship to DBGrader

WebGrader follows DBGrader conventions where they help:

- thin PHP entry points;
- most authoring and learner behavior in JavaScript;
- complete assignment definition in `lti_link.json`;
- built-in catalog under `assignments/`;
- instructor Edit mode vs learner mode;
- exploratory Run separated from Grade;
- existing Tsugi attempt recording, grade storage, and LTI passback;
- assignment JSON view/import/export.

It is a separate tool and does not share JavaScript internals with DBGrader. Shared Tsugi conventions matter more than cross-tool abstraction.

## Goals

- Grade introductory HTML, CSS, JavaScript, and common accessibility issues in the browser.
- Support HTML-only, CSS-only, JavaScript-only, accessibility, and combined assignments.
- Provide immediate preview, console output, and structured grading feedback.
- Grade observable behavior rather than forcing one implementation style.
- Store the full assignment in the placement JSON field.
- Reuse Tsugi attempts, grades, LTI, and student-data infrastructure.
- Keep a curated assignment library under `assignments/` with stable asset paths.
- Stay small enough to understand and maintain.

## Non-goals

WebGrader does not:

- provide a secure execution boundary against a determined learner;
- guarantee recovery from every synchronous infinite loop in student JavaScript;
- provide server-side browser automation;
- support arbitrary npm packages or arbitrary remote libraries in student code;
- perform screenshot or pixel-perfect visual comparison;
- fully validate WCAG compliance (axe checks catch selected rules);
- provide sophisticated static analysis of code quality;
- conceal browser-delivered tests or assignment data from a determined learner;
- act as a full multi-file cloud IDE.

The browser is an educational execution environment, not a hostile-code sandbox.

## High-level architecture

```text
Tsugi gradable placement
        |
        | lti_link.json (assignment) + lti_result.json (student source)
        v
PHP shell (index.php, save.php, student-save.php, …)
        | injects window.WEBGRADER
        v
JavaScript application (js/webgrader.js + modules)
        |
        +--> Instructions and editor tabs
        |
        +--> One student iframe (assembled HTML/CSS/JS)
        |       +--> console / error capture
        |       +--> optional mocked fetch() (designed; see Network)
        |
        +--> Test runner in parent page
        |       +--> DOM / computed style / CSS source / validators / axe
        |
        v
Tsugi attempt + grade APIs → LTI passback when applicable
```

## Repository layout

```text
webgrader/
├── index.php              # modes, bootstrap, HTML shell
├── register.php, tsugi.php
├── save.php               # instructor assignment JSON → lti_link.json
├── student-save.php       # learner source → lti_result.json
├── exercise.php           # load/normalize assignment + submission
├── assignments.php        # Settings / LTI catalog keys → paths
├── export-udemy.php       # author ZIP download
├── grades.php, grade-detail.php
├── css/webgrader.css
├── js/
│   ├── webgrader.js       # author + learner UI
│   ├── runtime.js         # iframe assembly, dirty revision
│   ├── tests.js           # declarative test handlers
│   ├── validation.js      # assignment shape checks
│   ├── console-capture.js
│   ├── html-validate.js   # optional CDN html-validate
│   ├── css-validate.js    # optional CDN css-tree
│   └── axe-validate.js    # optional CDN axe-core
├── assignments/
│   ├── html/…/assignment.json
│   ├── css/…/assignment.json
│   ├── a11y/…/assignment.json
│   └── javascript/…/assignment.json
├── export/udemy/          # ZIP export pipeline
├── scripts/export-udemy.php
└── tests/udemy-export/
```

PHP stays thin. Grading rules live in `js/`.

## Primary components

### PHP shell

Responsibilities:

- establish the Tsugi/LTI session;
- choose author, learner, settings, or student-data mode;
- load assignment JSON from `lti_link.json` (and copy a built-in when Settings / LTI `exercise` says so);
- load student source from `lti_result.json`;
- inject `window.WEBGRADER` (URLs, exercise, submission, mode flags);
- save instructor JSON (`save.php`) and student source (`student-save.php`);
- hand grade/attempt recording to existing Tsugi APIs;
- serve Udemy export (`export-udemy.php`).

PHP does not implement browser grading rules.

### Learner interface (`js/webgrader.js`)

- assignment title and HTML prompt;
- tabbed HTML / CSS / JavaScript editors (only tabs relevant to file modes);
- preview iframe;
- **Run / Restart**, **Grade**, Reset to starter;
- console panel and test-results panel;
- dirty-state warning when source changed since last Run;
- debounced autosave + localStorage backup;
- instructor-only **Load solution** when `solution` is present.

File modes: `editable`, `readonly`, `hidden`.

### Authoring interface

Edit mode supports:

- title and learner prompt;
- HTML / CSS / JavaScript starter source and file modes;
- tests, points, feedback;
- assets and runtime hints;
- reference `solution`;
- view / copy / import complete assignment JSON;
- select a built-in from the catalog (via Settings, copied into the placement);
- **Export to Udemy** (compatibility preview + ZIP).

Authoring is intentionally practical (textareas), not a large IDE.

### Student iframe (`js/runtime.js`)

One iframe holds the assembled student application:

```text
student iframe
├── HTML (starter or student)
├── CSS (starter or student)
├── JavaScript (starter or student)
├── <base href> when assets / runtime.base_href require it
└── console / error instrumentation
```

Every Run rebuilds the iframe from a clean state, clearing DOM mutations, listeners, globals, timers tied to the old window, console capture, and (when present) mock request history. Storage is reset on Run unless a future assignment model needs persistence.

### Test runner (`js/tests.js`)

The runner lives in the parent page and inspects the running iframe. Tests are declarative handlers keyed by `type`. Optional validators load from pinned CDNs only when an assignment uses them.

The runner distinguishes student assertion failure, syntax/runtime errors, configuration errors, and grader failures. Instructor/grader problems must never be reported as student failures.

Validators (`html_validate`, `css_validate`, `axe_validate`) run first so the results panel order stays predictable.

### Udemy export (`export/udemy/`)

A separate pipeline converts compatible assignments into a ZIP (starter, solution, Jasmine `evaluation.js`, instructions, compatibility notes). WebGrader remains the source of truth; Udemy is a derived package for manual paste. See [UDEMY_EXPORT.md](UDEMY_EXPORT.md).

## Learner lifecycle

### Source revisions

```text
Edit source        → DIRTY
Run / Restart      → RUNNING and CLEAN
Interact           → RUNNING and CLEAN
Grade              → evaluate current running state
Edit source again  → DIRTY; Grade disabled
```

Implementation sketch:

```javascript
sourceRevision += 1;              // on edit
runningRevision = sourceRevision; // after successful Run
// Grade allowed only when runningRevision === sourceRevision
```

### Run / Restart

1. Validate that the assignment can run.
2. Clear previous preview, console, and transient results.
3. Replace the student iframe.
4. Write HTML and CSS; install instrumentation.
5. Inject student JavaScript.
6. Mark the running revision clean.
7. Enable interaction and grading.

### Interact

Learners may click, type, and otherwise use the preview before Grade. Interaction-first grading avoids a general “wait and hope” pattern. Some tests may also perform controlled actions with bounded timeouts (not fixed sleeps).

### Grade

1. Refuse if source is dirty.
2. Run configured tests against the current iframe (and CSS/HTML source where needed).
3. Compute partial credit.
4. Show structured feedback.
5. Autosave source.
6. Record the attempt and update the grade via Tsugi (highest score policy unless Tsugi says otherwise).
7. LTI grade passback when applicable.

Grade may inspect DOM, attributes, text, computed styles, console/runtime errors, CSS source (for pseudo-class rules), and intentionally exposed JavaScript functions.

## Iframe trust model

The iframe is same-origin with:

```text
sandbox="allow-scripts allow-same-origin allow-popups"
```

so the parent can use `contentDocument` / `contentWindow` for DOM and computed-style grading, and learners can open `target="_blank"` links.

Same-origin student JavaScript is not a secure hostile-code boundary. A determined learner may inspect or alter parent state or browser-delivered tests. That is acceptable for typical course use if documented.

A possible future hardening path is an opaque-origin sandbox with an in-frame grading agent and `postMessage()`. That is not required for the current design.

## Infinite loops and runaway code

Synchronous infinite loops in student JavaScript can freeze the tab. WebGrader documents this, rebuilds the iframe on each Run, reports normal runtime errors, and does not claim that an iframe timeout can stop synchronous code. Keep introductory assignments short.

Possible later mitigations (not current requirements): Web Workers for pure-function exercises, source instrumentation, stronger isolation, or server-side browser execution for high-stakes grading.

## Console and error capture

Before student JavaScript runs, the iframe is instrumented for `console.log` / `info` / `warn` / `error`, uncaught `error`, and `unhandledrejection`. Output appears in the WebGrader console panel with limits on entry count, value length, total text, and stack traces. Circular values are formatted safely. The console clears on Run / Restart.

Console noise does not automatically zero the score unless the assignment includes an explicit test (for example `console_includes` or `no_runtime_errors`).

## Network and mock fetch

The assignment JSON may describe a mock network layer (routes, JSON responses, unmatched-request policy) so fetch/JSON exercises can be deterministic without external APIs. When implemented, mocks should:

- replace `window.fetch` in the student iframe before student code runs;
- match configured routes;
- return compatible `Response` objects;
- record requests for tests;
- deny unmatched requests by default.

Example shape:

```json
{
  "network": {
    "mode": "mock",
    "unmatched": "deny",
    "routes": [
      {
        "id": "get-people",
        "method": "GET",
        "url": "/api/people",
        "status": 200,
        "headers": { "Content-Type": "application/json" },
        "json": [
          { "id": 1, "name": "Ada Lovelace" },
          { "id": 2, "name": "Grace Hopper" }
        ]
      }
    ]
  }
}
```

Built-in assignments today do not require mock fetch; the schema and design leave room for it without changing the Edit → Run → Grade model.

## Assignment JSON

### Storage

The entire assignment lives in `lti_link.json`. Student source and attempt results stay in Tsugi student/attempt storage, not in the assignment definition.

### Version fields

```json
{
  "type": "webgrader",
  "schema_version": 1,
  "id": "javascript-fetch-people-001",
  "assignment_version": 1
}
```

- `schema_version` — format understood by WebGrader.
- `assignment_version` — revision of this particular assignment.

### Core shape

```json
{
  "type": "webgrader",
  "schema_version": 1,
  "id": "html-headings-001",
  "assignment_version": 1,
  "title": "Headings and Paragraphs",
  "prompt": "<p>Build a short page with a heading and paragraphs.</p>",
  "files": {
    "html": { "mode": "editable", "starter": "…" },
    "css": { "mode": "hidden", "starter": "" },
    "javascript": { "mode": "hidden", "starter": "" }
  },
  "runtime": { "preview": true },
  "assets": [],
  "tests": [],
  "grading": { "maximum_points": 10, "partial_credit": true },
  "solution": { "html": "…", "css": "", "javascript": "" }
}
```

**Required:** `type`, `schema_version`, `id`, `assignment_version`, `title`, `prompt`, `files`, `tests`.

**Common optional:** `runtime`, `assets`, `network`, `grading`, `hints`, `metadata`, `source`, `solution`.

Editor/library versions are global deployment details, not per-assignment fields.

## Test types

Declarative handlers in `js/tests.js` (plus optional validator modules):

### HTML / DOM

- `selector_exists`, `selector_not_exists`, `selector_count`
- `text_equals`, `text_contains`
- `attribute_equals`, `attribute_exists`
- `html_validate` — html-validate from pinned CDN when present

### CSS

- `computed_style_equals`, `computed_styles_equals`
- `css_validate` — css-tree from pinned CDN
- `css_rule_declares` — selector/property/value in CSS source (for `:hover` / `:visited` / `:active`)
- visibility / geometry helpers as implemented in `tests.js`

### JavaScript / interaction

- `console_includes`
- `call_function` (global function with random ints; `expect_op`: `sum` | `square`)
- `no_runtime_errors`
- DOM tests after learner interaction

### Accessibility

- `axe_validate` — axe-core against the live preview iframe; prefer explicit `runOnly` rule ids for teaching

### Network (when mock layer is present)

- `request_exists`, `request_json_body_equals`

CDN/library load failures for optional validators credit that test automatically so an external outage does not fail the learner; student content errors still fail normally.

## Scoring and feedback

Each test has a stable `id`, learner-facing `name`, `points`, optional failure `feedback`, and type-specific fields.

```text
score = sum(points earned) / maximum_points
```

`grading.maximum_points` is honored when set; otherwise the sum of test points is used. Partial credit is the default.

The UI shows passed/failed tests, points, failure feedback, and separates student syntax/runtime errors from assignment/grader errors. A broken test or missing required asset disables grading rather than silently reducing the student score.

## Assignment repository and catalog

Built-ins live under `assignments/{html,css,a11y,javascript}/…/assignment.json`. `assignments.php` maps Settings / LTI `exercise` keys to those directories.

When an instructor selects a built-in, its JSON is copied into `lti_link.json` as a frozen editable copy. Provenance may be retained:

```json
{
  "source": {
    "assignment_id": "…",
    "path": "assignments/html/simple-list/assignment.json"
  }
}
```

The placement does not auto-update when the repository copy changes.

## Asset policy

Assignment-owned assets live under `assignments/`. Published paths are append-only compatibility contracts: do not delete, move, rename, or silently change meaning; add a new filename for a new version (`cat-v1.png`, `cat-v2.png`).

Declare required assets in the assignment:

```json
{
  "assets": [
    {
      "id": "photo",
      "path": "assignments/a11y/fix-accessibility/assets/cat-v1.png",
      "type": "image",
      "required": true
    }
  ]
}
```

Missing required assets should surface as configuration errors and disable Run/Grade without recording a failed student attempt. Reject paths outside `assignments/`, `..` traversal, absolute filesystem paths, arbitrary remote JavaScript, and unsupported or oversized assets.

## Student submission state

Assignment definition and student work are separate. Student source roughly:

```json
{
  "schema": "webgrader-submission",
  "version": 1,
  "files": {
    "html": "…",
    "css": "…",
    "javascript": "…"
  },
  "source_revision": 12,
  "last_run_revision": 12
}
```

Autosave is independent of Run and Grade: debounced `student-save.php`, localStorage backup keyed by link id, restore on launch, Reset to starter with confirmation. Transient iframe DOM state is not autosaved.

## Assignment validation

`js/validation.js` checks shape before save/run: JSON structure, supported schema, unique test ids, file modes, supported test types, non-negative points, required fields per test type, and asset path rules. Stronger checks (reference solution passes, starter does not already pass everything, JSON Schema / CI) can sit beside this without changing the runtime model.

## Reference solutions

Optional top-level `solution` with `html` / `css` / `javascript`. Instructors get **Load solution** in learner mode; learners do not. Solutions are not injected into the iframe automatically and are not treated as secret when shipped in the browser or a public repo.

## Accessibility

The tool UI should remain keyboard-usable. Assignments may use `axe_validate` and/or DOM attribute tests for teaching checks (`lang`, `alt`, labels, button names). Do not claim full automated WCAG conformance.

## Design principles

1. Keep PHP thin.
2. Keep the assignment in one JSON blob.
3. Use one student iframe.
4. Separate Edit, Run, Interact, and Grade.
5. Grade observable results whenever possible.
6. Prefer declarative tests over arbitrary instructor code.
7. Treat grader/configuration failures differently from student failures.
8. Keep assets under `assignments/` and never break published paths.
9. Reuse existing Tsugi storage and grade infrastructure.
10. Prefer small, understandable extensions over a generalized online IDE.

## Open extensions

Useful directions that fit the current architecture without redesigning it:

- deterministic mock `fetch` and request-log tests;
- richer authoring forms for common test types;
- JSON Schema + repository CI validator;
- controlled automated interactions and optional fresh-run isolation;
- stronger iframe isolation via `postMessage()`;
- CodeMirror (or similar) as a deployment detail;
- catalog compare/update from repository provenance.

## Summary

WebGrader is a small browser grader in the DBGrader mold:

- one assignment JSON blob in the placement field;
- tabbed HTML / CSS / JavaScript source;
- one rebuilt student iframe;
- Edit → Run → Interact → Grade;
- declarative DOM, CSS, console, function, validator, and axe tests;
- existing Tsugi attempts, grades, and LTI passback;
- curated repository assignments with stable assets;
- optional Udemy ZIP export as a derived package.

The foundation is the data model and lifecycle, not a general-purpose IDE.
