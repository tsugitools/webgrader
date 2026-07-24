# WebGrader Design

## Status

Phase 0–1 implemented (HTML edit → Run → Grade MVP). This document remains a living guide; later sections describe deferred phases.

The implementation is deliberately incremental and stays close to the existing DBGrader architecture. Features that are not needed for the current working assignments remain deferred.

### Locked decisions (Phase 0–1)

- **Iframe:** same-origin with `sandbox="allow-scripts allow-same-origin allow-popups"` for direct DOM grading and `target="_blank"` links.
- **Max score:** honor `grading.maximum_points` when set; otherwise sum of test `points`.
- **Prompt:** trusted HTML.
- **Editor:** plain textareas (CodeMirror deferred).
- **Student autosave:** `student-save.php` → `$RESULT->setJson()`, plus localStorage backup keyed by link id.
- **Catalog:** directory-per-assignment with `assignment.json` under `assignments/`; `assignments.php` maps Settings keys to paths.
- **Partial credit:** Grade submits `earned / maximum` (0–1) with tool code `WEBGRADER`.

## Overview

WebGrader is an interactive browser-based autograder for HTML, CSS, and JavaScript.

Each assignment is represented as one versioned JSON object stored in the existing JSON field associated with a gradable Tsugi placement (`lti_link.json`). No new assignment tables or assignment-specific database schema are required.

The learner workflow is:

1. Edit HTML, CSS, and/or JavaScript.
2. Press **Run / Restart**.
3. Interact with the rendered page when the assignment requires it.
4. Inspect preview and console output.
5. Press **Grade**.
6. WebGrader evaluates the current running page and records the attempt and score through existing Tsugi APIs.

The central design idea is that HTML, CSS, and JavaScript are three logical source files but are assembled into one student web page running in one iframe.

## Relationship to DBGrader

WebGrader should follow DBGrader conventions wherever they make sense:

- thin PHP entry points;
- most authoring and learner behavior implemented in JavaScript;
- complete assignment definition stored as JSON in `lti_link.json`;
- built-in assignment catalog under `assignments/`;
- instructor authoring mode and learner mode;
- exploratory Run actions separated from the graded action;
- existing Tsugi attempt recording, grade storage, and LTI grade passback;
- assignment JSON view/import/export;
- simple initial authoring controls rather than a large IDE framework.

WebGrader is a separate tool and does not need to share JavaScript internals with DBGrader. Shared Tsugi conventions and small reusable utilities are preferred over premature cross-tool abstraction.

## Goals

WebGrader should:

- grade introductory HTML, CSS, and JavaScript assignments in the browser;
- support HTML-only, CSS-only, JavaScript-only, and combined assignments;
- provide immediate preview, console output, and grading feedback;
- grade observable behavior rather than unnecessarily enforcing one implementation technique;
- support browser interactions, DOM updates, events, `fetch()`, and JSON;
- use deterministic mock network responses for normal fetch assignments;
- store the complete assignment definition in the existing placement JSON field;
- use existing Tsugi attempt, grade, LTI, and student-data infrastructure;
- provide a curated assignment library in the repository;
- keep assignment assets under `assignments/` with stable paths;
- remain small enough to understand and maintain.

## Non-Goals

The initial implementation will not:

- provide a secure execution boundary against a determined or malicious learner;
- guarantee recovery from every synchronous infinite loop in student JavaScript;
- provide server-side browser automation;
- support arbitrary npm packages;
- support arbitrary remote JavaScript libraries;
- perform screenshot or pixel-perfect visual comparison;
- fully validate WCAG compliance;
- provide sophisticated static analysis of code quality;
- conceal browser-delivered tests or assignment data from a determined learner;
- provide AI-assisted assignment generation;
- become a full multi-file cloud IDE.

The browser is treated as an educational execution environment, not a hostile-code sandbox. Security limitations must be documented honestly.

## High-Level Architecture

