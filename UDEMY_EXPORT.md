# Udemy Export Design

## Status

Phase 0 (observations) and Phase 1 (minimal HTML export CLI) implemented.

Phase 1 success criterion met (2026-07-24): exported `simple-list` was entered manually into a Udemy Web Development coding exercise and graded successfully. See `docs/udemy-observations.md`.

Proposed design for exporting compatible WebGrader assignments into files used to author Udemy Web Development coding exercises.

WebGrader remains the authoritative assignment format and runtime. Udemy export produces a derived, reviewable package. The first implementation should be small and conservative.

## Goals

The exporter should:

1. Read one WebGrader assignment JSON document.
2. Generate a learner starter file.
3. Generate a complete solution file.
4. Generate a Jasmine evaluation file.
5. Export instructions, hints, and solution explanation.
6. Combine WebGrader's logical HTML, CSS, and JavaScript files where necessary.
7. Validate the generated package locally.
8. Produce a clear compatibility report.
9. Never silently omit unsupported grading behavior.

## Non-Goals

The initial exporter will not:

- Upload exercises directly to Udemy.
- Automate Udemy's instructor interface.
- Depend on undocumented Udemy APIs.
- Guarantee that every WebGrader assignment is exportable.
- Reproduce Tsugi attempts, grading, or LTI grade return.
- Translate arbitrary custom JavaScript tests perfectly.
- Support arbitrary external packages or complex applications.
- Make the Udemy files the canonical assignment source.

## Current Udemy Model

As of July 2026, Udemy's instructor guidance describes three relevant pieces:

- A **solution file** containing a correct implementation.
- An **evaluation file** containing unit tests.
- A **starter file** containing the code initially shown to learners.

Udemy's Web Development coding exercises support HTML, CSS, JavaScript, and TypeScript. Udemy documents Jasmine as the Web Development testing framework.

The exact authoring interface and accepted filenames can change. The exporter should therefore generate a package for instructor review rather than assume a permanent upload API.

## Core Principle

> WebGrader is the source format. Udemy is an export target.

Export must not modify the source assignment. Unsupported features must be reported explicitly.

## Primary Export Interface

The primary Phase-One interface is an **Export to Udemy** action in the authoring UI.

It should return a ZIP download directly to the browser:

```text
fetch-people-udemy.zip
```

The exporter assembles each member as an in-memory string or byte stream and writes it directly into the ZIP response. It should not create a persistent build directory or leave generated export files on the application server.

A command-line wrapper may also be provided for development and CI:

```bash
php scripts/export-udemy.php \
    assignments/javascript/fetch-people/assignment.json \
    > fetch-people-udemy.zip
```

The command writes ZIP bytes to standard output. An explicit `--output` option may write the final ZIP file when useful for local development, but intermediate files should still not be written to disk.

## ZIP Package

```text
fetch-people-udemy.zip
├── starter.html
├── solution.html
├── evaluation.js
├── instructions.md
├── hints.md
├── solution-explanation.md
├── manifest.json
└── COMPATIBILITY.md
```

These filenames are WebGrader export conventions. The instructor may extract, copy, or rename them as required by Udemy's current interface.

## In-Memory ZIP Generation

Phase One should build the ZIP as a streaming HTTP response.

Conceptually:

```php
$archive = new UdemyZipStream($downloadName);
$archive->addString('starter.html', $starterHtml);
$archive->addString('solution.html', $solutionHtml);
$archive->addString('evaluation.js', $evaluationJs);
$archive->addString('instructions.md', $instructionsMarkdown);
$archive->addString('manifest.json', $manifestJson);
$archive->addString('COMPATIBILITY.md', $compatibilityMarkdown);
$archive->finish();
```

The exact ZIP implementation may use a maintained streaming ZIP library or a small internal adapter. The design requirement is more important than the library choice:

- No persistent export directory.
- No intermediate generated files.
- No cleanup job.
- ZIP bytes stream directly to the response or standard output.
- Repository assets are read from their existing checked-out paths and streamed into the archive.
- Large assets should be added as streams rather than fully loaded into memory.

A short-lived system temporary file is acceptable only as a compatibility fallback when the deployed ZIP implementation cannot stream correctly. It must be deleted before the request completes and must never become part of normal application storage.

### `starter.html`

The learner-facing starter file.

### `solution.html`

The complete reference solution.

### `evaluation.js`

Generated Jasmine tests.

### `instructions.md`

The title, learning objective, and problem statement.

### `hints.md`

Ordered learner hints, when present.

### `solution-explanation.md`

The instructor-provided explanation of the correct solution.

### `manifest.json`

Machine-readable export metadata and compatibility status.

