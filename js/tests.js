/**
 * Declarative DOM test handlers for WebGrader (Phase 1).
 * runTests is async so optional html_validate / css_validate / axe_validate can load from CDN.
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
            // CSS properties come from getComputedStyle, never from
            // getBoundingClientRect() / rendered geometry (transforms scale those).
            var actual = win.getComputedStyle(el).getPropertyValue(prop).trim();
            var expected = String(test.expected).trim();
            logEffectiveZoom(doc, el);
            var pass = computedValuesEqual(doc, prop, actual, expected, el);
            return {
                pass: pass,
                detail: 'Got "' + actual + '", expected "' + expected + '"'
                    + transformNote(el, win)
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
            logEffectiveZoom(doc, el);
            var mismatches = [];
            props.forEach(function (prop) {
                var actual = cs.getPropertyValue(prop).trim();
                var expected = String(test.expected[prop]).trim();
                if (!computedValuesEqual(doc, prop, actual, expected, el)) {
                    mismatches.push(prop + ': got "' + actual + '", expected "' + expected + '"');
                }
            });
            var note = transformNote(el, win);
            if (!mismatches.length) {
                return {
                    pass: true,
                    detail: 'Matched ' + props.join(', ') + note
                };
            }
            return {
                pass: false,
                detail: mismatches.join('; ') + note
            };
        },
        /**
         * Pass if any captured console entry matches expected text.
         * String args are stored JSON-quoted; we compare against the logical text.
         * Optional test.level (e.g. "log") filters by level; omit to accept any.
         * Optional test.match: "equals" (default) or "contains".
         */
        console_includes: function (doc, test) {
            var Console = global.WebGraderConsole;
            if (!Console || typeof Console.getEntries !== 'function') {
                return {
                    pass: false,
                    detail: 'Console capture is not available.'
                };
            }
            if (typeof test.expected === 'undefined') {
                var err = new Error('console_includes requires expected');
                err.code = 'config';
                throw err;
            }
            var expected = String(test.expected);
            var matchMode = test.match === 'contains' ? 'contains' : 'equals';
            var levelFilter = test.level ? String(test.level) : null;
            var entries = Console.getEntries() || [];
            if (!entries.length) {
                return {
                    pass: false,
                    detail: 'No console output yet. Press Run / Restart first.'
                };
            }

            var matched = entries.some(function (entry) {
                if (!entry) return false;
                if (levelFilter && entry.level !== levelFilter) return false;
                var text = consoleEntryText(entry.message);
                if (matchMode === 'contains') {
                    return text.indexOf(expected) !== -1
                        || String(entry.message || '').indexOf(expected) !== -1;
                }
                return text === expected
                    || String(entry.message || '') === expected
                    || String(entry.message || '') === JSON.stringify(expected);
            });

            if (matched) {
                return {
                    pass: true,
                    detail: matchMode === 'contains'
                        ? 'Console contains "' + expected + '"'
                        : 'Found console output "' + expected + '"'
                };
            }

            var sample = entries.slice(0, 5).map(function (e) {
                return (e.level || '?') + ': ' + consoleEntryText(e.message);
            }).join(' | ');
            return {
                pass: false,
                detail: 'Expected console '
                    + (levelFilter ? (levelFilter + ' ') : '')
                    + (matchMode === 'contains' ? 'to contain' : 'message')
                    + ' "' + expected + '". Saw: ' + (sample || '(empty)')
            };
        },
        /**
         * Call a global function in the student iframe with random ints.
         * test.name — function name
         * test.arg_count — number of random args (default 1)
         * test.random_min / test.random_max — inclusive range (default 2–10)
         * test.trials — how many random checks (default 3)
         * test.expect_op — "sum" | "square"
         */
        call_function: function (doc, test) {
            var win = doc && doc.defaultView;
            if (!win) {
                return { pass: false, detail: 'Student window is not available. Press Run first.' };
            }
            var fnName = test.function || test.name;
            if (!fnName || typeof fnName !== 'string') {
                var err = new Error('call_function requires name');
                err.code = 'config';
                throw err;
            }
            var fn = win[fnName];
            if (typeof fn !== 'function') {
                return {
                    pass: false,
                    detail: '"' + fnName + '" is not a global function. '
                        + 'Use a function declaration like: function ' + fnName + '(...) { ... }'
                };
            }

            var min = typeof test.random_min === 'number' ? test.random_min : 2;
            var max = typeof test.random_max === 'number' ? test.random_max : 10;
            if (max < min) {
                var err2 = new Error('call_function random_max must be >= random_min');
                err2.code = 'config';
                throw err2;
            }
            var arity = typeof test.arg_count === 'number' ? test.arg_count : 1;
            var trials = typeof test.trials === 'number' ? test.trials : 3;
            var op = String(test.expect_op || '');
            if (op !== 'sum' && op !== 'square') {
                var err3 = new Error('call_function expect_op must be "sum" or "square"');
                err3.code = 'config';
                throw err3;
            }

            var details = [];
            for (var t = 0; t < trials; t++) {
                var args = [];
                for (var i = 0; i < arity; i++) {
                    args.push(randomIntInclusive(min, max));
                }
                var expected = expectedFromOp(op, args);
                var actual;
                try {
                    actual = fn.apply(win, args);
                } catch (e) {
                    return {
                        pass: false,
                        detail: fnName + '(' + args.join(', ') + ') threw: '
                            + ((e && e.message) ? e.message : String(e))
                    };
                }
                if (actual !== expected) {
                    return {
                        pass: false,
                        detail: fnName + '(' + args.join(', ') + ') returned '
                            + stringifyCallResult(actual) + ', expected '
                            + stringifyCallResult(expected)
                    };
                }
                details.push(fnName + '(' + args.join(', ') + ') → ' + expected);
            }
            return {
                pass: true,
                detail: 'Passed ' + trials + ' trial(s): ' + details.join('; ')
            };
        }
    };

    function randomIntInclusive(min, max) {
        return Math.floor(Math.random() * (max - min + 1)) + min;
    }

    function expectedFromOp(op, args) {
        if (op === 'sum') {
            return args.reduce(function (a, b) { return a + b; }, 0);
        }
        if (op === 'square') {
            return args[0] * args[0];
        }
        return undefined;
    }

    function stringifyCallResult(v) {
        if (typeof v === 'string') return JSON.stringify(v);
        if (v === undefined) return 'undefined';
        if (typeof v === 'number' && isNaN(v)) return 'NaN';
        return String(v);
    }

    /** Undo JSON.stringify quoting used when capturing string console args. */
    function consoleEntryText(message) {
        var raw = String(message == null ? '' : message);
        try {
            var parsed = JSON.parse(raw);
            if (typeof parsed === 'string') return parsed;
        } catch (e) { /* not a JSON string literal */ }
        return raw;
    }

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

    function isTransformProperty(prop) {
        return String(prop || '').toLowerCase() === 'transform';
    }

    /** Treat bare 0 the same as 0px for corner offsets. */
    function normalizeCssOffset(value) {
        var v = String(value || '').trim().toLowerCase();
        if (v === '0') return '0px';
        return v;
    }

    /**
     * Numeric CSS lengths (border-width, etc.) compare with a small epsilon.
     * Do not scale these values by any CSS transform — getComputedStyle is
     * authoritative. Returns null when either side is not a CSS number.
     */
    function cssNumericEquals(actual, expected, epsilon) {
        epsilon = epsilon == null ? 0.01 : epsilon;
        var aStr = String(actual == null ? '' : actual).trim();
        var eStr = String(expected == null ? '' : expected).trim();
        if (!/^[-+]?\d/.test(aStr) || !/^[-+]?\d/.test(eStr)) return null;
        var aNum = parseFloat(aStr);
        var eNum = parseFloat(eStr);
        if (!isFinite(aNum) || !isFinite(eNum)) return null;
        var aUnit = aStr.replace(/^[-+]?\d*\.?\d+(e[-+]?\d+)?/i, '').trim().toLowerCase();
        var eUnit = eStr.replace(/^[-+]?\d*\.?\d+(e[-+]?\d+)?/i, '').trim().toLowerCase();
        if (aUnit === '' && eUnit === 'px') aUnit = 'px';
        if (eUnit === '' && aUnit === 'px') eUnit = 'px';
        if (aUnit !== eUnit) return false;
        return Math.abs(aNum - eNum) < epsilon;
    }

    function parseDomMatrix(transform, win) {
        if (!transform || transform === 'none') return null;
        var DM = (win && win.DOMMatrix)
            || (typeof DOMMatrix === 'function' ? DOMMatrix : null);
        if (!DM) return null;
        try {
            return new DM(transform);
        } catch (e) {
            return null;
        }
    }

    function matricesEqual(a, b, epsilon) {
        epsilon = epsilon == null ? 0.01 : epsilon;
        if (!a || !b) return false;
        return Math.abs(a.a - b.a) < epsilon
            && Math.abs(a.b - b.b) < epsilon
            && Math.abs(a.c - b.c) < epsilon
            && Math.abs(a.d - b.d) < epsilon
            && Math.abs(a.e - b.e) < epsilon
            && Math.abs(a.f - b.f) < epsilon;
    }

    /**
     * Walk from the target up through ancestors and return the first
     * non-none CSS transform, individual scale, or non-1 zoom.
     * Parsed with DOMMatrix when available.
     * Detection is diagnostic only — it does not change CSS-property grades.
     */
    function findTransform(element, win) {
        var el = element;
        var doc = el && el.ownerDocument;
        var view = win || (doc && doc.defaultView);
        if (!el || !view || !view.getComputedStyle) return null;

        while (el && el.nodeType === 1) {
            var style = view.getComputedStyle(el);
            var transform = style && style.transform;
            if (transform && transform !== 'none') {
                return {
                    element: el,
                    transform: transform,
                    matrix: parseDomMatrix(transform, view)
                };
            }
            var zoom = style && style.zoom;
            if (zoom && zoom !== 'normal') {
                var zNum = parseFloat(zoom);
                if (isFinite(zNum) && Math.abs(zNum - 1) > 0.001 && Math.abs(zNum - 100) > 0.001) {
                    return {
                        element: el,
                        transform: 'zoom(' + zoom + ')',
                        matrix: null,
                        zoom: zoom
                    };
                }
            }
            var scale = style && style.scale;
            if (scale && scale !== 'none') {
                return {
                    element: el,
                    transform: 'scale(' + scale + ')',
                    matrix: parseDomMatrix('scale(' + scale + ')', view)
                };
            }
            el = el.parentElement;
        }
        return null;
    }

    function describeElement(el) {
        if (!el) return 'element';
        var tag = (el.tagName || 'element').toLowerCase();
        if (el.id) return tag + '#' + el.id;
        var cls = '';
        if (typeof el.className === 'string' && el.className.trim()) {
            cls = '.' + el.className.trim().split(/\s+/)[0];
        }
        return tag + cls;
    }

    function transformNote(element, win) {
        var info = findTransform(element, win);
        if (!info) return '';
        var kind = info.zoom ? 'zoom' : 'transform';
        var value = info.zoom || info.transform;
        return ' [' + kind + ' on ' + describeElement(info.element)
            + ': ' + value + ']';
    }

    /**
     * Used-px / specified-px in the same subtree the grader compares against.
     */
    function measureEffectiveZoom(doc, contextEl) {
        var specified = 100;
        var resolved = resolveCssValue(doc, contextEl, 'border-top-width', specified + 'px');
        var used = parseFloat(resolved);
        if (!isFinite(used) || specified === 0) return 1;
        return used / specified;
    }

    function logEffectiveZoom(doc, contextEl) {
        var zoom = measureEffectiveZoom(doc, contextEl);
        var msg = 'WebGrader effective zoom: ' + zoom;
        try {
            console.log(msg);
        } catch (e) { /* ignore */ }
        try {
            if (global.WebGraderConsole && typeof global.WebGraderConsole.append === 'function') {
                global.WebGraderConsole.append({ level: 'log', message: msg });
            }
        } catch (e2) { /* ignore */ }
        return zoom;
    }

    /**
     * Resolve an expected CSS value in the target's document/subtree so
     * browser zoom and ancestor CSS zoom apply equally to both sides.
     * Does not multiply/divide by a transform matrix.
     */
    function resolveCssValue(doc, contextEl, prop, value) {
        var raw = String(value == null ? '' : value).trim();
        var win = doc && doc.defaultView;
        if (!raw || !doc || !doc.createElement || !win || !win.getComputedStyle) {
            return raw;
        }
        var parent = contextEl && contextEl.nodeType === 1 ? contextEl : doc.body;
        if (!parent) return raw;
        var probe = doc.createElement('wg-probe');
        probe.setAttribute('aria-hidden', 'true');
        probe.style.cssText = 'position:absolute;left:-9999px;top:0;visibility:hidden;'
            + 'pointer-events:none;display:block;';
        probe.style.setProperty(prop, raw, 'important');
        var p = String(prop || '').toLowerCase();
        if (p.indexOf('border') !== -1 && p.indexOf('width') !== -1) {
            probe.style.setProperty('border-style', 'solid', 'important');
        }
        if (isOffsetProperty(prop)) {
            probe.style.setProperty('position', 'fixed', 'important');
        }
        parent.appendChild(probe);
        var resolved = win.getComputedStyle(probe).getPropertyValue(prop).trim();
        parent.removeChild(probe);
        return resolved || raw;
    }

    function transformValuesEqual(doc, actual, expected) {
        actual = String(actual || '').trim();
        expected = String(expected || '').trim();
        if (actual === expected) return true;
        var aNone = !actual || actual === 'none';
        var eNone = !expected || expected === 'none';
        if (aNone || eNone) return aNone && eNone;
        var win = doc && doc.defaultView;
        return matricesEqual(
            parseDomMatrix(actual, win),
            parseDomMatrix(expected, win)
        );
    }

    /**
     * Compare one computed CSS property. Lengths use a 0.01px epsilon after
     * resolving the expected value in the same document/subtree (so zoom
     * affects both sides). Transform is compared via DOMMatrix when asked.
     */
    function computedValuesEqual(doc, prop, actual, expected, contextEl) {
        actual = String(actual || '').trim();
        expected = String(expected || '').trim();
        if (isColorProperty(prop)) {
            return normalizeCssColor(doc, actual) === normalizeCssColor(doc, expected);
        }
        if (isTransformProperty(prop)) {
            return transformValuesEqual(doc, actual, expected);
        }
        if (isOffsetProperty(prop)) {
            actual = normalizeCssOffset(actual);
            expected = normalizeCssOffset(expected);
        }
        var resolved = resolveCssValue(doc, contextEl, prop, expected);
        if (isOffsetProperty(prop)) {
            resolved = normalizeCssOffset(resolved);
        }
        var numeric = cssNumericEquals(actual, resolved, 0.01);
        if (numeric !== null) return numeric;
        return actual === resolved || actual === expected;
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
     * Run html_validate / css_validate / axe_validate before behavioral tests
     * so feedback leads with syntax/validity/a11y issues. Relative order
     * within each group is preserved.
     */
    function orderTestsForGrading(tests) {
        var html = [];
        var css = [];
        var axe = [];
        var rest = [];
        tests.forEach(function (t) {
            if (!t || !t.type) {
                rest.push(t);
            } else if (t.type === 'html_validate') {
                html.push(t);
            } else if (t.type === 'css_validate') {
                css.push(t);
            } else if (t.type === 'axe_validate') {
                axe.push(t);
            } else {
                rest.push(t);
            }
        });
        return html.concat(css).concat(axe).concat(rest);
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
        var AV = global.WebGraderAxeValidate;
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

            if (test.type === 'axe_validate') {
                if (!AV || !AV.runAxe) {
                    earned += pushResult(results, test, points, {
                        pass: true,
                        detail: 'Accessibility checker unavailable — credited automatically.'
                    }, 'pass');
                    return runNext();
                }
                return AV.runAxe(doc, test).then(function (outcome) {
                    if (outcome && outcome.bypass) {
                        earned += pushResult(results, test, points, outcome, 'pass');
                    } else {
                        earned += pushResult(results, test, points, outcome, 'fail');
                    }
                }).catch(function (err) {
                    var reason = (err && err.message) ? err.message : String(err);
                    earned += pushResult(results, test, points, {
                        pass: true,
                        detail: 'Accessibility checker unavailable — credited automatically. (' + reason + ')'
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
        orderTestsForGrading: orderTestsForGrading,
        findTransform: findTransform,
        cssNumericEquals: cssNumericEquals,
        computedValuesEqual: computedValuesEqual
    };
})(window);
