# Udemy Web Development Coding Exercises — Observations

Last updated: 2026-07-24  
Status: Phase 1 and Phase 2 verified in live Udemy Web Development coding exercises

## Sources

- [Instructor guide to creating coding exercises](https://teach.udemy.com/instructor-guide-coding-exercises/)
- [How to Create a Coding Exercise](https://support.udemy.com/hc/en-us/articles/115002883587-How-to-Create-a-Coding-Exercise)
- Udemy Developers: Web Development Exercises (page referenced in design; often requires instructor/dev access)

## Authoring workflow (from public docs)

Udemy recommends backwards planning:

1. **Plan Exercise** — title, learning objective, skills practiced.
2. **Author Solution** — complete solution file plus evaluation (unit test) file; system verifies that the solution passes the evaluation.
3. **Guide learners** — problem statement / instructions, starter file, hints, related lecture, solution explanation.

Web Development exercises support HTML, CSS, JavaScript, and TypeScript. Public instructor material describes Jasmine as the Web Development testing framework.

## Files the exporter should produce

| WebGrader export member | Udemy authoring concept |
|---|---|
| `instructions.md` | Learning objective + problem statement |
| `starter.html` | Starter file shown to learners |
| `solution.html` | Solution file used to verify evaluation |
| `evaluation.js` | Jasmine evaluation / unit tests |
| `hints.md` | Hints (optional) |
| `solution-explanation.md` | Solution explanation (optional) |

Exact upload filenames and whether HTML/CSS/JS are separate panes may change in the instructor UI. Treat export filenames as reviewable conventions, not a permanent API contract.

## Verified assumptions (live Udemy)

### Phase 1 — HTML (2026-07-24)

Source: `assignments/html/simple-list/assignment.json`

1. **Confirmed:** Jasmine tests can inspect the learner page DOM via `document.querySelector` / `querySelectorAll`.
2. **Confirmed:** A single combined HTML document is acceptable as the starter and solution for simple HTML exercises.
3. **Confirmed:** Evaluation as a Jasmine suite (`describe` / `it` / `expect`) works.

### Phase 2 — CSS and JavaScript (2026-07-24)

4. **Confirmed:** `getComputedStyle` + color normalization helpers work for exported CSS exercises (e.g. `coloring-paragraphs`).
5. **Confirmed:** Global functions from a combined HTML `<script>` are reachable as `window["fn"]` for `call_function` exports (e.g. `add-two-and-square`).

### Still assumed

6. Weighted / unequal points are not guaranteed; preserve names and order, record points only as comments.
7. Assets (images, JSON) may require manual upload or path adjustment — not auto-compatible.
8. Mock `fetch` is not available unless we inject a prelude (Phase 3 experiment).

## Still to confirm in a live Udemy exercise

- [x] Jasmine accesses the learner page DOM (`document.querySelector` works).
- [x] Combined HTML starter / solution / Jasmine evaluation round-trip succeeds for a simple HTML exercise.
- [x] Computed-style Jasmine checks work for a CSS exercise.
- [x] Global function calls from combined HTML work for a JS exercise.
- [ ] Exact file pane layout for Web Development (one HTML file vs separate HTML/CSS/JS).
- [ ] Asynchronous test support (`done`, async/await, timeouts).
- [ ] Whether unequal test weights exist or all tests are equal.
- [ ] Asset hosting / relative URL behavior.
- [ ] Whether `html_validate`-style tooling exists (almost certainly not — keep WebGrader-only).

## Known-good manual fixtures

- `tests/udemy-export/fixtures/udemy-verified/simple-list/` — Phase 1 HTML (2026-07-24)
- `tests/udemy-export/fixtures/udemy-verified/coloring-paragraphs/` — Phase 2 CSS (2026-07-24)
- `tests/udemy-export/fixtures/udemy-verified/add-two-and-square/` — Phase 2 JS (2026-07-24)

## Implications for the exporter

- Prefer declarative DOM tests that map 1:1 to Jasmine `expect(document.querySelector(...))`.
- Soft-skip WebGrader-only validators (`html_validate`, `css_validate`, …) with explicit warnings; fail hard on unknown types and when nothing convertible remains.
- Ship a compatibility report with every package.
- Do not automate Udemy upload.

## Phase 2 catalog notes

Verified in Udemy: CSS computed-style packages and JS `call_function` packages via combined HTML.

Also exportable in CLI (same patterns): `four-corners`, `putting-the-cascade`, `highlighting-with-span`, `headings-and-paragraphs`.

Still unsupported for export: `link-states` (`css_rule_declares` only), `hello-world` (`console_includes` only), asset-backed assignments.