### `COMPATIBILITY.md`

A concise human-readable report of converted, partial, and unsupported features.

## Source Assignment Requirements

An exportable assignment should contain starter and solution content:

```json
{
  "type": "webgrader",
  "schema_version": 1,
  "id": "javascript-fetch-people",
  "title": "Fetch and Display People",
  "learning_objective": "Use fetch() to load JSON and update the DOM.",
  "instructions": "Complete the application...",
  "files": {
    "html": {
      "mode": "readonly",
      "starter": "<button id=\"load\">Load</button>",
      "solution": "<button id=\"load\">Load</button>"
    },
    "css": {
      "mode": "hidden",
      "starter": "",
      "solution": ""
    },
    "javascript": {
      "mode": "editable",
      "starter": "",
      "solution": "..."
    }
  },
  "tests": [],
  "hints": [],
  "solution_explanation": "..."
}
```

The exporter must fail when required solution content is missing.

## Combining HTML, CSS, and JavaScript

WebGrader keeps HTML, CSS, and JavaScript as logical files. The initial Udemy export combines them into one HTML document:

```html
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Exercise</title>
  <style>
/* WebGrader CSS */
  </style>
</head>
<body>
<!-- WebGrader HTML -->

<script>
/* WebGrader JavaScript */
</script>
</body>
</html>
```

The exporter must safely escape closing script sequences in embedded student JavaScript. Generated files should remain readable and should not be minified.

## File Modes

WebGrader file modes affect the starter file but not the solution file.

| WebGrader mode | Starter export behavior |
|---|---|
| `editable` | Include starter content as learner work |
| `readonly` | Include supplied content |
| `hidden` | Include supplied content if required at runtime |
| `optional` | Include starter content when present |

Udemy may not preserve WebGrader's editable/read-only distinction in a combined file. The exporter must warn when an assignment depends on that distinction.

Example:

```text
PARTIAL: HTML is read-only in WebGrader but may be editable in Udemy.
```

## Test Translation

### Preferred Model

WebGrader tests should be declarative whenever possible. Each supported test type has a deterministic Jasmine emitter.

WebGrader:

```json
{
  "id": "heading-exists",
  "name": "The page contains a heading",
  "type": "selector_exists",
  "selector": "h1",
  "points": 1
}
```

Generated Jasmine:

```javascript
describe("Page structure", function () {
    it("The page contains a heading", function () {
        expect(document.querySelector("h1")).not.toBeNull();
    });
});
```

### Phase-One Test Types

Keep the first supported set small:

- `selector_exists`
- `selector_not_exists`
- `selector_count`
- `text_equals`
- `text_contains`
- `attribute_equals`
- `class_present`
- `computed_style`
- `function_exists`
- `function_result`

### Later Interaction Tests

Possible later types:

- `click_changes_dom`
- `input_changes_dom`
- `form_submission`
- `event_dispatch`
- `local_storage_value`
- `request_observed`
- `json_rendered`

Asynchronous tests must use Jasmine's supported asynchronous mechanisms rather than arbitrary sleep delays.

### Raw Jasmine

Raw Jasmine is an escape hatch, not the normal assignment format.

It may be copied only with explicit opt-in:

```json
{
  "type": "jasmine",
  "export": {
    "udemy": true
  },
  "source": "expect(document.querySelector('#answer')).not.toBeNull();"
}
```

Raw tests without opt-in should produce an unsupported-feature error.

Unknown test types must never be silently skipped.

## Test Grouping

Generated tests should use readable suites:

```javascript
describe("HTML structure", function () {
    // HTML tests
});

describe("CSS presentation", function () {
    // CSS tests
});

describe("JavaScript behavior", function () {
    // JavaScript tests
});
```

Internal IDs may be retained as comments:

```javascript
// WebGrader test: heading-exists
```

## Points and Scoring

WebGrader may assign unequal point values. Udemy may not preserve identical weighting.

The exporter should:

- Preserve test order.
- Preserve learner-facing test names.
- Include WebGrader points in comments.
- Warn when tests have unequal weights.
- Avoid claiming equivalent numeric scoring.

```javascript
// WebGrader points: 3
it("Displays all returned people", function () {
    // ...
});
```

## Mock Fetch and JSON

WebGrader can provide controlled fetch routes and a request log. Udemy export is more constrained.

### Initial Policy

Mock-fetch assignments are not considered automatically compatible.

The exporter reports routes such as:

```text
UNSUPPORTED: Mock route GET /api/people cannot be assumed to exist in Udemy.
```

### Later Experiment

A later phase may generate a Jasmine prelude that:

