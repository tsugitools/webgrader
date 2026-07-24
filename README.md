# WebGrader

Tsugi-based browser autograder for introductory HTML, CSS, and JavaScript.

Learners edit source, press **Run / Restart** to preview in an iframe, optionally interact with the page, then press **Grade**. Scores use existing Tsugi attempt recording and LTI grade passback. Assignments are one JSON object stored in `lti_link.json`.

## Status

Phase 0–1 implemented:

- Thin PHP shell mirroring DBGrader (`window.WEBGRADER` bootstrap)
- Learner edit → Run → Grade loop with dirty-state protection
- Declarative DOM tests and partial credit
- Student source autosave (`student-save.php` + localStorage backup)
- Built-in HTML assignments under `assignments/html/`

Later phases (CSS tests, console capture, mock `fetch`, rich authoring) are described in [DESIGN.md](DESIGN.md).

## Tool layout

```text
webgrader/
  index.php, register.php, tsugi.php
  save.php              # instructor assignment JSON → lti_link.json
  student-save.php      # learner source → lti_result.json
  exercise.php, assignments.php
  grades.php, grade-detail.php
  js/                   # validation, runtime, tests, UI
  css/webgrader.css
  assignments/html/…/assignment.json
```

## Instructor setup

1. Add the WebGrader tool to a Tsugi course / LTI placement.
2. Open **Settings** and choose a built-in assignment (copies JSON into the placement).
3. Optionally open **Edit** to adjust title, prompt, starters, or import full JSON.
4. In **Learner** view, use **Load solution** (instructor only) when a reference `solution` is present.
5. Use **Student Data** for grades.

LTI custom parameter (optional):

```json
{ "key": "exercise", "value": "HeadingsAndParagraphs" }
```

Catalog keys: `HeadingsAndParagraphs`, `LinksAndImages`, `SimpleList`, `Dj4eValidatedPage`.

## Learner workflow

1. Edit HTML (and other visible tabs).
2. **Run / Restart** — rebuilds the student iframe.
3. Interact with the preview if needed.
4. **Grade** — only enabled when source matches the last Run.
5. Partial credit is submitted as `earned / maximum` (0–1).

### Optional HTML validation

Assignments may include a test with `"type": "html_validate"`. That loads [html-validate](https://html-validate.org/) from a pinned CDN ES module (`esm.sh`) only when needed — no `node_modules` in this tool.

```json
{
  "id": "html-valid",
  "name": "HTML validates",
  "type": "html_validate",
  "preset": "html-validate:recommended",
  "points": 1,
  "feedback": "Fix the HTML validation errors shown in the test details."
}
```

`html-validate:standard` follows HTML5 optional end tags (a missing `</p>` can still be “valid”). Prefer **`html-validate:recommended`**, which includes `no-implicit-close` and catches that common learner mistake.

## Locked Phase 1 decisions

| Topic | Choice |
|-------|--------|
| Iframe | Same-origin, `sandbox="allow-scripts allow-same-origin allow-popups"` |
| Max score | Explicit `grading.maximum_points`, else sum of test points |
| Prompt | Trusted HTML |
| Editor | Plain textareas (CodeMirror later) |
| Autosave | `student-save.php` + localStorage backup |
| Catalog | Directory + `assignment.json` under `assignments/` |

## Security limitations

- Not a secure boundary against a determined learner (same-origin preview).
- Synchronous infinite loops in student JS can freeze the tab; documented, not “solved.”
- Do not claim screenshot-perfect or WCAG-complete grading.

## License

Apache License 2.0 — see [LICENSE](LICENSE).
