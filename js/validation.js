/**
 * Minimal WebGrader assignment validation (Phase 1).
 */
(function (global) {
    'use strict';

    var FILE_KEYS = ['html', 'css', 'javascript'];
    var FILE_MODES = { editable: true, readonly: true, hidden: true };
    var TEST_TYPES = {
        selector_exists: true,
        selector_not_exists: true,
        selector_count: true,
        text_equals: true,
        text_contains: true,
        attribute_equals: true,
        attribute_exists: true,
        html_validate: true,
        css_validate: true,
        css_rule_declares: true,
        axe_validate: true,
        computed_style_equals: true,
        computed_styles_equals: true,
        console_includes: true,
        call_function: true
    };

    function isObject(v) {
        return v && typeof v === 'object' && !Array.isArray(v);
    }

    function assetPathOk(path) {
        if (typeof path !== 'string' || !path) return false;
        if (path.indexOf('..') !== -1) return false;
        if (path.charAt(0) === '/' || /^[a-zA-Z]:/.test(path)) return false;
        if (path.indexOf('assignments/') !== 0) return false;
        return true;
    }

    /**
     * @returns {{ok: boolean, errors: string[]}}
     */
    function validateAssignment(exercise) {
        var errors = [];
        if (!isObject(exercise)) {
            return { ok: false, errors: ['Assignment is missing or not an object.'] };
        }
        if (exercise.type !== 'webgrader') {
            errors.push('type must be "webgrader".');
        }
        if (exercise.schema_version !== 1 && exercise.schema_version !== '1') {
            errors.push('Unsupported schema_version (expected 1).');
        }
        if (!exercise.id || typeof exercise.id !== 'string') {
            errors.push('Missing assignment id.');
        }
        if (!exercise.prompt || typeof exercise.prompt !== 'string') {
            errors.push('Missing prompt.');
        }
        if (!isObject(exercise.files)) {
            errors.push('Missing files object.');
        } else {
            var editableCount = 0;
            FILE_KEYS.forEach(function (key) {
                var f = exercise.files[key];
                if (!isObject(f)) {
                    errors.push('Missing files.' + key + '.');
                    return;
                }
                if (!FILE_MODES[f.mode]) {
                    errors.push('Invalid mode for files.' + key + ' (use editable, readonly, or hidden).');
                }
                if (typeof f.starter !== 'string') {
                    errors.push('files.' + key + '.starter must be a string.');
                }
                if (f.mode === 'editable') editableCount += 1;
            });
            if (editableCount < 1) {
                errors.push('At least one file must be editable.');
            }
        }

        if (!Array.isArray(exercise.tests)) {
            errors.push('tests must be an array.');
        } else {
            var seen = {};
            exercise.tests.forEach(function (t, i) {
                if (!isObject(t)) {
                    errors.push('tests[' + i + '] is not an object.');
                    return;
                }
                if (!t.id || typeof t.id !== 'string') {
                    errors.push('tests[' + i + '] missing id.');
                } else if (seen[t.id]) {
                    errors.push('Duplicate test id: ' + t.id);
                } else {
                    seen[t.id] = true;
                }
                if (!TEST_TYPES[t.type]) {
                    errors.push('Unsupported test type "' + t.type + '" on ' + (t.id || i) + '.');
                }
                if (typeof t.points !== 'number' || t.points < 0) {
                    errors.push('Test ' + (t.id || i) + ' points must be a non-negative number.');
                }
                if (t.type === 'selector_exists' || t.type === 'selector_not_exists'
                    || t.type === 'selector_count' || t.type === 'text_equals'
                    || t.type === 'text_contains' || t.type === 'attribute_equals'
                    || t.type === 'attribute_exists' || t.type === 'computed_style_equals'
                    || t.type === 'computed_styles_equals') {
                    if (!t.selector || typeof t.selector !== 'string') {
                        errors.push('Test ' + (t.id || i) + ' requires selector.');
                    }
                }
                if (t.type === 'html_validate' && t.preset != null && typeof t.preset !== 'string') {
                    errors.push('Test ' + (t.id || i) + ' preset must be a string when set.');
                }
                if (t.type === 'css_validate') {
                    if (t.mode != null && typeof t.mode !== 'string') {
                        errors.push('Test ' + (t.id || i) + ' mode must be a string when set.');
                    }
                    if (t.preset != null && typeof t.preset !== 'string') {
                        errors.push('Test ' + (t.id || i) + ' preset must be a string when set.');
                    }
                }
                if (t.type === 'css_rule_declares') {
                    if (!t.selector || typeof t.selector !== 'string') {
                        errors.push('Test ' + (t.id || i) + ' requires selector.');
                    }
                    if (!t.property || typeof t.property !== 'string') {
                        errors.push('Test ' + (t.id || i) + ' requires property.');
                    }
                    if (typeof t.expected === 'undefined') {
                        errors.push('Test ' + (t.id || i) + ' requires expected.');
                    }
                }
                if (t.type === 'call_function') {
                    if (!t.function || typeof t.function !== 'string') {
                        errors.push('Test ' + (t.id || i) + ' requires function.');
                    }
                    if (t.expect_op !== 'sum' && t.expect_op !== 'square') {
                        errors.push('Test ' + (t.id || i) + ' expect_op must be "sum" or "square".');
                    }
                }
                if (t.type === 'selector_count' || t.type === 'text_equals'
                    || t.type === 'text_contains' || t.type === 'attribute_equals'
                    || t.type === 'computed_style_equals' || t.type === 'console_includes') {
                    if (typeof t.expected === 'undefined') {
                        errors.push('Test ' + (t.id || i) + ' requires expected.');
                    }
                }
                if (t.type === 'computed_styles_equals') {
                    if (!t.expected || typeof t.expected !== 'object' || Array.isArray(t.expected)) {
                        errors.push('Test ' + (t.id || i) + ' requires expected object of CSS properties.');
                    }
                }
                if (t.type === 'attribute_equals' || t.type === 'attribute_exists') {
                    if (!t.attribute || typeof t.attribute !== 'string') {
                        errors.push('Test ' + (t.id || i) + ' requires attribute.');
                    }
                }
                if (t.type === 'computed_style_equals') {
                    if (!t.property || typeof t.property !== 'string') {
                        errors.push('Test ' + (t.id || i) + ' requires property.');
                    }
                }
            });
        }

        if (Array.isArray(exercise.assets)) {
            exercise.assets.forEach(function (a, i) {
                if (!isObject(a)) {
                    errors.push('assets[' + i + '] is not an object.');
                    return;
                }
                if (!assetPathOk(a.path)) {
                    errors.push('Invalid asset path (must stay under assignments/): '
                        + (a.path || '(missing)'));
                }
            });
        }

        return { ok: errors.length === 0, errors: errors };
    }

    /**
     * Maximum points: explicit grading.maximum_points, else sum of test points.
     */
    function maximumPoints(exercise) {
        if (isObject(exercise.grading) && typeof exercise.grading.maximum_points === 'number'
            && exercise.grading.maximum_points > 0) {
            return exercise.grading.maximum_points;
        }
        var sum = 0;
        if (Array.isArray(exercise.tests)) {
            exercise.tests.forEach(function (t) {
                if (t && typeof t.points === 'number') sum += t.points;
            });
        }
        return sum;
    }

    global.WebGraderValidation = {
        validateAssignment: validateAssignment,
        maximumPoints: maximumPoints,
        assetPathOk: assetPathOk,
        TEST_TYPES: TEST_TYPES
    };
})(window);