- Replaces `window.fetch`.
- Matches method and URL.
- Returns deterministic `Response` objects.
- Records requests.
- Supports JSON bodies and status codes.

This should be added only after successful tests in Udemy's actual exercise environment.

## Assets

WebGrader assignments explicitly declare required assets under `assignments/`:

```json
{
  "assets": [
    {
      "path": "assignments/javascript/fetch-people/assets/people-v1.json",
      "type": "json",
      "required": true
    }
  ]
}
```

Every exported asset receives a status:

- `embedded`
- `copied`
- `manual_upload_required`
- `unsupported`

### Initial Asset Policy

- Small CSS and JavaScript are embedded in generated HTML.
- Images and JSON files are copied into an export `assets/` directory.
- Missing required assets cause export failure.
- Assets outside `assignments/` are rejected.
- `../` path traversal is rejected.
- Remote assets are not fetched automatically.
- The compatibility report tells the instructor which assets require Udemy verification.

Example ZIP contents:

```text
fetch-people-udemy.zip
├── starter.html
├── solution.html
├── evaluation.js
└── assets/
    ├── people-v1.json
    └── avatar-v1.png
```

## Metadata Export

Useful optional WebGrader fields:

```json
{
  "learning_objective": "...",
  "estimated_minutes": 10,
  "hints": [
    "Use document.querySelector().",
    "Remember to await response.json()."
  ],
  "solution_explanation": "...",
  "related_lecture": "..."
}
```

The exporter writes these into Markdown files for copying into Udemy.

## Compatibility Levels

### `compatible`

All required behavior maps to supported exporter features.

### `compatible_with_warnings`

The package is usable but requires instructor review or minor adaptation.

### `unsupported`

A critical assignment feature cannot be represented safely.

Example manifest:

```json
{
  "format": "webgrader-udemy-export",
  "version": 1,
  "source_assignment": "javascript-fetch-people",
  "compatibility": "compatible_with_warnings",
  "generated_files": [
    "starter.html",
    "solution.html",
    "evaluation.js",
    "instructions.md"
  ],
  "warnings": [
    {
      "code": "READONLY_NOT_PRESERVED",
      "message": "The HTML may be editable in Udemy."
    }
  ],
  "errors": []
}
```

## Compatibility Report

`COMPATIBILITY.md` should be concise and actionable:

```markdown
# Udemy Export Compatibility

Overall status: Compatible with warnings

## Converted

- Starter HTML/CSS/JavaScript
- Reference solution
- 8 declarative tests
- Instructions
- 2 hints
- Solution explanation

## Warnings

- Read-only HTML cannot be guaranteed in Udemy.
- Unequal WebGrader point weights may not be preserved.

## Unsupported

- Mock fetch delay was not exported.

## Instructor Checklist

- Enter the generated files into a test Udemy coding exercise.
- Verify the solution passes every Jasmine test.
- Verify the starter fails the intended tests.
- Confirm asset paths.
- Preview the exercise as a learner.
```

## Export Validation

Before reporting success, the exporter must verify:

1. The source JSON is valid.
2. The WebGrader schema version is supported.
3. Required starter content exists.
4. Required solution content exists.
5. All required assets exist.
6. Asset paths remain under `assignments/`.
7. Test IDs are unique.
8. Every test type is recognized.
9. Every test is converted or explicitly reported unsupported.
10. Generated JavaScript parses.
11. Generated HTML assembles correctly.
12. The solution passes the generated tests locally.
13. The starter does not already pass every test unless explicitly permitted.

The exporter should test its output, not merely emit text.

## Repository Structure

```text
webgrader/
├── assignments/
├── docs/
│   ├── DESIGN.md
│   └── UDEMY_EXPORT.md
├── export/
│   └── udemy/
│       ├── Exporter.php
│       ├── TestEmitter.php
│       ├── HtmlBuilder.php
│       └── CompatibilityReport.php
├── scripts/
│   └── export-udemy.php
└── tests/
    └── udemy-export/
```

Keep the implementation small. Split code only where doing so clarifies responsibilities.

Possible interface:

```php
$package = UdemyExporter::build($assignment);

$package->compatibility;
$package->members;       // logical ZIP members, not disk paths
$package->warnings;
$package->errors;

UdemyZipResponse::stream($package, 'fetch-people-udemy.zip');
```

`UdemyExporter::build()` should produce strings, streams, and metadata. It should not require an output directory.

## Phased Implementation

### Phase 0 — Observe Udemy

Purpose: avoid implementing assumptions.

- Manually create one or two Udemy Web Development exercises.
- Record the current authoring fields and workflow.
- Save small known-good starter, solution, and evaluation examples.
- Confirm how Udemy loads HTML, CSS, JavaScript, and additional assets.
- Confirm how Jasmine accesses the learner page.
- Confirm asynchronous test behavior.
- Confirm whether weighted tests exist.

