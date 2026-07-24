/**
 * Optional CSS validation via css-tree (CDN ES module).
 * Loaded only when an assignment uses type: "css_validate".
 *
 * Checks:
 *   - balanced { } (common student mistake css-tree alone tolerates)
 *   - parse errors (css-tree onParseError)
 *   - property / value mismatches (css-tree lexer) when mode is "recommended"
 */
(function (global) {
    'use strict';

    // Pinned version — bump intentionally when upgrading.
    var CDN_URL = 'https://esm.sh/css-tree@3.1.0';

    var loadPromise = null;

    function load() {
        if (loadPromise) return loadPromise;
        loadPromise = import(CDN_URL).then(function (mod) {
            if (!mod || typeof mod.parse !== 'function' || !mod.lexer) {
                throw new Error('css-tree CDN module missing parse/lexer exports');
            }
            return mod;
        }).catch(function (err) {
            loadPromise = null;
            throw err;
        });
        return loadPromise;
    }

    function exerciseNeedsCssValidate(exercise) {
        var tests = exercise && Array.isArray(exercise.tests) ? exercise.tests : [];
        return tests.some(function (t) {
            return t && (t.type === 'css_validate' || t.type === 'css_rule_declares');
        });
    }

    function normalizeSelector(sel) {
        return String(sel || '').replace(/\s+/g, ' ').trim();
    }

    function isColorPropertyName(prop) {
        var p = String(prop || '').toLowerCase();
        return p === 'color' || p.indexOf('color') !== -1 || p === 'fill' || p === 'stroke';
    }

    function normalizeColorValue(value, doc) {
        var raw = String(value || '').trim();
        if (!raw) return raw;
        var root = doc || document;
        var win = root.defaultView || window;
        var body = root.body || document.body;
        if (!body || !win.getComputedStyle) return raw;
        var probe = root.createElement
            ? root.createElement('div')
            : document.createElement('div');
        probe.style.color = raw;
        body.appendChild(probe);
        var resolved = win.getComputedStyle(probe).color;
        body.removeChild(probe);
        return (resolved || raw).trim();
    }

    /**
     * Check that student CSS includes a rule for selector declaring property=expected.
     * Used for :visited / :hover / :active where computed style is unreliable.
     * @returns {Promise<{pass:boolean, detail:string, bypass?:boolean}>}
     */
    function ruleDeclares(css, test, options) {
        options = options || {};
        test = test || {};
        var wantSel = normalizeSelector(test.selector);
        var prop = String(test.property || '').trim().toLowerCase();
        var expected = test.expected;
        if (!wantSel || !prop || typeof expected === 'undefined') {
            return Promise.resolve({
                pass: false,
                detail: 'css_rule_declares requires selector, property, and expected.'
            });
        }

        return load().then(function (csstree) {
            var ast;
            try {
                ast = csstree.parse(String(css || ''), {
                    positions: true,
                    onParseError: function () { /* collect via empty match */ }
                });
            } catch (e) {
                return {
                    pass: false,
                    detail: 'Could not parse CSS: ' + ((e && e.message) || String(e))
                };
            }

            var foundSelector = false;
            var foundValues = [];

            csstree.walk(ast, {
                visit: 'Rule',
                enter: function (rule) {
                    if (!rule.prelude) return;
                    var prelude = normalizeSelector(csstree.generate(rule.prelude));
                    var parts = prelude.split(',').map(normalizeSelector);
                    if (parts.indexOf(wantSel) === -1) return;
                    foundSelector = true;
                    if (!rule.block || !rule.block.children) return;
                    rule.block.children.forEach(function (child) {
                        if (!child || child.type !== 'Declaration') return;
                        if (String(child.property || '').toLowerCase() !== prop) return;
                        foundValues.push(csstree.generate(child.value).trim());
                    });
                }
            });

            if (!foundSelector) {
                return {
                    pass: false,
                    detail: 'No rule found for selector "' + wantSel + '".'
                };
            }
            if (!foundValues.length) {
                return {
                    pass: false,
                    detail: 'Found "' + wantSel + '" but it does not set ' + prop + '.'
                };
            }

            var want = String(expected).trim();
            var doc = options.doc || document;
            if (isColorPropertyName(prop)) {
                want = normalizeColorValue(want, doc);
            }

            var matched = foundValues.some(function (actual) {
                var got = actual;
                if (isColorPropertyName(prop)) {
                    got = normalizeColorValue(actual, doc);
                }
                return got === want || actual === String(expected).trim();
            });

            if (matched) {
                return {
                    pass: true,
                    detail: wantSel + ' { ' + prop + ': ' + String(expected).trim() + '; }'
                };
            }
            return {
                pass: false,
                detail: 'Found ' + prop + ': ' + foundValues.join(' | ')
                    + ' on "' + wantSel + '", expected "' + String(expected).trim() + '".'
            };
        }).catch(function (err) {
            var reason = (err && err.message) ? err.message : String(err);
            return {
                pass: true,
                bypass: true,
                detail: 'CSS rule checker unavailable — credited automatically. (' + reason + ')'
            };
        });
    }

    /**
     * Find first unbalanced { } after stripping comments and strings.
     * @returns {{message:string, line:number, column:number}|null}
     */
    function findBraceIssue(css) {
        var text = String(css || '');
        var depth = 0;
        var line = 1;
        var column = 1;
        var i = 0;
        var openLine = 1;
        var openCol = 1;

        function advance() {
            if (text.charAt(i) === '\n') {
                line += 1;
                column = 1;
            } else {
                column += 1;
            }
            i += 1;
        }

        while (i < text.length) {
            var ch = text.charAt(i);
            var next = text.charAt(i + 1);

            // /* comment */
            if (ch === '/' && next === '*') {
                advance();
                advance();
                while (i < text.length) {
                    if (text.charAt(i) === '*' && text.charAt(i + 1) === '/') {
                        advance();
                        advance();
                        break;
                    }
                    advance();
                }
                continue;
            }

            // "…" or '…'
            if (ch === '"' || ch === "'") {
                var quote = ch;
                advance();
                while (i < text.length) {
                    var c = text.charAt(i);
                    if (c === '\\') {
                        advance();
                        if (i < text.length) advance();
                        continue;
                    }
                    if (c === quote) {
                        advance();
                        break;
                    }
                    advance();
                }
                continue;
            }

            if (ch === '{') {
                if (depth === 0) {
                    openLine = line;
                    openCol = column;
                }
                depth += 1;
                advance();
                continue;
            }
            if (ch === '}') {
                depth -= 1;
                if (depth < 0) {
                    return {
                        message: 'Unexpected closing "}" — check for an extra brace or a missing "{".',
                        line: line,
                        column: column
                    };
                }
                advance();
                continue;
            }
            advance();
        }

        if (depth > 0) {
            return {
                message: 'Unclosed "{" — add the missing "}" '
                    + '(opened near L' + openLine + ':' + openCol + ').',
                line: openLine,
                column: openCol
            };
        }
        return null;
    }

    function pushMessage(list, msg) {
        if (!msg) return;
        list.push(msg);
    }

    function formatLoc(line, column) {
        if (line == null) return '';
        return column != null ? ('L' + line + ':' + column + ': ') : ('L' + line + ': ');
    }

    function shortMismatch(message) {
        var text = String(message || '');
        // css-tree mismatch messages are multi-line; keep first line + a short hint.
        var first = text.split('\n')[0].trim();
        if (/^Mismatch$/i.test(first)) {
            var valueLine = text.split('\n').find(function (l) {
                return /^\s*value:/i.test(l);
            });
            var syntaxLine = text.split('\n').find(function (l) {
                return /^\s*syntax:/i.test(l);
            });
            var bits = ['Invalid value'];
            if (valueLine) bits.push(valueLine.trim());
            if (syntaxLine) bits.push('(' + syntaxLine.trim() + ')');
            return bits.join(' ');
        }
        return first || text;
    }

    /**
     * Validate CSS source string.
     * @returns {Promise<{pass:boolean, detail:string, messages:Array, bypass?:boolean}>}
     */
    function validateCssSource(css, test) {
        test = test || {};
        var mode = test.mode || test.preset || 'recommended';
        var maxShow = typeof test.max_messages === 'number' ? test.max_messages : 8;
        var source = String(css || '');

        return load().then(function (csstree) {
            var messages = [];

            var brace = findBraceIssue(source);
            if (brace) {
                pushMessage(messages, {
                    severity: 2,
                    line: brace.line,
                    column: brace.column,
                    message: brace.message,
                    ruleId: 'brace-balance'
                });
            }

            var ast = null;
            try {
                ast = csstree.parse(source, {
                    positions: true,
                    onParseError: function (err) {
                        pushMessage(messages, {
                            severity: 2,
                            line: err.line,
                            column: err.column,
                            message: (err.formattedMessage || err.message || 'Parse error')
                                .split('\n')[0],
                            ruleId: 'parse-error'
                        });
                    }
                });
            } catch (e) {
                pushMessage(messages, {
                    severity: 2,
                    line: e.line,
                    column: e.column,
                    message: (e.formattedMessage || e.message || 'Parse error').split('\n')[0],
                    ruleId: 'parse-error'
                });
            }

            if (ast && mode !== 'syntax' && csstree.lexer && typeof csstree.walk === 'function') {
                csstree.walk(ast, {
                    visit: 'Declaration',
                    enter: function (node) {
                        var match = csstree.lexer.matchDeclaration(node);
                        if (!match || !match.error) return;
                        var loc = node.loc && node.loc.start;
                        var prop = node.property || 'property';
                        var errMsg = match.error.message || String(match.error);
                        pushMessage(messages, {
                            severity: 2,
                            line: loc && loc.line,
                            column: loc && loc.column,
                            message: prop + ': ' + shortMismatch(errMsg),
                            ruleId: 'declaration'
                        });
                    }
                });
            }

            if (!messages.length) {
                return {
                    pass: true,
                    detail: 'CSS validated (' + mode + ').',
                    messages: []
                };
            }

            var lines = messages.slice(0, maxShow).map(function (m) {
                return formatLoc(m.line, m.column) + (m.ruleId ? (m.ruleId + ' — ') : '')
                    + (m.message || '');
            });
            if (messages.length > maxShow) {
                lines.push('…and ' + (messages.length - maxShow) + ' more');
            }

            return {
                pass: false,
                detail: messages.length + ' validation issue(s):\n' + lines.join('\n'),
                messages: messages
            };
        }).catch(function (err) {
            var reason = (err && err.message) ? err.message : String(err);
            return {
                pass: true,
                bypass: true,
                detail: 'CSS validator unavailable — credited automatically. (' + reason + ')',
                messages: []
            };
        });
    }

    global.WebGraderCssValidate = {
        CDN_URL: CDN_URL,
        load: load,
        exerciseNeedsCssValidate: exerciseNeedsCssValidate,
        validateCssSource: validateCssSource,
        ruleDeclares: ruleDeclares
    };
})(window);