```text
Tsugi gradable placement
        |
        | existing lti_link.json assignment blob
        v
WebGrader PHP shell and JavaScript application
        |
        +--> Instructions and editor tabs
        |
        +--> One student iframe
        |       |
        |       +--> assembled HTML
        |       +--> assembled CSS
        |       +--> student JavaScript
        |       +--> console/error capture
        |       +--> optional mocked fetch()
        |
        +--> Test runner in parent page
        |       |
        |       +--> DOM inspection
        |       +--> computed CSS inspection
        |       +--> request-log inspection
        |       +--> optional controlled interactions
        |
        v
Existing Tsugi attempt and grading APIs
        |
        v
LTI grade passback when applicable
```

## Primary Components

### 1. PHP Shell

PHP should remain thin, following the DBGrader pattern.

Likely responsibilities:

- establish the Tsugi/LTI session;
- determine author, learner, student-data, or settings mode;
- load the assignment JSON from `lti_link.json`;
- inject a bootstrap object such as `window.WEBGRADER`;
- render the basic HTML shell;
- save instructor-authored assignment JSON;
- expose existing Tsugi attempt and grade endpoints;
- perform server-side asset preflight when practical.

The PHP layer should not implement the browser grading rules.

### 2. Learner Interface

The learner interface should include:

- assignment title and prompt;
- tabbed HTML, CSS, and JavaScript editors;
- only the tabs relevant to the assignment;
- preview iframe;
- **Run / Restart** button;
- **Grade** button;
- console panel;
- test-results panel;
- Reset to starter code;
- source autosave and recovery;
- a clear dirty-state warning when source has changed since the last Run.

A single editor surface with tabs is preferred over three permanently visible editors.

Each logical file supports these modes:

- `editable`
- `readonly`
- `hidden`

An `optional` mode can be added later if a real assignment requires it.

### 3. Authoring Interface

The first authoring interface should be simple and practical.

It should support:

- metadata and title;
- learner prompt;
- HTML, CSS, and JavaScript starter source;
- file mode selection (`editable`, `readonly`, `hidden`);
- preview configuration;
- mock fetch routes;
- tests;
- points and feedback;
- required asset declarations;
- reference solution source;
- assignment validation;
- Run reference solution;
- preview learner view;
- view/copy complete assignment JSON;
- import assignment JSON;
- select a built-in assignment from the repository catalog.

The first version may use textareas or the same lightweight code editor used by learners. A large drag-and-drop authoring system is not required.

### 4. Student Iframe

There is one student iframe.

It contains the assembled student application:

```text
student iframe
├── supplied or student HTML
├── supplied or student CSS
├── supplied or student JavaScript
├── mocked browser APIs when configured
└── runtime instrumentation
```

HTML, CSS, and JavaScript editor tabs are not separate iframes.

Every Run should rebuild the iframe from a clean state. Rebuilding clears normal page state including:

- DOM mutations;
- event listeners;
- JavaScript globals;
- active timers associated with the discarded window;
- console capture state;
- mock request history.

Storage behavior should initially be reset on Run. Persistent-storage assignments can be added in a later phase.

### 5. Test Runner

The test runner lives in the parent WebGrader application and evaluates the running student iframe.

Initial tests should be declarative and implemented by reusable test handlers. Raw Jasmine-style tests may be supported later as an instructor escape hatch, but should not be required for normal assignment authoring.

The test runner should distinguish:

- student assertion failure;
- student syntax error;
- student runtime error;
- unhandled promise rejection;
- unexpected mock network request;
- assignment configuration error;
- grader runtime error.

Instructor or grader failures must never be reported as student failures.

## Learner Lifecycle

### Source State

WebGrader should track source revisions.

```text
Edit source        -> DIRTY
Run / Restart      -> RUNNING and CLEAN
Interact           -> RUNNING and CLEAN
Grade              -> evaluate current running state
Edit source again  -> DIRTY; Grade disabled
```

The Grade button must not grade an iframe built from stale source.

A source revision counter or stable hash is sufficient:

```javascript
sourceRevision += 1;          // on edit
runningRevision = sourceRevision; // after successful Run
```

Grade is allowed only when `runningRevision === sourceRevision`.

### Run / Restart

Run / Restart:

1. validates that the assignment can run;
2. clears previous preview, console, request log, and transient results;
3. creates or replaces the student iframe;
4. writes HTML and CSS;
5. installs runtime instrumentation and fetch mocks;
6. injects student JavaScript;
7. marks the running revision clean;
8. enables interaction and grading.

### Interact

