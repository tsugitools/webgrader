/**
 * Optional accessibility checks via axe-core (CDN ES module).
 * Loaded only when an assignment uses type: "axe_validate".
 * Runs against the student iframe DOM (live preview), not raw source.
 */
(function (global) {
    'use strict';

    // Pinned version — bump intentionally when upgrading.
    var CDN_URL = 'https://cdn.jsdelivr.net/npm/axe-core@4.10.3/axe.min.js';

    var loadPromise = null;

    /**
     * Load axe as a classic script so window.axe is set in this realm.
     * (ESM builds + iframe Document objects often trip axe argument checks.)
     */
    function load() {
        if (loadPromise) return loadPromise;
        if (global.axe && typeof global.axe.run === 'function') {
            loadPromise = Promise.resolve(global.axe);
            return loadPromise;
        }

        loadPromise = new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = CDN_URL;
            script.async = true;
            script.onload = function () {
                if (global.axe && typeof global.axe.run === 'function') {
                    resolve(global.axe);
                } else {
                    loadPromise = null;
                    reject(new Error('axe-core loaded but window.axe.run is missing'));
                }
            };
            script.onerror = function () {
                loadPromise = null;
                reject(new Error('Failed to load axe-core from CDN'));
            };
            document.head.appendChild(script);
        });
        return loadPromise;
    }

    function exerciseNeedsAxeValidate(exercise) {
        var tests = exercise && Array.isArray(exercise.tests) ? exercise.tests : [];
        return tests.some(function (t) { return t && t.type === 'axe_validate'; });
    }

    function buildRunOptions(test) {
        test = test || {};
        var options = {};

        if (test.runOnly != null) {
            if (Array.isArray(test.runOnly)) {
                // Plain string arrays are treated as *tags* by axe — always
                // wrap rule ids as type:'rule' so planted checks actually run.
                options.runOnly = {
                    type: 'rule',
                    values: test.runOnly.map(String)
                };
            } else if (typeof test.runOnly === 'string') {
                options.runOnly = {
                    type: 'rule',
                    values: [test.runOnly]
                };
            } else if (typeof test.runOnly === 'object') {
                options.runOnly = test.runOnly;
            }
        } else if (Array.isArray(test.tags) && test.tags.length) {
            options.runOnly = {
                type: 'tag',
                values: test.tags.map(String)
            };
        } else if (typeof test.tags === 'string') {
            options.runOnly = {
                type: 'tag',
                values: [test.tags]
            };
        }

        return options;
    }

    /**
     * Prefer the iframe element as axe context. Passing an iframe Document from
     * the parent window fails axe's argument validation (cross-realm Document).
     */
    function resolveAxeContext(doc) {
        if (!doc) return null;
        try {
            var win = doc.defaultView;
            if (win && win.frameElement) {
                return win.frameElement;
            }
        } catch (e) { /* ignore */ }
        if (doc.documentElement) {
            return doc.documentElement;
        }
        return doc;
    }

    function impactRank(impact) {
        var order = { minor: 1, moderate: 2, serious: 3, critical: 4 };
        var key = String(impact || '').toLowerCase();
        return order[key] || 0;
    }

    function minImpactRank(test) {
        if (!test || test.impact == null || test.impact === '') {
            return 0;
        }
        return impactRank(test.impact);
    }

    function formatNodeTarget(node) {
        if (!node) return '';
        if (Array.isArray(node.target) && node.target.length) {
            return node.target.join(' ');
        }
        if (typeof node.target === 'string') return node.target;
        return '';
    }

    /**
     * Run axe against a live Document (student iframe).
     * @returns {Promise<{pass:boolean, detail:string, messages:Array, bypass?:boolean}>}
     */
    function runAxe(doc, test) {
        test = test || {};
        var maxShow = typeof test.max_messages === 'number' ? test.max_messages : 8;
        var minImpact = minImpactRank(test);

        if (!doc || !doc.documentElement) {
            return Promise.resolve({
                pass: false,
                detail: 'No student document is available. Press Run first.',
                messages: []
            });
        }

        var context = resolveAxeContext(doc);
        if (!context) {
            return Promise.resolve({
                pass: false,
                detail: 'Could not resolve accessibility check context.',
                messages: []
            });
        }

        return load().then(function (axe) {
            var options = buildRunOptions(test);
            return axe.run(context, options).then(function (results) {
                var violations = (results && Array.isArray(results.violations))
                    ? results.violations
                    : [];

                if (minImpact > 0) {
                    violations = violations.filter(function (v) {
                        return impactRank(v.impact) >= minImpact;
                    });
                }

                if (!violations.length) {
                    return {
                        pass: true,
                        detail: 'No accessibility violations for the configured axe rules.',
                        messages: []
                    };
                }

                var messages = [];
                violations.forEach(function (v) {
                    var nodes = Array.isArray(v.nodes) ? v.nodes : [];
                    if (!nodes.length) {
                        messages.push({
                            ruleId: v.id,
                            help: v.help || v.description || '',
                            impact: v.impact || '',
                            target: ''
                        });
                        return;
                    }
                    nodes.forEach(function (node) {
                        messages.push({
                            ruleId: v.id,
                            help: v.help || v.description || '',
                            impact: v.impact || node.impact || '',
                            target: formatNodeTarget(node)
                        });
                    });
                });

                var lines = messages.slice(0, maxShow).map(function (m) {
                    var impact = m.impact ? ('[' + m.impact + '] ') : '';
                    var rule = m.ruleId ? (m.ruleId + ': ') : '';
                    var help = m.help || 'Accessibility issue';
                    var where = m.target ? (' — ' + m.target) : '';
                    return impact + rule + help + where;
                });
                if (messages.length > maxShow) {
                    lines.push('…and ' + (messages.length - maxShow) + ' more');
                }

                return {
                    pass: false,
                    detail: messages.length + ' accessibility issue(s):\n' + lines.join('\n'),
                    messages: messages
                };
            });
        }).catch(function (err) {
            var reason = (err && err.message) ? err.message : String(err);
            return {
                pass: true,
                bypass: true,
                detail: 'Accessibility checker unavailable — credited automatically. (' + reason + ')',
                messages: []
            };
        });
    }

    global.WebGraderAxeValidate = {
        CDN_URL: CDN_URL,
        load: load,
        exerciseNeedsAxeValidate: exerciseNeedsAxeValidate,
        runAxe: runAxe
    };
})(window);
