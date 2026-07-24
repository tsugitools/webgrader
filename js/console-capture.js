/**
 * Capture console + runtime errors from the student iframe.
 * Parent panel API + injectable bridge script for srcdoc assembly.
 */
(function (global) {
    'use strict';

    var MAX_ENTRIES = 200;
    var MAX_ARG_LEN = 500;
    var MAX_STACK_LEN = 800;
    var MAX_TOTAL_CHARS = 40000;

    var entries = [];
    var totalChars = 0;
    var panelEl = null;
    var listEl = null;

    function truncate(str, max) {
        str = String(str);
        if (str.length <= max) return str;
        return str.slice(0, max - 1) + '…';
    }

    function safeStringify(value, depth) {
        depth = depth || 0;
        if (depth > 3) return '[…]';
        if (value === null) return 'null';
        if (value === undefined) return 'undefined';
        var t = typeof value;
        if (t === 'string') return truncate(JSON.stringify(value), MAX_ARG_LEN);
        if (t === 'number' || t === 'boolean' || t === 'bigint') return String(value);
        if (t === 'function') {
            return truncate('[Function ' + (value.name || 'anonymous') + ']', MAX_ARG_LEN);
        }
        if (t === 'symbol') return truncate(String(value), MAX_ARG_LEN);
        if (value instanceof Error) {
            var msg = value.name + ': ' + value.message;
            if (value.stack) {
                msg += '\n' + truncate(value.stack, MAX_STACK_LEN);
            }
            return truncate(msg, MAX_ARG_LEN + MAX_STACK_LEN);
        }
        if (Array.isArray(value)) {
            try {
                return truncate(JSON.stringify(value), MAX_ARG_LEN);
            } catch (e) {
                return '[Array]';
            }
        }
        if (t === 'object') {
            try {
                return truncate(JSON.stringify(value), MAX_ARG_LEN);
            } catch (e) {
                try {
                    return truncate(Object.prototype.toString.call(value), MAX_ARG_LEN);
                } catch (e2) {
                    return '[Object]';
                }
            }
        }
        return truncate(String(value), MAX_ARG_LEN);
    }

    function formatArgs(args) {
        if (!args || !args.length) return '';
        return args.map(function (a) { return safeStringify(a); }).join(' ');
    }

    function ensurePanelParts() {
        if (!panelEl) return;
        if (!listEl) {
            listEl = panelEl.querySelector('.console-list');
        }
    }

    function renderEntry(entry) {
        ensurePanelParts();
        if (!listEl) return;
        var empty = listEl.querySelector('.console-empty');
        if (empty) empty.remove();

        var row = document.createElement('div');
        row.className = 'console-line console-' + entry.level;
        var mark = document.createElement('span');
        mark.className = 'console-level';
        mark.textContent = entry.level;
        var msg = document.createElement('pre');
        msg.className = 'console-msg';
        msg.textContent = entry.message;
        row.appendChild(mark);
        row.appendChild(msg);
        listEl.appendChild(row);
        listEl.scrollTop = listEl.scrollHeight;
    }

    function append(entry) {
        if (!entry || !entry.message) return;
        var level = entry.level || 'log';
        var message = truncate(entry.message, MAX_ARG_LEN + MAX_STACK_LEN);
        var item = {
            level: level,
            message: message,
            ts: Date.now()
        };
        if (totalChars + message.length > MAX_TOTAL_CHARS) {
            while (entries.length && totalChars + message.length > MAX_TOTAL_CHARS) {
                var dropped = entries.shift();
                totalChars -= (dropped.message || '').length;
            }
            if (listEl) {
                while (listEl.firstChild) listEl.removeChild(listEl.firstChild);
                entries.forEach(renderEntry);
            }
        }
        entries.push(item);
        totalChars += message.length;
        while (entries.length > MAX_ENTRIES) {
            var old = entries.shift();
            totalChars -= (old.message || '').length;
            if (listEl && listEl.firstChild) listEl.removeChild(listEl.firstChild);
        }
        renderEntry(item);
    }

    function clear() {
        entries = [];
        totalChars = 0;
        ensurePanelParts();
        if (listEl) {
            listEl.innerHTML = '';
            var empty = document.createElement('div');
            empty.className = 'console-empty';
            empty.textContent = 'Console output appears here when the preview runs.';
            listEl.appendChild(empty);
        }
    }

    function bind(panel) {
        panelEl = panel || null;
        listEl = panelEl ? panelEl.querySelector('.console-list') : null;
    }

    function getEntries() {
        return entries.slice();
    }

    function hasErrors() {
        return entries.some(function (e) {
            return e.level === 'error' || e.level === 'exception' || e.level === 'rejection';
        });
    }

    /**
     * Called from the iframe bridge (same-origin).
     */
    function onIframeLog(level, args) {
        append({
            level: level,
            message: formatArgs(args)
        });
    }

    function onIframeError(payload) {
        var msg = (payload && payload.message) ? payload.message : 'Error';
        if (payload && payload.stack) {
            msg += '\n' + truncate(payload.stack, MAX_STACK_LEN);
        } else if (payload && payload.source) {
            msg += ' (' + payload.source + ':' + (payload.line || '?') + ')';
        }
        append({
            level: payload && payload.level ? payload.level : 'exception',
            message: msg
        });
    }

    /**
     * Inline bridge installed at the top of the student document,
     * before student scripts run.
     */
    function bridgeScriptHtml() {
        // Keep this self-contained; communicate via parent.WebGraderConsole.
        return '<script>(function(){\n'
            + 'function send(level, args){\n'
            + '  try {\n'
            + '    var C = window.parent && window.parent.WebGraderConsole;\n'
            + '    if (C && C.onIframeLog) C.onIframeLog(level, args);\n'
            + '  } catch (e) {}\n'
            + '}\n'
            + 'function sendErr(payload){\n'
            + '  try {\n'
            + '    var C = window.parent && window.parent.WebGraderConsole;\n'
            + '    if (C && C.onIframeError) C.onIframeError(payload);\n'
            + '  } catch (e) {}\n'
            + '}\n'
            + '["log","info","warn","error","debug"].forEach(function(m){\n'
            + '  var orig = console[m] ? console[m].bind(console) : function(){};\n'
            + '  console[m] = function(){\n'
            + '    var args = Array.prototype.slice.call(arguments);\n'
            + '    send(m === "debug" ? "log" : m, args);\n'
            + '    try { orig.apply(console, args); } catch (e) {}\n'
            + '  };\n'
            + '});\n'
            + 'window.addEventListener("error", function(ev){\n'
            + '  var t = ev && ev.target;\n'
            + '  // Resource load failures (img/script/link/…) do not bubble; use capture.\n'
            + '  if (t && t !== window && t.tagName) {\n'
            + '    var tag = String(t.tagName).toLowerCase();\n'
            + '    var src = t.src || t.href || "";\n'
            + '    sendErr({\n'
            + '      level: "error",\n'
            + '      message: "Failed to load <" + tag + "> resource" + (src ? (": " + src) : "")\n'
            + '    });\n'
            + '    return;\n'
            + '  }\n'
            + '  sendErr({\n'
            + '    level: "exception",\n'
            + '    message: (ev && ev.message) ? ev.message : String(ev),\n'
            + '    source: ev && ev.filename,\n'
            + '    line: ev && ev.lineno,\n'
            + '    stack: ev && ev.error && ev.error.stack\n'
            + '  });\n'
            + '}, true);\n'
            + 'window.addEventListener("unhandledrejection", function(ev){\n'
            + '  var reason = ev && ev.reason;\n'
            + '  var message = "Unhandled rejection";\n'
            + '  var stack = null;\n'
            + '  if (reason && reason.message) {\n'
            + '    message = "Unhandled rejection: " + reason.message;\n'
            + '    stack = reason.stack || null;\n'
            + '  } else if (typeof reason === "string") {\n'
            + '    message = "Unhandled rejection: " + reason;\n'
            + '  } else {\n'
            + '    try { message = "Unhandled rejection: " + String(reason); } catch (e) {}\n'
            + '  }\n'
            + '  sendErr({ level: "rejection", message: message, stack: stack });\n'
            + '});\n'
            + '})();<\/script>\n';
    }

    global.WebGraderConsole = {
        bind: bind,
        clear: clear,
        append: append,
        getEntries: getEntries,
        hasErrors: hasErrors,
        onIframeLog: onIframeLog,
        onIframeError: onIframeError,
        bridgeScriptHtml: bridgeScriptHtml,
        formatArgs: formatArgs
    };
})(window);