The learner may interact directly with the student iframe before grading:

- click buttons;
- type into forms;
- submit forms;
- trigger event handlers;
- make mocked fetch requests;
- manipulate application state through the interface.

This interaction-first design avoids a general-purpose “wait and hope” grading pattern.

### Grade

Grade normally inspects the page as the learner left it.

It may inspect:

- current DOM;
- element attributes and text;
- computed styles;
- element geometry;
- console and runtime errors;
- mock fetch request history;
- permitted storage state;
- JavaScript functions or values intentionally exposed by the exercise.

Some tests may perform controlled actions themselves. These should use event- or condition-based completion with bounded timeouts, not arbitrary fixed sleeps.

Grade should:

1. verify the source is not dirty;
2. execute all configured tests;
3. calculate partial credit;
4. display structured feedback;
5. autosave current source;
6. record the attempt;
7. update the stored grade according to Tsugi policy;
8. perform LTI grade passback when applicable.

The default policy should preserve the highest achieved score unless existing Tsugi behavior specifies otherwise.

## Iframe Trust Model

### Phase 1 Choice

The initial implementation may use a same-origin iframe so the parent can directly inspect:

```javascript
frame.contentDocument
frame.contentWindow
```

This keeps the first implementation understandable and enables direct DOM and computed-style grading.

The iframe should still use an appropriate `sandbox` attribute and only the permissions required for the assignment runtime.

### Limitation

Same-origin student JavaScript is not a secure hostile-code boundary. A determined learner may attempt to inspect or alter parent state or browser-delivered tests.

This is acceptable for the initial educational use case, provided the limitation is clearly documented.

### Future Hardening

A future phase may use an opaque-origin sandboxed iframe with an in-frame grading agent and `postMessage()` communication. This is explicitly deferred until the core grader and assignment model are proven useful.

## Infinite Loops and Runaway Code

Student code such as this can freeze the browser UI thread:

```javascript
while (true) {}
```

A normal iframe does not fully protect the parent from this condition.

The first version should:

- document the limitation;
- recreate the iframe on each Run;
- provide normal runtime error reporting;
- avoid claiming that an iframe timeout can terminate synchronous code;
- encourage short introductory assignments.

Possible later mitigations:

- Web Worker execution for pure-function JavaScript exercises;
- source instrumentation for common loop forms;
- stronger origin or process isolation;
- server-side browser execution for high-stakes grading.

These are not Phase 1 requirements.

## Console and Error Capture

Before student JavaScript executes, WebGrader should instrument the iframe to capture:

- `console.log()`;
- `console.info()`;
- `console.warn()`;
- `console.error()`;
- uncaught `error` events;
- `unhandledrejection` events.

The learner should see these in a WebGrader console panel.

The console implementation must limit:

- number of retained entries;
- length of each serialized value;
- total retained text;
- stack trace length.

Circular and complex values should be formatted safely.

Console output is cleared on Run / Restart.

Assignments may include a “no serious runtime errors” test, but console errors should not automatically produce a zero unless the assignment says so.

## Mock Fetch and JSON

Fetch and JSON assignments should normally use a deterministic mock network layer installed before student JavaScript executes.

The mock layer should:

- replace `window.fetch` in the student iframe;
- match configured routes;
- return native or compatible `Response` objects;
- record requests;
- normalize method and headers;
- expose request URL, method, headers, and body to tests;
- reject unmatched requests by default;
- block real network access by default.

Initial route matching may be deliberately simple:

- exact method;
- exact path and query string;
- optional JSON body comparison.

Later phases may add query normalization, path parameters, sequential responses, delays, malformed JSON, and simulated network failures.

