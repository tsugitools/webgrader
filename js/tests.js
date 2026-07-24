/**
 * Declarative DOM test handlers for WebGrader (Phase 1).
 * runTests is async so optional html_validate / css_validate can load from CDN.
 */
(function (global) {
    'use strict';

    function textOf(el) {
        if (!el) return '';
        return (el.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function first(doc, selector) {
        try {
            return doc.querySelector(selector);
        } catch (e) {
            var err = new Error('Invalid selector: ' + selector);
            err.code = 'config';
            throw err;
        }
    }

    function all(doc, selector) {
        try {
            return doc.querySelectorAll(selector);
        } catch (e) {
            var err = new Error('Invalid selector: ' + selector);
            err.code = 'config';
            throw err;
        }
    }

    var handlers = {
        selector_exists: function (doc, test) {
            var el = first(doc, test.selector);
            return {
                pass: !!el,
                detail: el ? 'Found ' + test.selector : 'No match for ' + test.selector
            };
        },
        selector_not_exists: function (doc, test) {
            var el = first(doc, test.selector);
            return {
                pass: !el,
                detail: el ? 'Unexpected match for ' + test.selector : 'Correctly absent'
            };
        },
        selector_count: function (doc, test) {
            var nodes = all(doc, test.selector);
            var count = nodes.length;
            var expected = Number(test.expected);
            return {
                pass: count === expected,
                detail: 'Found ' + count + ', expected ' + expected
            };
        },
        text_equals: function (doc, test) {
            var el = first(doc, test.selector);
            if (!el) {
                return { pass: false, detail: 'No match for ' + test.selector };
            }
            var actual = textOf(el);
            var expected = String(test.expected).replace(/\s+/g, ' ').trim();
            return {
                pass: actual === expected,
                detail: 'Got "' + actual + '", expected "' + expected + '"'
            };
        },
        text_contains: function (doc, test) {
            var el = first(doc, test.selector);
            if (!el) {
                return { pass: false, detail: 'No match for ' + test.selector };
            }
            var actual = textOf(el);
            var needle = String(test.expected);
            return {
                pass: actual.indexOf(needle) !== -1,
                detail: actual.indexOf(needle) !== -1
                    ? 'Contains "' + needle + '"'
                    : 'Text does not contain "' + needle + '"'
            };
        },
        attribute_equals: function (doc, test) {
            var el = first(doc, test.selector);
            if (!el) {
                return { pass: false, detail: 'No match for ' + test.selector };
            }
            var actual = el.getAttribute(test.attribute);
            if (actual === null) {
                return { pass: false, detail: 'Attribute ' + test.attribute + ' is missing' };
            }
            var expected = String(test.expected);
            return {
                pass: actual === expected,
                detail: 'Got "' + actual + '", expected "' + expected + '"'
            };
        },
        attribute_exists: function (doc, test) {
            var el = first(doc, test.selector);
            if (!el) {
                return { pass: false, detail: 'No match for ' + test.selector };
            }
            var has = el.hasAttribute(test.attribute);
            return {
                pass: has,
                detail: has
                    ? 'Attribute ' + test.attribute + ' is present'
                    : 'Attribute ' + test.attribute + ' is missing'
            };
        },
        computed_style_equals: function (doc, test) {
            var el = first(doc, test.selector);
            if (!el) {
                return { pass: false, detail: 'No match for ' + test.selector };
            }
            if (!test.property || typeof test.property !== 'string') {
                var err = new Error('computed_style_equals requires property');
                err.code = 'config';
                throw err;
            }
            if (typeof test.expected === 'undefined') {
                var err2 = new Error('computed_style_equals requires expected');
                err2.code = 'config';
                throw err2;
            }
            var win = doc.defaultView;
            if (!win || !win.getComputedStyle) {
                return { pass: false, detail: 'Cannot read computed style (no window)' };
            }
            var prop = String(test.property).trim();
            var actual = win.getComputedStyle(el).getPropertyValue(prop).trim();
            var expected = String(test.expected).trim();
            if (isColorProperty(prop)) {
                actual = normalizeCssColor(doc, actual);
                expected = normalizeCssColor(doc, expected);
            } else if (isOffsetProperty(prop)) {
                actual = normalizeCssOffset(actual);
                expected = normalizeCssOffset(expected);
            }
            return {
                pass: actual === expected,
                detail: 'Got "' + actual + '", expected "' + expected + '"'
            };
        },
        /**
         * Compare several computed properties on one element.
         * test.expected is an object: { "position": "fixed", "top": "0", ... }
         */
        computed_styles_equals: function (doc, test) {
            var el = first(doc, test.selector);
            if (!el) {
                return { pass: false, detail: 'No match for ' + test.selector };
            }
            if (!test.expected || typeof test.expected !== 'object' || Array.isArray(test.expected)) {
                var err = new Error('computed_styles_equals requires expected object');
                err.code = 'config';
                throw err;
            }
            var win = doc.defaultView;
            if (!win || !win.getComputedStyle) {
                return { pass: false, detail: 'Cannot read computed style (no window)' };
            }
            var cs = win.getComputedStyle(el);
            var props = Object.keys(test.expected);
            if (!props.length) {
                var err2 = new Error('computed_styles_equals expected object is empty');
                err2.code = 'config';
                throw err2;
            }
            var mismatches = [];
            props.forEach(function (prop) {
                var actual = cs.getPropertyValue(prop).trim();
                var expected = String(test.expected[prop]).trim();
                if (isColorProperty(prop)) {
                    actual = normalizeCssColor(doc, actual);
                    expected = normalizeCssColor(doc, expected);
                } else if (isOffsetProperty(prop)) {
                    actual = normalizeCssOffset(actual);
                    expected = normalizeCssOffset(expected);
                }
                if (actual !== expected) {
                    mismatches.push(prop + ': got "' + actual + '", expected "' + expected + '"');
                }
            });
            if (!mismatches.length) {
                return {
                    pass: true,
                    detail: 'Matched ' + props.join(', ')
                };
            }
            return {
                pass: false,
                detail: mismatches.join('; ')
            };
        }
    };

    function isColorProperty(prop) {
        var p = String(prop || '').toLowerCase();
        return p === 'color'
            || p.indexOf('color') !== -1
            || p === 'fill'
            || p === 'stroke';
    }

    function isOffsetProperty(prop) {
        var p = String(prop || '').toLowerCase();
        return p === 'top' || p === 'right' || p === 'bottom' || p === 'left';
    }

    /** Treat bare 0 the same as 0px for corner offsets. */
    function normalizeCssOffset(value) {
        var v = String(value || '').trim().toLowerCase();
        if (v === '0') return '0px';
        return v;
    }

    /**
     * Resolve any CSS color (name, hex, rgb) to the browser's computed rgb/rgba form.
     */
    function normalizeCssColor(doc, value) {
        var raw = String(value || '').trim();
        if (!raw) return raw;
        var win = doc.defaultView;
        if (!win || !doc.body) return raw;
        var probe = doc.createElement('div');
        probe.style.backgroundColor = raw;
        doc.body.appendChild(probe);
        var resolved = win.getComputedStyle(probe).backgroundColor;
        doc.body.removeChild(probe);
        return (resolved || raw).trim();
    }

    function pushResult(results, test, points, outcome, kinds) {
        var pass = !!outcome.pass;
        var kind = pass ? 'pass' : (kinds || 'fail');
        results.push({
            id: test.id,
            name: test.name || test.id,
            pass: pass,
            points: points,
            earned: pass ? points : 0,
            kind: kind,
            detail: outcome.detail || '',
            feedback: pass ? '' : (test.feedback || '')
        });
        return pass ? points : 0;
    }

    /**
     * Run html_validate / css_validate before behavioral tests so feedback
     * leads with syntax/validity issues. Relative order within each group
     * is preserved.
     */
    function orderTestsForGrading(tests) {
        var html = [];
        var css = [];
        var rest = [];
        tests.forEach(function (t) {
            if (!t || !t.type) {
                rest.push(t);
            } else if (t.type === 'html_validate') {
                html.push(t);
            } else if (t.type === 'css_validate') {
                css.push(t);
            } else {
                rest.push(t);
            }
        });
        return html.concat(css).concat(rest);
    }

    /**
     * Run all tests against a document (and optional HTML source).
     * @returns {Promise<object>}
     */
    function runTests(doc, exercise, options) {
        options = options || {};
        var V = global.WebGraderValidation;
        var HV = global.WebGraderHtmlValidate;
        var CV = global.WebGraderCssValidate;
        var maximum = V ? V.maximumPoints(exercise) : 0;
        var results = [];
        var earned = 0;
        var configError = null;
        var graderError = null;

        if (!doc) {
            return Promise.resolve({
                results: [],
                earned: 0,
                maximum: maximum,
                grade: 0,
                configError: null,
                graderError: 'No student document is available. Press Run first.'
            });
        }

        var tests = orderTestsForGrading(
            Array.isArray(exercise.tests) ? exercise.tests : []
        );
        var i = 0;

        function finish() {
            var grade = maximum > 0 ? (earned / maximum) : 0;
            if (grade > 1) grade = 1;
            if (grade < 0) grade = 0;
            return {
                results: results,
                earned: earned,
                maximum: maximum,
                grade: grade,
                configError: configError,
                graderError: graderError
            };
        }

        function runNext() {
            if (i >= tests.length) {
                return Promise.resolve(finish());
            }
            var test = tests[i++];
            var points = typeof test.points === 'number' ? test.points : 0;

            if (test.type === 'html_validate') {
                if (!HV || !HV.validateHtmlSource) {
                    earned += pushResult(results, test, points, {
                        pass: true,
                        detail: 'HTML validator unavailable — credited automatically.'
                    }, 'pass');
                    return runNext();
                }
                var source = options.htmlSource;
                if (typeof source !== 'string') {
                    source = '';
                }
                return HV.validateHtmlSource(source, test).then(function (outcome) {
                    if (outcome && outcome.bypass) {
                        earned += pushResult(results, test, points, outcome, 'pass');
                    } else {
                        earned += pushResult(results, test, points, outcome, 'fail');
                    }
                }).catch(function (err) {
                    // CDN / library failure — do not penalize the student.
                    var reason = (err && err.message) ? err.message : String(err);
                    earned += pushResult(results, test, points, {
                        pass: true,
                        detail: 'HTML validator unavailable — credited automatically. (' + reason + ')'
                    }, 'pass');
                }).then(runNext);
            }

            if (test.type === 'css_validate') {
                if (!CV || !CV.validateCssSource) {
                    earned += pushResult(results, test, points, {
                        pass: true,
                        detail: 'CSS validator unavailable — credited automatically.'
                    }, 'pass');
                    return runNext();
                }
                var cssSource = options.cssSource;
                if (typeof cssSource !== 'string') {
                    cssSource = '';
                }
                return CV.validateCssSource(cssSource, test).then(function (outcome) {
                    if (outcome && outcome.bypass) {
                        earned += pushResult(results, test, points, outcome, 'pass');
                    } else {
                        earned += pushResult(results, test, points, outcome, 'fail');
                    }
                }).catch(function (err) {
                    var reason = (err && err.message) ? err.message : String(err);
                    earned += pushResult(results, test, points, {
                        pass: true,
                        detail: 'CSS validator unavailable — credited automatically. (' + reason + ')'
                    }, 'pass');
                }).then(runNext);
            }

            if (test.type === 'css_rule_declares') {
                if (!CV || !CV.ruleDeclares) {
                    earned += pushResult(results, test, points, {
                        pass: true,
                        detail: 'CSS rule checker unavailable — credited automatically.'
                    }, 'pass');
                    return runNext();
                }
                var cssForRule = options.cssSource;
                if (typeof cssForRule !== 'string') {
                    cssForRule = '';
                }
                return CV.ruleDeclares(cssForRule, test, { doc: doc }).then(function (outcome) {
                    if (outcome && outcome.bypass) {
                        earned += pushResult(results, test, points, outcome, 'pass');
                    } else {
                        earned += pushResult(results, test, points, outcome, 'fail');
                    }
                }).catch(function (err) {
                    var reason = (err && err.message) ? err.message : String(err);
                    earned += pushResult(results, test, points, {
                        pass: true,
                        detail: 'CSS rule checker unavailable — credited automatically. (' + reason + ')'
                    }, 'pass');
                }).then(runNext);
            }

            var handler = handlers[test.type];
            if (!handler) {
                configError = 'Unsupported test type: ' + test.type;
                results.push({
                    id: test.id,
                    name: test.name || test.id,
                    pass: false,
                    points: points,
                    earned: 0,
                    kind: 'config',
                    detail: configError,
                    feedback: test.feedback || ''
                });
                return runNext();
            }

            try {
                var outcome = handler(doc, test);
                earned += pushResult(results, test, points, outcome, 'fail');
            } catch (e) {
                if (e && e.code === 'config') {
                    configError = e.message || String(e);
                    results.push({
                        id: test.id,
                        name: test.name || test.id,
                        pass: false,
                        points: points,
                        earned: 0,
                        kind: 'config',
                        detail: configError,
                        feedback: ''
                    });
                } else {
                    graderError = (e && e.message) ? e.message : String(e);
                    results.push({
                        id: test.id,
                        name: test.name || test.id,
                        pass: false,
                        points: points,
                        earned: 0,
                        kind: 'grader',
                        detail: graderError,
                        feedback: ''
                    });
                }
            }
            return runNext();
        }

        return runNext();
    }

    global.WebGraderTests = {
        runTests: runTests,
        handlers: handlers,
        orderTestsForGrading: orderTestsForGrading
    };
})(window);
