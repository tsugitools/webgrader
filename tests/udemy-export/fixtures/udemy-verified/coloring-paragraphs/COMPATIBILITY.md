# Udemy Export Compatibility

Overall status: Compatible with warnings

## Converted

- Starter HTML/CSS/JavaScript
- Reference solution
- Instructions
- Starter differs from solution (local CSS/JS checks skipped)
- 4 declarative tests

## Warnings

- HTML is read-only in WebGrader but may be editable in Udemy.
- WebGrader-only test "css-valid" (css_validate) was not exported to Udemy Jasmine.
- Computed-style / event tests were not verified locally (jsdom not installed (optional; npm i jsdom for local CSS checks)). Confirm them inside Udemy.

## Unsupported

- (none)

## Instructor Checklist

- Enter the generated files into a test Udemy coding exercise.
- Verify the solution passes every Jasmine test.
- Verify the starter fails the intended tests.
- Confirm asset paths.
- Preview the exercise as a learner.