Example:

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
        "headers": {
          "Content-Type": "application/json"
        },
        "json": [
          { "id": 1, "name": "Ada Lovelace" },
          { "id": 2, "name": "Grace Hopper" }
        ]
      }
    ]
  }
}
```

## Assignment JSON

### Storage

The entire assignment is stored as one JSON object in the existing placement JSON field.

No new relational assignment model is required.

Student source, attempts, and grading results remain in existing Tsugi student/attempt storage and are not written into the assignment definition.

### Version Fields

Use separate fields for schema and assignment revision:

```json
{
  "type": "webgrader",
  "schema_version": 1,
  "id": "javascript-fetch-people-001",
  "assignment_version": 1
}
```

- `schema_version` describes the JSON format understood by WebGrader.
- `assignment_version` describes a revision of this particular assignment.

### Initial Example

```json
{
  "type": "webgrader",
  "schema_version": 1,
  "id": "javascript-fetch-people-001",
  "assignment_version": 1,
  "title": "Fetch and Display People",
  "prompt": "<p>Load the people list and display each name.</p>",

  "files": {
    "html": {
      "mode": "readonly",
      "starter": "<button id=\"load\">Load People</button>\n<ul id=\"people\"></ul>"
    },
    "css": {
      "mode": "hidden",
      "starter": ""
    },
    "javascript": {
      "mode": "editable",
      "starter": "document.querySelector('#load').addEventListener('click', async () => {\n    // Your code\n});"
    }
  },

  "runtime": {
    "preview": true,
    "storage": "reset_on_run"
  },

  "assets": [],

  "network": {
    "mode": "mock",
    "unmatched": "deny",
    "routes": [
      {
        "id": "people-route",
        "method": "GET",
        "url": "/api/people",
        "status": 200,
        "headers": {
          "Content-Type": "application/json"
        },
        "json": [
          { "id": 1, "name": "Ada Lovelace" },
          { "id": 2, "name": "Grace Hopper" }
        ]
      }
    ]
  },

  "tests": [
    {
      "id": "people-list-count",
      "name": "Displays both people",
      "type": "selector_count",
      "selector": "#people li",
      "expected": 2,
      "points": 5,
      "feedback": "Display one list item for each returned person."
    },
    {
      "id": "people-request",
      "name": "Requests the people endpoint",
      "type": "request_exists",
      "method": "GET",
      "url": "/api/people",
      "points": 5,
      "feedback": "Fetch /api/people when the Load People button is used."
    }
  ],

  "grading": {
    "maximum_points": 10,
    "partial_credit": true
  }
}
```

### Initial Required Fields

- `type`
- `schema_version`
- `id`
- `assignment_version`
- `title`
- `prompt`
- `files`
- `tests`

### Initial Optional Fields

- `runtime`
- `assets`
- `network`
- `grading`
- `hints`
- `metadata`
- `source`
- `solution`

Deployment details such as the code editor version or bundled test library version should remain global rather than being repeated in each assignment.

## Initial Test Types

Phase 1 should implement only a small set of high-value declarative tests.

### HTML / DOM

- `selector_exists`
- `selector_not_exists`
- `selector_count`
- `text_equals`
- `text_contains`
- `attribute_equals`
- `attribute_exists`
- `html_validate` (optional; loads html-validate from a pinned CDN ES module only when present)

### CSS

- `computed_style_equals`
- `element_visible`
- `element_hidden`
- basic geometry comparison such as same row, stacked, or ordered position

### JavaScript / Interaction State

- `no_runtime_errors`
- `request_exists`
- `request_json_body_equals`
- current DOM tests after learner interaction

Phase 1 should not attempt to implement every possible test category.

### Later Test Types

Possible later additions:

- controlled click/input/change/submit actions;
- function-call tests;
- promise and async tests;
- localStorage/sessionStorage tests;
- responsive viewport tests;
- accessibility checks;
- HTML structure validation;
- sequential mock responses;
- custom Jasmine-style instructor tests.

## Test Isolation

The initial default is to grade the current running iframe state.

Tests should be observational whenever practical and should not mutate the page.

A later phase may support test isolation modes:

- `current_state`
- `fresh_run`
- `shared_sequence`

`current_state` is the only required mode for the first implementation.

When controlled-interaction tests are added, they should default to a fresh iframe unless explicitly designed as a sequence.

## Scoring and Feedback

Each test has:

- stable `id`;
- learner-facing `name`;
- `points`;
- optional failure `feedback`;
- test-specific configuration.

Score is:

```text
sum(points earned) / maximum_points
```

Partial credit is the default.

The grader should show:

- passed and failed tests;
- points earned and possible;
- useful failure feedback;
- student syntax/runtime errors separately;
- assignment/grader errors separately.

A broken test or missing required asset disables grading rather than reducing the student score.

## Assignment Repository

Built-in assignments live under `assignments/`.

Recommended structure:

```text
assignments/
├── html/
│   └── headings-and-paragraphs/
│       ├── assignment.json
│       └── assets/
├── css/
│   └── flexbox-cards/
│       ├── assignment.json
│       └── assets/
├── javascript/
│   └── fetch-people/
│       ├── assignment.json
│       └── assets/
└── applications/
    └── todo-list/
        ├── assignment.json
        └── assets/
