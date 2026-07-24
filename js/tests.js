/**
 * Declarative DOM test handlers for WebGrader (Phase 1).
 * runTests is async so optional html_validate can load from CDN.
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
        }
    };

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
     * Run all tests against a document (and optional HTML source).
     * @returns {Promise<object>}
     */
    function runTests(doc, exercise, options) {
        options = options || {};
        var V = global.WebGraderValidation;
        var HV = global.WebGraderHtmlValidate;
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

        var tests = Array.isArray(exercise.tests) ? exercise.tests : [];
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
                    configError = 'html_validate support is not loaded.';
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
                    return runNext();
                }
                var source = options.htmlSource;
                if (typeof source !== 'string') {
                    source = '';
                }
                return HV.validateHtmlSource(source, test).then(function (outcome) {
                    earned += pushResult(results, test, points, outcome, 'fail');
                }).catch(function (err) {
                    graderError = (err && err.message) ? err.message : String(err);
                    results.push({
                        id: test.id,
                        name: test.name || test.id,
                        pass: false,
                        points: points,
                        earned: 0,
                        kind: 'grader',
                        detail: 'HTML validator failed to run: ' + graderError,
                        feedback: ''
                    });
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
        handlers: handlers
    };
})(window);
