/**
 * Student iframe runtime: assemble sources, dirty revision tracking.
 */
(function (global) {
    'use strict';

    var sourceRevision = 0;
    var runningRevision = -1;
    var iframeEl = null;

    function bumpSourceRevision() {
        sourceRevision += 1;
        return sourceRevision;
    }

    function getSourceRevision() {
        return sourceRevision;
    }

    function getRunningRevision() {
        return runningRevision;
    }

    function isClean() {
        return runningRevision === sourceRevision && runningRevision >= 0;
    }

    function markCleanAfterRun() {
        runningRevision = sourceRevision;
    }

    function resetRevisions() {
        sourceRevision = 0;
        runningRevision = -1;
    }

    /**
     * Turn a tool-relative path into an absolute URL for <base href>.
     */
    function makeAbsoluteHref(rel) {
        if (!rel || typeof rel !== 'string') return '';
        rel = rel.replace(/\\/g, '/');
        if (!/\/$/.test(rel) && rel.indexOf('.') === -1) {
            rel += '/';
        }
        if (/^https?:\/\//i.test(rel) || rel.charAt(0) === '/') {
            return rel;
        }
        try {
            return new URL(rel, window.location.href).href;
        } catch (e) {
            var a = document.createElement('a');
            a.href = rel;
            return a.href;
        }
    }

    /**
     * Prefer runtime.base_href; else shared directory of declared assets.
     */
    function resolveBaseHref(exercise) {
        if (!exercise) return '';
        if (exercise.runtime && exercise.runtime.base_href) {
            return makeAbsoluteHref(String(exercise.runtime.base_href));
        }
        var assets = Array.isArray(exercise.assets) ? exercise.assets : [];
        var dirs = [];
        assets.forEach(function (a) {
            if (!a || typeof a.path !== 'string') return;
            var p = a.path.replace(/\\/g, '/');
            var i = p.lastIndexOf('/');
            if (i >= 0) dirs.push(p.slice(0, i + 1));
        });
        if (!dirs.length) return '';
        var first = dirs[0];
        for (var i = 1; i < dirs.length; i++) {
            if (dirs[i] !== first) return '';
        }
        return makeAbsoluteHref(first);
    }

    function escapeAttr(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;');
    }

    /**
     * Build a full HTML document from logical file sources.
     * HTML may be a fragment or a full document.
     * Always injects console/error capture before student scripts.
     * options.baseHref — optional <base href> so short asset names resolve.
     */
    function assembleDocument(files, options) {
        options = options || {};
        var html = (files && files.html) || '';
        var css = (files && files.css) || '';
        var js = (files && files.javascript) || '';

        var Console = global.WebGraderConsole;
        var bridge = (Console && Console.bridgeScriptHtml)
            ? Console.bridgeScriptHtml()
            : '';
        var baseHref = options.baseHref ? String(options.baseHref) : '';
        var baseTag = baseHref
            ? '<base href="' + escapeAttr(baseHref) + '">\n'
            : '';
        var headInject = baseTag + bridge;

        var styleBlock = css && String(css).trim()
            ? '<style>\n' + css + '\n</style>\n'
            : '';
        var scriptBlock = js && String(js).trim()
            ? '<script>\n' + js + '\n<\/script>\n'
            : '';

        var lower = html.toLowerCase();
        var hasHtml = lower.indexOf('<html') !== -1;
        var hasHead = lower.indexOf('<head') !== -1;
        var hasBody = lower.indexOf('<body') !== -1;

        if (hasHtml) {
            var out = html;
            // Install base + console bridge as early as possible.
            if (headInject) {
                if (hasHead && /<head[^>]*>/i.test(out)) {
                    out = out.replace(/<head([^>]*)>/i, '<head$1>\n' + headInject);
                } else if (hasHtml && /<html[^>]*>/i.test(out)) {
                    out = out.replace(/<html([^>]*)>/i, '<html$1>\n' + headInject);
                } else {
                    out = headInject + out;
                }
            }
            if (styleBlock) {
                if (hasHead && /<\/head>/i.test(out)) {
                    out = out.replace(/<\/head>/i, styleBlock + '</head>');
                } else if (hasBody && /<body[^>]*>/i.test(out)) {
                    out = out.replace(/<body([^>]*)>/i, '<body$1>\n' + styleBlock);
                } else {
                    out = styleBlock + out;
                }
            }
            if (scriptBlock) {
                if (/<\/body>/i.test(out)) {
                    out = out.replace(/<\/body>/i, scriptBlock + '</body>');
                } else {
                    out = out + scriptBlock;
                }
            }
            return out;
        }

        // Fragment: wrap in a minimal document.
        return '<!DOCTYPE html>\n<html lang="en">\n<head>\n<meta charset="utf-8">\n'
            + headInject
            + styleBlock
            + '</head>\n<body>\n'
            + html + '\n'
            + scriptBlock
            + '</body>\n</html>\n';
    }

    /**
     * Create or replace the preview iframe and load assembled HTML.
     * options.baseHref or options.exercise (to derive asset base).
     * @returns {HTMLIFrameElement}
     */
    function runInto(container, files, options) {
        if (!container) {
            throw new Error('Missing preview container');
        }
        options = options || {};
        if (global.WebGraderConsole && global.WebGraderConsole.clear) {
            global.WebGraderConsole.clear();
        }
        // Remove previous iframe for a clean window.
        container.innerHTML = '';
        var iframe = document.createElement('iframe');
        iframe.id = 'studentFrame';
        iframe.title = 'Student preview';
        iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-popups');
        iframe.className = 'student-frame';
        container.appendChild(iframe);

        var baseHref = options.baseHref || resolveBaseHref(options.exercise);
        var docHtml = assembleDocument(files, { baseHref: baseHref });
        // srcdoc keeps us same-origin with the parent for DOM grading.
        iframe.srcdoc = docHtml;
        iframeEl = iframe;
        markCleanAfterRun();
        return iframe;
    }

    function getIframe() {
        return iframeEl;
    }

    function getStudentDocument() {
        if (!iframeEl) return null;
        try {
            return iframeEl.contentDocument || (iframeEl.contentWindow && iframeEl.contentWindow.document);
        } catch (e) {
            return null;
        }
    }

    function clearPreview(container) {
        if (container) container.innerHTML = '';
        iframeEl = null;
        runningRevision = -1;
        if (global.WebGraderConsole && global.WebGraderConsole.clear) {
            global.WebGraderConsole.clear();
        }
    }

    global.WebGraderRuntime = {
        bumpSourceRevision: bumpSourceRevision,
        getSourceRevision: getSourceRevision,
        getRunningRevision: getRunningRevision,
        isClean: isClean,
        markCleanAfterRun: markCleanAfterRun,
        resetRevisions: resetRevisions,
        resolveBaseHref: resolveBaseHref,
        makeAbsoluteHref: makeAbsoluteHref,
        assembleDocument: assembleDocument,
        runInto: runInto,
        getIframe: getIframe,
        getStudentDocument: getStudentDocument,
        clearPreview: clearPreview
    };
})(window);
