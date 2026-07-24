/**
 * Optional HTML validation via html-validate (CDN ES module).
 * Loaded only when an assignment uses type: "html_validate".
 */
(function (global) {
    'use strict';

    // Pinned version — bump intentionally when upgrading.
    var CDN_URL = 'https://esm.sh/html-validate@10.9.0/browser';

    var loadPromise = null;

    function load() {
        if (loadPromise) return loadPromise;
        loadPromise = import(CDN_URL).then(function (mod) {
            if (!mod || !mod.HtmlValidate || !mod.StaticConfigLoader) {
                throw new Error('html-validate CDN module missing HtmlValidate exports');
            }
            return {
                HtmlValidate: mod.HtmlValidate,
                StaticConfigLoader: mod.StaticConfigLoader
            };
        }).catch(function (err) {
            loadPromise = null;
            throw err;
        });
        return loadPromise;
    }

    function exerciseNeedsHtmlValidate(exercise) {
        var tests = exercise && Array.isArray(exercise.tests) ? exercise.tests : [];
        return tests.some(function (t) { return t && t.type === 'html_validate'; });
    }

    /**
     * Validate HTML source string.
     * @returns {Promise<{pass:boolean, detail:string, messages:Array}>}
     */
    function validateHtmlSource(html, test) {
        test = test || {};
        var preset = test.preset || 'html-validate:recommended';
        var includeWarnings = !!test.include_warnings;
        var maxShow = typeof test.max_messages === 'number' ? test.max_messages : 8;

        return load().then(function (api) {
            var config = {
                extends: [preset]
            };
            if (test.rules && typeof test.rules === 'object') {
                config.rules = test.rules;
            }
            var loader = new api.StaticConfigLoader(config);
            var validator = new api.HtmlValidate(loader);
            return validator.validateString(String(html || ''), 'student.html').then(function (report) {
                var raw = [];
                if (report && Array.isArray(report.results)) {
                    report.results.forEach(function (r) {
                        if (r && Array.isArray(r.messages)) {
                            raw = raw.concat(r.messages);
                        }
                    });
                }

                var messages = raw.filter(function (m) {
                    var sev = typeof m.severity === 'number' ? m.severity : 2;
                    return includeWarnings ? sev >= 1 : sev >= 2;
                });

                // Prefer report.valid when the library marks the document invalid,
                // even if a custom filter somehow emptied the list.
                var invalid = (report && report.valid === false) || messages.length > 0;
                if (!invalid) {
                    return {
                        pass: true,
                        detail: 'HTML validated (' + preset + ').',
                        messages: []
                    };
                }

                if (!messages.length && raw.length) {
                    messages = raw.filter(function (m) {
                        return (typeof m.severity === 'number' ? m.severity : 2) >= 2;
                    });
                }

                var lines = messages.slice(0, maxShow).map(function (m) {
                    var loc = (m.line != null) ? ('L' + m.line + ': ') : '';
                    var rule = m.ruleId ? (m.ruleId + ' — ') : '';
                    var text = m.message || '';
                    if (m.ruleId === 'parser-error') {
                        text += '\n    Hint: look above this line for a tag that never got a closing ">" '
                            + 'or for a missing quote on an attribute (e.g. <h1 or class="foo).';
                    } else if (m.ruleId === 'no-implicit-close') {
                        text += '\n    Hint: add the missing end tag (e.g. </p>) instead of letting the next tag close it.';
                    } else if (m.ruleId === 'close-order') {
                        text += '\n    Hint: tags must nest correctly — close the innermost tag first.';
                    }
                    return loc + rule + text;
                });
                if (!lines.length) {
                    lines.push('Document is invalid (html-validate reported valid=false).');
                }
                if (messages.length > maxShow) {
                    lines.push('…and ' + (messages.length - maxShow) + ' more');
                }

                return {
                    pass: false,
                    detail: messages.length + ' validation issue(s):\n' + lines.join('\n'),
                    messages: messages
                };
            });
        });
    }

    global.WebGraderHtmlValidate = {
        CDN_URL: CDN_URL,
        load: load,
        exerciseNeedsHtmlValidate: exerciseNeedsHtmlValidate,
        validateHtmlSource: validateHtmlSource
    };
})(window);
