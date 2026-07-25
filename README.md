# WebGrader

Tsugi-based browser autograder for introductory HTML, CSS, JavaScript, and accessibility.

Learners edit source, press **Run / Restart** to preview in an iframe, optionally interact with the page, then press **Grade**. Scores use existing Tsugi attempt recording and LTI grade passback. Each placement stores one assignment JSON object in `lti_link.json`.

## What it does

- Edit → Run → Grade loop with dirty-state protection (Grade only after the current source has been Run)
- Declarative DOM and CSS tests, optional HTML/CSS validators, optional axe-core accessibility checks
- Partial credit (`earned / maximum`)
- Student source autosave (`student-save.php` + localStorage backup)
- Built-in assignment catalog under `assignments/` (HTML, CSS, A11Y, JavaScript)
- Instructor **Edit** mode for title, instructions, starters, solutions, and JSON import/export
- **Export to Udemy** for compatible exercises (starter / solution / Jasmine ZIP)

## Instructor setup

1. Add the WebGrader tool to a Tsugi course / LTI placement.
2. Open **Settings** and choose a built-in assignment (copies JSON into the placement).
3. Optionally open **Edit** to adjust title, rich-text instructions, starters, or import full JSON.
4. In **Learner** view, use **Load solution** (instructor only) when a reference `solution` is present.
5. Use **Student Data** for grades.

### Placing an assignment in `lessons.json`

Add an LTI item that launches WebGrader and selects a built-in via the `exercise` custom parameter. On first launch, that key is copied into the placement Settings when empty.

```json
{
    "type": "lti",
    "title": "Auto-grader: Headings and Paragraphs",
    "launch": "{apphome}/mod/webgrader/",
    "resource_link_id": "dj4e_webgrader_headings",
    "target": "_blank",
    "custom": [
        { "key": "exercise", "value": "HeadingsAndParagraphs" }
    ]
}
```

- **`launch`**: `{apphome}/mod/webgrader/` (or your installed tool path).
- **`resource_link_id`**: stable unique id for this placement in the course.
- **`target`**: optional; `"_blank"` opens in a new window (common for autograders).
- **`custom` → `exercise`**: catalog key from the table below. Instructors can still change the assignment later under **Settings**.

| `exercise` value | Settings label |
|---|---|
| `HeadingsAndParagraphs` | HTML: Headings and Paragraphs |
| `LinksAndImages` | HTML: Links and Images |
| `SimpleList` | HTML: A Simple List |
| `ValidatedHtmlPage` | HTML: Validated HTML Page |
| `ColoringParagraphs` | CSS: Coloring Paragraphs |
| `FourCorners` | CSS: Four Corners |
| `PuttingTheCascade` | CSS: Putting the Cascade in CSS |
| `HighlightingWithSpan` | CSS: Highlighting with Span |
| `LinkStates` | CSS: Link States |
| `FixAccessibility` | A11Y: Fix Accessibility |
| `HelloWorld` | JavaScript: Hello World |
| `AddTwoAndSquare` | JavaScript: Add Two and Square |

Omit `custom` / `exercise` if the instructor will pick the assignment only from Settings after install.

## Learner workflow

1. Edit HTML (and other visible tabs).
2. **Run / Restart** — rebuilds the student iframe.
3. Interact with the preview if needed.
4. **Grade** — only enabled when source matches the last Run.
5. Partial credit is submitted as `earned / maximum` (0–1).

The **Console** panel shows `console.log` / warn / error output and failed resource loads from the preview. It clears on each Run.

## Assignment tests

Assignments declare tests in JSON. Common types include selector/text/attribute checks, computed styles, function calls, HTML/CSS validation, and accessibility checks. The grader runs `html_validate`, then `css_validate`, then `axe_validate` before other tests (results panel order matches), even if they appear later in the assignment JSON.

### HTML validation

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

Uses [html-validate](https://html-validate.org/) from a pinned CDN when needed. Prefer **`html-validate:recommended`** over `standard` so missing end tags (for example `</p>`) are caught. If the CDN/library fails, that test is credited automatically; student markup errors still fail normally.

### CSS validation

```json
{
  "id": "css-valid",
  "name": "CSS validates",
  "type": "css_validate",
  "mode": "recommended",
  "points": 2,
  "feedback": "Fix the CSS validation errors shown in the test details."
}
```

Uses [css-tree](https://github.com/csstree/csstree) from a pinned CDN when needed.

- **`recommended`** (default): braces, parse errors, and property/value checks.
- **`syntax`**: braces + parse only.

CDN/library failure credits the test automatically.

### CSS rule checks (link pseudo-classes)

`:visited` / `:hover` / `:active` cannot be graded reliably from computed style. Use `"type": "css_rule_declares"` to require a selector/property/value in the student CSS source:

```json
{
  "id": "hover-green",
  "name": "a:hover is green",
  "type": "css_rule_declares",
  "selector": "a:hover",
  "property": "color",
  "expected": "green",
  "points": 2
}
```

### Accessibility checks (axe-core)

```json
{
  "id": "a11y-axe",
  "name": "Accessibility checks pass",
  "type": "axe_validate",
  "runOnly": ["html-has-lang", "image-alt", "label", "button-name"],
  "points": 4,
  "feedback": "Fix the accessibility issues listed in the test details."
}
```

Runs [axe-core](https://github.com/dequelabs/axe-core) against the live preview iframe after Run.

- **`runOnly`**: axe rule ids (recommended for teaching) or an axe `runOnly` object.
- **`tags`**: optional tag list (e.g. `wcag2a`) when you do not pin individual rules.
- **`impact`**: optional minimum impact (`moderate`, `serious`, `critical`).

CDN/library failure credits the test automatically. Built-in example: **A11Y: Fix Accessibility** (`FixAccessibility`).

## Export to Udemy

In **Edit** mode, click **Export to Udemy** to preview compatibility and download a ZIP for manual paste into a Udemy Web Development coding exercise. The package includes `starter.html`, `solution.html`, Jasmine `evaluation.js`, instructions, `manifest.json`, and `COMPATIBILITY.md`.

CLI (optional):

```bash
php scripts/export-udemy.php \
  assignments/html/simple-list/assignment.json \
  --output simple-list-udemy.zip
```

WebGrader-only checks (`html_validate`, `css_validate`, `axe_validate`, and similar) are skipped with explicit warnings. Details: [UDEMY_EXPORT.md](UDEMY_EXPORT.md).

Design notes for contributors: [DESIGN.md](DESIGN.md).

## Security limitations

- Not a secure boundary against a determined learner (same-origin preview).
- Synchronous infinite loops in student JavaScript can freeze the tab; use Run / Restart after fixing the code.
- Automated accessibility checks catch common issues, not full WCAG compliance.
- Do not claim screenshot-perfect visual grading.

## License

Apache License 2.0 — see [LICENSE](LICENSE).