```

An assignment may be represented as a single JSON file or as `assignment.json` in a directory. Directory-per-assignment is preferred once assets are involved.

The repository should eventually include:

```text
schema/
    webgrader-v1.schema.json
tools/
    validate-assignments.js
assignments.php
README.md
DESIGN.md
```

## Asset Policy

### Location

All assignment-owned assets must live under `assignments/` in the WebGrader repository checkout.

Examples:

- images;
- JSON data files;
- assignment-specific CSS;
- assignment-specific text data;
- small media files needed by the exercise.

### Stable Paths

Published asset paths are append-only compatibility contracts.

Once an asset is referenced by a published assignment:

- do not delete it;
- do not move it;
- do not rename it;
- avoid changing its meaning in place;
- add a new filename for a changed version.

Example:

```text
people-v1.json
people-v2.json
```

An active LTI link may contain old assignment JSON while using the currently checked-out repository assets. Stable asset paths allow the old JSON to continue working without copying assets into placement storage or pinning every placement to a Git commit.

### Required Asset Declaration

Each assignment must declare the assets it requires.

```json
{
  "assets": [
    {
      "id": "people-data",
      "path": "assignments/javascript/fetch-people/assets/people-v1.json",
      "type": "json",
      "required": true
    },
    {
      "id": "avatar",
      "path": "assignments/javascript/fetch-people/assets/grace-v1.png",
      "type": "image",
      "required": true
    }
  ]
}
```

### Asset Preflight

Required assets should be checked:

1. in the authoring interface;
2. by repository validation/CI;
3. when the assignment launches.

If a required asset is missing:

- show an assignment configuration error;
- identify the missing path;
- disable Run and Grade;
- do not record a failed student attempt.

Initial validation only needs existence and permitted-path checks. Optional SHA-256 integrity checking can be added later.

### Asset Safety Rules

The validator should reject:

- paths outside `assignments/`;
- `..` path traversal;
- absolute filesystem paths;
- missing required files;
- arbitrary remote JavaScript;
- unsupported or excessively large assets.

## Assignment Catalog and Import

Built-in assignments should be exposed through an assignment catalog similar to DBGrader.

Instructor setup options:

- start from scratch;
- import/paste assignment JSON;
- choose a built-in assignment.

When a built-in assignment is selected, its complete JSON is copied into `lti_link.json` as a frozen editable copy.

The placement JSON may retain provenance:

```json
{
  "source": {
    "assignment_id": "javascript-fetch-people-001",
    "path": "assignments/javascript/fetch-people/assignment.json"
  }
}
```

The active placement does not automatically update when the repository assignment changes.

A compare/update workflow can be added later.

## Student Submission State

The assignment definition and student work are separate.

A student source payload may resemble:

```json
{
  "schema": "webgrader-submission",
  "version": 1,
  "files": {
    "html": "...",
    "css": "...",
    "javascript": "..."
  },
  "source_revision": 12,
  "last_run_revision": 12
}
```

Runtime logs and grade results are transient attempt data, not editable student source.

The implementation should use existing Tsugi storage patterns rather than introducing new WebGrader-specific tables unless a concrete requirement later proves necessary.

## Autosave and Recovery

Student source should autosave independently of Run and Grade.

Initial behavior:

- debounced server autosave using existing Tsugi storage patterns;
- optional local browser backup for recovery;
- explicit Reset to starter code with confirmation;
- restore saved source on launch;
- do not autosave transient iframe DOM state.

The learner should not lose work because the browser reloads or the iframe is restarted.

## Assignment Validation

Validation should occur before saving and before running.

Initial checks:

- valid JSON;
- supported `type` and `schema_version`;
- unique assignment ID;
- unique test IDs;
- supported file modes;
- at least one editable file;
- valid test types;
- numeric non-negative points;
- points total matches configured maximum or maximum is calculated automatically;
- required selectors and expected values present for each test type;
- no conflicting mock routes with identical method and URL;
- all required assets exist;
- all asset paths remain under `assignments/`;
- assignment blob remains within a configured size limit.

Later validation may also confirm:

- the reference solution passes all tests;
- starter source does not already pass all tests;
- selectors parse correctly;
- mock response bodies match declared content types;
- assignment metadata is complete for catalog publication.

## Reference Solutions

Assignments may include an optional top-level `solution` object:

```json
"solution": {
  "html": "…",
  "css": "…",
  "javascript": "…"
}
```

In learner mode, instructors see a **Load solution** button when a solution is present. It copies the reference source into the editors so the instructor can Run and Grade. Learners do not get the button.

Reference solution fields should not be inserted into the learner iframe automatically. The design does not claim they are secret when delivered to the browser or stored in a public repository.

## Editor Choice

The first implementation should use a lightweight browser code editor with modes for HTML, CSS, and JavaScript.

CodeMirror is a reasonable default because it is lighter than a full IDE-style editor. A plain textarea remains acceptable for the earliest prototype.

The editor implementation is a deployment detail and should not appear in every assignment JSON.

## Accessibility

WebGrader itself should be keyboard accessible and usable with screen readers.

Initial assignment tests may include basic accessibility checks such as:

- required `alt` attributes;
- associated form labels;
- duplicate IDs;
- use of native buttons for button interactions.

WebGrader should not claim full automated accessibility conformance testing.

## Phased Implementation

### Phase 0: Skeleton and JSON Loading

Goal: establish the Tsugi tool and assignment lifecycle without complex grading.

Implement:

- repository/tool skeleton based on DBGrader conventions;
- `index.php`, save path, settings/catalog hooks, and thin PHP bootstrap;
- load/save assignment JSON in `lti_link.json`;
- author and learner modes;
- minimal assignment schema validation;
- built-in assignment catalog under `assignments/`;
- one trivial HTML assignment.

Do not implement fetch mocking, sophisticated editors, or many test types.

Success criterion:

> An instructor can select or paste an assignment JSON blob, save it, and a learner can open the assignment.

### Phase 1: HTML Grader MVP

Goal: prove the complete edit/run/grade loop with the smallest useful grader.

Implement:

- HTML editor tab;
- hidden/read-only CSS and JavaScript support in the schema, even if minimally used;
- one student iframe;
- Run / Restart;
- dirty-state protection;
- Grade current iframe state;
- initial DOM test types;
- test results and partial credit;
- source autosave;
- attempt recording and grade passback;
- Reset to starter;
- asset declarations and launch preflight;
- several built-in HTML assignments.

Success criterion:

> A learner can complete an HTML assignment and receive a correct Tsugi/LTI grade without any new database model.

### Phase 2: CSS Grading

Goal: add practical CSS exercises without visual screenshot comparison.

Implement:

- CSS editor tab;
- read-only supplied HTML;
- `getComputedStyle()` tests;
- visibility tests;
- a small set of geometry/layout tests;
- optional preview viewport dimensions;
- several built-in CSS assignments.

Avoid exact raw CSS source matching when observable layout or computed style is sufficient.

Success criterion:

> A learner can complete selector, typography, spacing, Flexbox, or basic Grid assignments with robust observable tests.

### Phase 3: JavaScript, Console, and DOM Events

Goal: grade introductory browser JavaScript.

Implement:

- JavaScript editor tab;
- console capture panel;
- uncaught error and unhandled rejection capture;
- DOM event assignments using manual learner interaction;
- tests that inspect the resulting DOM state;
- `no_runtime_errors` test;
- several built-in JavaScript assignments.

Success criterion:

> A learner can implement button, form, and DOM-manipulation behavior, interact with it, and grade the resulting state.

### Phase 4: Mock Fetch and JSON

Goal: support deterministic fetch/JSON assignments.

Implement:

- route-based mock `fetch()` installed before student code;
- request logging;
- exact method/URL matching;
- JSON responses;
- request existence and JSON-body tests;
- unmatched-request denial;
- basic HTTP error and network rejection simulation if needed;
- several built-in fetch assignments.

Success criterion:

> A learner can fetch mocked JSON, render it, submit JSON, and receive deterministic grading without external APIs.

### Phase 5: Authoring and Repository Tooling

Goal: make assignment creation and maintenance pleasant for instructors.

Implement:

- structured authoring forms for common test types;
- raw JSON view/import/export;
- reference solution support;
- run reference solution against tests;
- JSON Schema file;
- repository validator;
- GitHub Actions validation;
- catalog metadata and filtering;
- documentation for assignment authors.

Success criterion:

> An instructor can create, validate, preview, commit, and reuse assignments without hand-editing every JSON field.

### Phase 6: Optional Enhancements

Only implement after the earlier phases are stable and used.

Candidates:

- controlled automated interactions;
- fresh-run test isolation;
- responsive viewport test suites;
- storage exercises;
- more flexible route matching;
- sequential network responses;
- custom Jasmine-style tests;
- improved iframe isolation using `postMessage()`;
- Web Worker mode for pure JavaScript functions;
- assignment compare/update from repository provenance;
- progressive hints and solution reveal;
- AI assistance for assignment construction.

These are not commitments and should not complicate earlier phases.

## Suggested Initial File Layout

```text
webgrader/
├── assignments/
│   ├── html/
│   ├── css/
│   ├── javascript/
│   └── applications/
├── css/
│   └── webgrader.css
├── js/
│   ├── author.js
│   ├── learner.js
│   ├── runtime.js
│   ├── tests.js
│   ├── fetch-mock.js
│   ├── console-capture.js
│   └── validation.js
├── schema/
│   └── webgrader-v1.schema.json
├── DESIGN.md
├── README.md
├── assignments.php
├── index.php
├── save.php
├── grades.php
├── grade-detail.php
└── tsugi.php
```

This is illustrative. The implementation should mirror DBGrader naming and organization when that reduces friction.

## Initial Acceptance Tests

The first production-capable release should demonstrate:

1. HTML-only assignment with editable HTML.
2. CSS-only assignment with read-only HTML and editable CSS.
3. JavaScript-only assignment with read-only HTML and editable JavaScript.
4. Combined HTML/CSS/JavaScript assignment.
5. Console syntax/runtime error display.
6. Manual interaction followed by Grade.
7. Mock GET returning JSON.
8. Mock POST with JSON body inspection.
9. Missing required asset disables grading as a configuration error.
10. Editing after Run disables Grade until rerun.
11. Student source survives page reload.
12. Correct partial score is recorded and passed through existing Tsugi grade handling.

## Design Principles

1. **Keep PHP thin.**
2. **Keep the assignment in one JSON blob.**
3. **Use one student iframe.**
4. **Separate Edit, Run, Interact, and Grade.**
5. **Grade observable results whenever possible.**
6. **Prefer declarative tests over arbitrary instructor code.**
7. **Treat grader/configuration failures differently from student failures.**
8. **Keep assets under `assignments/` and never break published paths.**
9. **Reuse existing Tsugi storage and grade infrastructure.**
10. **Do not solve future problems before the basic grader is useful.**

## Open Questions

Resolved for Phase 0–1 (see Status). Remaining for later phases:

- whether reference solutions live inside `assignment.json` or in instructor-only companion files;
- exact CodeMirror version and loading strategy;
- browser support baseline;
- maximum assignment JSON and asset sizes;
- naming of the final published repository (`webgrader` is the working name);
- whether prompts should later support Markdown rendered to HTML.

## Summary

WebGrader should begin as a small browser-based extension of the successful DBGrader pattern:

- one complete assignment JSON blob in the existing placement field;
- tabbed HTML, CSS, and JavaScript source;
- one rebuilt student iframe;
- Edit → Run → Interact → Grade;
- direct observable DOM and CSS tests;
- console/error capture;
- deterministic mocked fetch and JSON;
- existing Tsugi attempts, grades, and LTI passback;
- curated repository assignments with append-only required assets;
- phased implementation that proves value before adding complexity.

The first milestone is not a generalized online IDE. It is a dependable HTML grader with the right data model and lifecycle so CSS, JavaScript, fetch, authoring, and future AI assistance can be added without redesigning the foundation.