Deliverable: `docs/udemy-observations.md`.

### Phase 1 — Minimal HTML Export

Support only:

- Instructions
- One combined starter HTML file
- One combined solution HTML file
- `selector_exists`
- `selector_count`
- `text_equals`
- `attribute_equals`
- Jasmine evaluation output
- Manifest and compatibility report
- Local validation against the reference solution
- Directly streamed ZIP download
- In-memory generation of all text members
- No persistent intermediate files or export directory

Do not support fetch, assets, asynchronous tests, or custom Jasmine.

Success criterion:

> A simple WebGrader HTML exercise can be exported as a ZIP download, entered manually into Udemy, and graded successfully.

### Phase 2 — CSS and Basic JavaScript

Add:

- Computed-style tests
- Function existence and return-value tests
- Basic DOM event tests
- HTML/CSS/JavaScript combination rules
- Read-only and hidden-file warnings
- Better test grouping and messages

Success criterion:

> Introductory HTML, CSS, and JavaScript assignments export without hand-writing Jasmine.

### Phase 3 — Assets and Fetch Experiments

Add cautiously:

- Export asset directory
- Asset compatibility statuses
- Static JSON experiments
- Generated fetch-mock prelude
- Asynchronous Jasmine tests
- Request-observation tests

This phase must be driven by real tests inside Udemy.

Success criterion:

> At least one deterministic fetch/JSON assignment works in WebGrader and Udemy from the same source assignment.

### Phase 4 — Authoring Integration

Add:

- Improved **Export to Udemy** authoring integration
- Compatibility preview before ZIP generation
- Per-test export status
- Suggested repairs for unsupported tests
- Export regression tests in CI

Success criterion:

> An instructor can author once in WebGrader, review compatibility, and download a complete Udemy package.

### Phase 5 — Optional Enhancements

Possible later work:

- TypeScript export
- Additional Udemy-supported frameworks
- Smarter custom-Jasmine translation
- AI-assisted compatibility repair
- Udemy-specific assignment variants
- Browser automation for preview verification, if permitted and maintainable

These are not required for the initial exporter.

## Error Handling

Exporter failures are instructor/configuration errors, not student errors.

Examples:

- Missing solution code
- Missing asset
- Unknown test type
- Invalid selector
- Invalid generated JavaScript
- Unsupported network behavior
- Assignment too large
- Path traversal attempt

The command should return a non-zero exit status for critical errors.

Warnings may still produce a package unless strict mode is requested:

```bash
php scripts/export-udemy.php assignment.json --strict
```

In strict mode, any warning prevents a successful export.

## Security

The exporter should:

- Reject paths outside `assignments/`.
- Reject `../` traversal.
- Avoid executing assignment-provided PHP or shell commands.
- Avoid fetching remote URLs.
- Treat raw Jasmine as executable code requiring explicit opt-in.
- Write only inside the selected output directory.
- Escape HTML and JavaScript boundaries carefully.

## Testing Strategy

Unit tests should cover:

- Every declarative test emitter
- HTML assembly
- Script escaping
- Missing-field validation
- Asset path validation
- Compatibility classification
- Unknown test handling
- Manifest generation

Golden-file tests should compare generated packages against checked-in expected output.

Integration tests should:

1. Load the generated solution.
2. Load generated Jasmine.
3. Confirm all tests pass.
4. Load the generated starter.
5. Confirm intended tests fail.

Maintain a small set of manually verified Udemy examples as compatibility fixtures.

## Maintenance Policy

Udemy is an external platform and may change.

Therefore:

- Isolate Udemy-specific code under `export/udemy/`.
- Version the exporter format.
- Record the date of the last manual Udemy verification.
- Avoid undocumented upload automation.
- Prefer warnings over guesses.
- Re-run compatibility fixtures after significant Udemy changes.

Example:

```json
{
  "udemy_export_version": 1,
  "last_verified": "2026-07-24"
}
```

## Final Recommendation

Begin with Phase 0 and Phase 1 only.

The first useful exporter needs to prove only that a simple WebGrader assignment can generate:

- a starter file,
- a solution file,
- a Jasmine evaluation file,
- and a truthful compatibility report.

Once that round trip works reliably in Udemy, expand the supported subset one feature at a time.

## References

- Udemy Teaching Center, *Instructor guide to creating coding exercises*: https://teach.udemy.com/instructor-guide-coding-exercises/
- Udemy Developers, *Web Development Exercises*: https://www.udemy.com/developers/coding-exercises/web_development
