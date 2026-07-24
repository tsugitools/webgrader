/**
 * WebGrader UI — author and learner modes.
 * PHP only injects window.WEBGRADER; all interaction lives here.
 */
(function () {
    'use strict';

    var cfg = window.WEBGRADER || {};
    var exercise = cfg.exercise || {};
    var Runtime = window.WebGraderRuntime;
    var Tests = window.WebGraderTests;
    var Validation = window.WebGraderValidation;
    var Console = window.WebGraderConsole;

    var FILE_KEYS = ['html', 'css', 'javascript'];
    var saveTimer = null;
    var statusEl = null;

    function $(sel, root) {
        return (root || document).querySelector(sel);
    }

    function el(tag, attrs, html) {
        var node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                if (k === 'className') node.className = attrs[k];
                else if (k === 'text') node.textContent = attrs[k];
                else node.setAttribute(k, attrs[k]);
            });
        }
        if (typeof html === 'string') node.innerHTML = html;
        return node;
    }

    function setStatus(kind, message) {
        if (!statusEl) return;
        statusEl.className = 'status status-' + (kind || 'pending');
        statusEl.textContent = message || '';
    }

    function fileMode(name) {
        var f = exercise.files && exercise.files[name];
        return (f && f.mode) || 'hidden';
    }

    function starterFor(name) {
        var f = exercise.files && exercise.files[name];
        return (f && typeof f.starter === 'string') ? f.starter : '';
    }

    function solutionFor(name) {
        var sol = exercise.solution;
        if (!sol || typeof sol !== 'object') return null;
        if (typeof sol[name] !== 'string') return null;
        return sol[name];
    }

    function hasSolution() {
        return FILE_KEYS.some(function (key) {
            return solutionFor(key) !== null;
        });
    }

    function solutionFiles() {
        var files = {
            html: starterFor('html'),
            css: starterFor('css'),
            javascript: starterFor('javascript')
        };
        FILE_KEYS.forEach(function (key) {
            var s = solutionFor(key);
            if (s !== null) files[key] = s;
        });
        return files;
    }

    function persistKey() {
        return (cfg.urls && cfg.urls.persistKey) || 'webgrader-anon';
    }

    function localBackupKey() {
        return persistKey() + '-source';
    }

    function readEditors() {
        var out = { html: '', css: '', javascript: '' };
        FILE_KEYS.forEach(function (key) {
            var ta = $('#editor-' + key);
            if (ta) {
                out[key] = ta.value;
            } else {
                // Hidden / not shown — use starter (readonly supplied content).
                out[key] = starterFor(key);
            }
        });
        return out;
    }

    function writeEditors(files) {
        FILE_KEYS.forEach(function (key) {
            var ta = $('#editor-' + key);
            if (ta && files && typeof files[key] === 'string') {
                ta.value = files[key];
            }
        });
    }

    function initialFiles() {
        var files = {
            html: starterFor('html'),
            css: starterFor('css'),
            javascript: starterFor('javascript')
        };
        var sub = cfg.submission;
        if (sub && sub.files) {
            FILE_KEYS.forEach(function (key) {
                if (typeof sub.files[key] === 'string') {
                    files[key] = sub.files[key];
                }
            });
            return files;
        }
        try {
            var raw = localStorage.getItem(localBackupKey());
            if (raw) {
                var parsed = JSON.parse(raw);
                if (parsed && parsed.files) {
                    FILE_KEYS.forEach(function (key) {
                        if (typeof parsed.files[key] === 'string') {
                            files[key] = parsed.files[key];
                        }
                    });
                }
            }
        } catch (e) { /* ignore */ }
        return files;
    }

    function buildSubmissionPayload() {
        return {
            schema: 'webgrader-submission',
            version: 1,
            files: readEditors(),
            source_revision: Runtime.getSourceRevision(),
            last_run_revision: Runtime.getRunningRevision()
        };
    }

    function saveLocalBackup() {
        try {
            localStorage.setItem(localBackupKey(), JSON.stringify(buildSubmissionPayload()));
        } catch (e) { /* quota */ }
    }

    function clearLocalBackup() {
        try {
            localStorage.removeItem(localBackupKey());
        } catch (e) { /* ignore */ }
    }

    function saveStudentSource(done) {
        saveLocalBackup();
        if (!cfg.urls || !cfg.urls.studentSave || !cfg.hasLink) {
            if (done) done(null);
            return;
        }
        var payload = buildSubmissionPayload();
        fetch(cfg.urls.studentSave, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (resp) {
            return resp.json().catch(function () { return {}; }).then(function (body) {
                if (done) done(resp.ok ? null : (body.detail || 'Save failed'));
            });
        }).catch(function (err) {
            if (done) done(err.message || 'Save failed');
        });
    }

    function scheduleAutosave() {
        if (saveTimer) clearTimeout(saveTimer);
        saveTimer = setTimeout(function () {
            saveTimer = null;
            saveStudentSource(null);
        }, 800);
    }

    function updateGradeButton() {
        var btn = $('#btnGrade');
        if (!btn) return;
        var v = Validation.validateAssignment(exercise);
        var assetErr = preflightAssets(exercise);
        var can = Runtime.isClean() && v.ok && !assetErr && (exercise.tests || []).length > 0;
        btn.disabled = !can;
        var dirty = $('#dirtyNote');
        if (dirty) {
            if (!Runtime.isClean() && Runtime.getRunningRevision() >= 0) {
                dirty.hidden = false;
                dirty.textContent = 'Source changed since last Run — Grade is disabled until you Run again.';
            } else if (!Runtime.isClean()) {
                dirty.hidden = false;
                dirty.textContent = 'Press Run / Restart before grading.';
            } else {
                dirty.hidden = true;
            }
        }
    }

    function preflightAssets(ex) {
        if (!Array.isArray(ex.assets) || !ex.assets.length) return null;
        for (var i = 0; i < ex.assets.length; i++) {
            var a = ex.assets[i];
            if (!a || !a.required) continue;
            if (!Validation.assetPathOk(a.path)) {
                return 'Required asset path is invalid: ' + (a.path || '(missing)');
            }
            // Existence of remote assets is checked at authoring/CI time;
            // for Phase 1 we only enforce path policy on launch.
        }
        return null;
    }

    function onEditorInput() {
        Runtime.bumpSourceRevision();
        updateGradeButton();
        scheduleAutosave();
    }

    function renderEditorTabs(container, files, opts) {
        opts = opts || {};
        var tabs = el('div', { className: 'editor-tabs', role: 'tablist' });
        var panes = el('div', { className: 'editor-panes' });
        var firstVisible = null;

        FILE_KEYS.forEach(function (key) {
            var mode = fileMode(key);
            if (mode === 'hidden') return;
            if (!firstVisible) firstVisible = key;

            var tab = el('button', {
                type: 'button',
                className: 'editor-tab',
                role: 'tab',
                id: 'tab-' + key,
                'data-file': key,
                text: key.toUpperCase() + (mode === 'readonly' ? ' (read-only)' : '')
            });
            tabs.appendChild(tab);

            var pane = el('div', {
                className: 'editor-pane',
                id: 'pane-' + key,
                role: 'tabpanel'
            });
            var ta = el('textarea', {
                className: 'code',
                id: 'editor-' + key,
                spellcheck: 'false',
                'aria-label': key + ' source'
            });
            ta.value = (files && typeof files[key] === 'string') ? files[key] : starterFor(key);
            if (mode === 'readonly' || opts.readOnly) {
                ta.readOnly = true;
            } else {
                ta.addEventListener('input', onEditorInput);
            }
            pane.appendChild(ta);
            pane.hidden = true;
            panes.appendChild(pane);

            tab.addEventListener('click', function () {
                activateTab(key, container);
            });
        });

        container.appendChild(tabs);
        container.appendChild(panes);
        if (firstVisible) activateTab(firstVisible, container);
    }

    function activateTab(key, root) {
        var scope = root || document;
        FILE_KEYS.forEach(function (k) {
            var tab = scope.querySelector('#tab-' + k);
            var pane = scope.querySelector('#pane-' + k);
            if (tab) {
                tab.classList.toggle('is-active', k === key);
                tab.setAttribute('aria-selected', k === key ? 'true' : 'false');
            }
            if (pane) pane.hidden = k !== key;
        });
    }

    function renderPrompt(root) {
        var block = el('section', { className: 'prompt-block' });
        var title = exercise.title || 'Untitled assignment';
        block.appendChild(el('h1', { text: title }));
        var prompt = el('div', { className: 'prompt' });
        prompt.innerHTML = exercise.prompt || '';
        block.appendChild(prompt);
        root.appendChild(block);
        var titleEl = $('#exerciseTitle');
        if (titleEl) titleEl.textContent = title;
    }

    function renderResults(panel, report) {
        panel.innerHTML = '';
        panel.appendChild(el('h2', { text: 'Test results' }));

        if (report.configError) {
            panel.appendChild(el('p', {
                className: 'error-banner',
                text: 'Assignment configuration error: ' + report.configError
            }));
        }
        if (report.graderError) {
            panel.appendChild(el('p', {
                className: 'error-banner',
                text: 'Grader error: ' + report.graderError
            }));
        }

        var summary = el('p', { className: 'score-summary' });
        summary.textContent = 'Score: ' + report.earned + ' / ' + report.maximum
            + ' (' + Math.round(report.grade * 1000) / 10 + '%)';
        panel.appendChild(summary);

        var list = el('ul', { className: 'test-list' });
        (report.results || []).forEach(function (r) {
            var li = el('li', {
                className: 'test-item test-' + r.kind
            });
            var head = el('div', { className: 'test-head' });
            head.appendChild(el('span', {
                className: 'test-mark',
                text: r.pass ? 'PASS' : (r.kind === 'config' || r.kind === 'grader' ? 'ERROR' : 'FAIL')
            }));
            head.appendChild(el('span', {
                className: 'test-name',
                text: r.name
            }));
            head.appendChild(el('span', {
                className: 'test-points',
                text: r.earned + '/' + r.points
            }));
            li.appendChild(head);
            if (r.detail) {
                li.appendChild(el('div', { className: 'test-detail', text: r.detail }));
            }
            if (r.feedback) {
                li.appendChild(el('div', { className: 'test-feedback', text: r.feedback }));
            }
            list.appendChild(li);
        });
        panel.appendChild(list);
    }

    function recordAttempt() {
        if (!cfg.urls || !cfg.urls.recordAttempt) return;
        var fd = new FormData();
        fetch(cfg.urls.recordAttempt, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        }).catch(function () { /* best-effort */ });
    }

    function submitGrade(grade) {
        if (!cfg.urls || !cfg.urls.gradeSubmit) {
            return Promise.reject(new Error('Grade submit URL missing'));
        }
        var fd = new FormData();
        fd.append('grade', String(grade));
        fd.append('code', 'WEBGRADER');
        return fetch(cfg.urls.gradeSubmit, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        }).then(function (resp) {
            return resp.json().catch(function () {
                return { status: resp.ok ? 'success' : 'failure' };
            }).then(function (body) {
                return { ok: resp.ok, body: body };
            });
        });
    }

    function doRun() {
        var preview = $('#previewHost');
        var results = $('#resultsPanel');
        if (results) results.innerHTML = '';
        try {
            Runtime.runInto(preview, readEditors(), { exercise: exercise });
            setStatus('success', 'Preview running');
            updateGradeButton();
            scheduleAutosave();
        } catch (e) {
            setStatus('error', e.message || String(e));
        }
    }

    function doGrade() {
        var v = Validation.validateAssignment(exercise);
        if (!v.ok) {
            setStatus('error', 'Assignment configuration error');
            var panel = $('#resultsPanel');
            if (panel) {
                panel.innerHTML = '';
                panel.appendChild(el('p', {
                    className: 'error-banner',
                    text: v.errors.join(' ')
                }));
            }
            return;
        }
        var assetErr = preflightAssets(exercise);
        if (assetErr) {
            setStatus('error', assetErr);
            return;
        }
        if (!Runtime.isClean()) {
            setStatus('fail', 'Run the current source before grading');
            return;
        }
        var doc = Runtime.getStudentDocument();
        var btnGrade = $('#btnGrade');
        if (btnGrade) btnGrade.disabled = true;
        setStatus('pending', 'Grading…');

        var needsHv = window.WebGraderHtmlValidate
            && window.WebGraderHtmlValidate.exerciseNeedsHtmlValidate(exercise);
        var ready = needsHv && window.WebGraderHtmlValidate.load
            ? window.WebGraderHtmlValidate.load().catch(function () { /* runTests reports failure */ })
            : Promise.resolve();

        ready.then(function () {
            return Tests.runTests(doc, exercise, { htmlSource: readEditors().html });
        }).then(function (report) {
            renderResults($('#resultsPanel'), report);

            if (report.configError || report.graderError) {
                setStatus('error', report.configError || report.graderError);
                updateGradeButton();
                return;
            }

            setStatus('pending', 'Submitting grade…');
            recordAttempt();
            saveStudentSource(null);
            return submitGrade(report.grade).then(function (resp) {
                if (resp.ok || (resp.body && resp.body.status === 'success')) {
                    setStatus('success', 'Grade submitted: '
                        + report.earned + '/' + report.maximum);
                } else {
                    var detail = (resp.body && (resp.body.detail || resp.body.status)) || 'submit failed';
                    setStatus('success', 'Scored ' + report.earned + '/' + report.maximum
                        + ' — grade note: ' + detail);
                }
                updateGradeButton();
            });
        }).catch(function (err) {
            setStatus('error', (err && err.message) ? err.message : String(err));
            updateGradeButton();
        });
    }

    function doReset() {
        if (!window.confirm('Reset editors to the assignment starter code? Your saved work for this placement will be cleared.')) {
            return;
        }
        var starters = {
            html: starterFor('html'),
            css: starterFor('css'),
            javascript: starterFor('javascript')
        };
        writeEditors(starters);
        Runtime.resetRevisions();
        Runtime.bumpSourceRevision();
        Runtime.clearPreview($('#previewHost'));
        if (Console && Console.clear) Console.clear();
        var results = $('#resultsPanel');
        if (results) results.innerHTML = '';
        clearLocalBackup();
        saveStudentSource(function () {
            setStatus('pending', 'Reset to starter code');
            updateGradeButton();
        });
        updateGradeButton();
    }

    function doLoadSolution() {
        if (!cfg.isInstructor) return;
        if (!hasSolution()) {
            setStatus('error', 'No reference solution configured for this assignment');
            return;
        }
        if (!window.confirm('Load the reference solution into the editors? This replaces the current source for this session.')) {
            return;
        }
        writeEditors(solutionFiles());
        Runtime.bumpSourceRevision();
        Runtime.clearPreview($('#previewHost'));
        if (Console && Console.clear) Console.clear();
        var results = $('#resultsPanel');
        if (results) results.innerHTML = '';
        scheduleAutosave();
        setStatus('pending', 'Solution loaded — press Run / Restart');
        updateGradeButton();
    }

    // ---- Learner ----

    function renderLearner() {
        var app = $('#app');
        app.innerHTML = '';

        var v = Validation.validateAssignment(exercise);
        var assetErr = preflightAssets(exercise);

        renderPrompt(app);

        if (!v.ok) {
            app.appendChild(el('p', {
                className: 'error-banner',
                text: 'This assignment has configuration errors and cannot be graded: '
                    + v.errors.join(' ')
            }));
        }
        if (assetErr) {
            app.appendChild(el('p', {
                className: 'error-banner',
                text: assetErr
            }));
        }

        var layout = el('div', { className: 'learner-layout' });
        var editorBlock = el('section', { className: 'editor-block' });
        editorBlock.appendChild(el('h2', { text: 'Source' }));
        renderEditorTabs(editorBlock, initialFiles());
        layout.appendChild(editorBlock);

        var right = el('div', { className: 'learner-right' });
        var actions = el('div', { className: 'btn-row' });
        var btnRun = el('button', {
            type: 'button',
            className: 'btn btn-primary',
            id: 'btnRun',
            text: 'Run / Restart'
        });
        var btnGrade = el('button', {
            type: 'button',
            className: 'btn btn-secondary',
            id: 'btnGrade',
            text: 'Grade'
        });
        var btnReset = el('button', {
            type: 'button',
            className: 'btn btn-ghost',
            id: 'btnReset',
            text: 'Reset to starter'
        });
        statusEl = el('span', { className: 'status status-pending', id: 'runStatus' });
        actions.appendChild(btnRun);
        actions.appendChild(btnGrade);
        actions.appendChild(btnReset);
        if (cfg.isInstructor && hasSolution()) {
            var btnSolution = el('button', {
                type: 'button',
                className: 'btn btn-ghost',
                id: 'btnLoadSolution',
                text: 'Load solution'
            });
            actions.appendChild(btnSolution);
            btnSolution.addEventListener('click', doLoadSolution);
        }
        actions.appendChild(statusEl);
        right.appendChild(actions);

        var dirtyNote = el('p', {
            className: 'dirty-note',
            id: 'dirtyNote'
        });
        dirtyNote.hidden = true;
        right.appendChild(dirtyNote);

        var previewWrap = el('section', { className: 'preview-block' });
        previewWrap.appendChild(el('h2', { text: 'Preview' }));
        previewWrap.appendChild(el('div', { id: 'previewHost', className: 'preview-host' }));
        right.appendChild(previewWrap);

        var consoleWrap = el('section', {
            className: 'console-block',
            id: 'consolePanel'
        });
        consoleWrap.appendChild(el('h2', { text: 'Console' }));
        var consoleList = el('div', { className: 'console-list' });
        var consoleEmpty = el('div', {
            className: 'console-empty',
            text: 'Console output appears here when the preview runs.'
        });
        consoleList.appendChild(consoleEmpty);
        consoleWrap.appendChild(consoleList);
        right.appendChild(consoleWrap);
        if (Console && Console.bind) Console.bind(consoleWrap);

        right.appendChild(el('section', { id: 'resultsPanel', className: 'results-panel' }));

        layout.appendChild(right);
        app.appendChild(layout);

        btnRun.addEventListener('click', doRun);
        btnGrade.addEventListener('click', doGrade);
        btnReset.addEventListener('click', doReset);

        Runtime.resetRevisions();
        Runtime.bumpSourceRevision();
        updateGradeButton();

        // Prefetch CDN validator only when this assignment opts in.
        if (window.WebGraderHtmlValidate
            && window.WebGraderHtmlValidate.exerciseNeedsHtmlValidate(exercise)) {
            window.WebGraderHtmlValidate.load().catch(function () { /* Grade will surface errors */ });
        }
    }

    // ---- Author ----

    function collectExerciseFromForm() {
        var title = ($('#auth-title') || {}).value || '';
        var prompt = ($('#auth-prompt') || {}).value || '';
        var id = ($('#auth-id') || {}).value || exercise.id || 'custom';
        var next = JSON.parse(JSON.stringify(exercise));
        next.type = 'webgrader';
        next.schema_version = 1;
        next.title = title;
        next.prompt = prompt;
        next.id = id;
        if (!next.files) next.files = {};
        FILE_KEYS.forEach(function (key) {
            if (!next.files[key]) next.files[key] = { mode: 'hidden', starter: '' };
            var modeSel = $('#auth-mode-' + key);
            var starter = $('#auth-starter-' + key);
            if (modeSel) next.files[key].mode = modeSel.value;
            if (starter) next.files[key].starter = starter.value;
            var solTa = $('#auth-solution-' + key);
            if (solTa) {
                if (!next.solution) next.solution = {};
                next.solution[key] = solTa.value;
            }
        });
        if (next.solution) {
            var anySol = FILE_KEYS.some(function (key) {
                return next.solution[key] && String(next.solution[key]).trim();
            });
            if (!anySol) delete next.solution;
        }
        next.builtin_rev = 'custom';
        return next;
    }

    function authorSave() {
        var next = collectExerciseFromForm();
        var v = Validation.validateAssignment(next);
        if (!v.ok) {
            setStatus('error', v.errors[0] || 'Invalid assignment');
            return Promise.resolve(false);
        }
        if (!cfg.urls || !cfg.urls.save) {
            setStatus('error', 'Save URL missing');
            return Promise.resolve(false);
        }
        setStatus('pending', 'Saving…');
        return fetch(cfg.urls.save, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(next)
        }).then(function (resp) {
            return resp.json().catch(function () { return {}; }).then(function (body) {
                if (resp.ok && body.status === 'success') {
                    exercise = next;
                    cfg.exercise = next;
                    setStatus('success', 'Saved');
                    return true;
                }
                setStatus('error', body.detail || 'Save failed');
                return false;
            });
        }).catch(function (err) {
            setStatus('error', err.message || 'Save failed');
            return false;
        });
    }

    function authorSaveAndLearner() {
        authorSave().then(function (ok) {
            if (!ok) return;
            var url = (cfg.urls && cfg.urls.learner) || 'index.php';
            window.location.href = url;
        });
    }

    function authorImportJson() {
        var raw = window.prompt('Paste complete assignment JSON:');
        if (raw === null) return;
        try {
            var parsed = JSON.parse(raw);
            var v = Validation.validateAssignment(parsed);
            if (!v.ok) {
                window.alert('Invalid assignment:\n' + v.errors.join('\n'));
                return;
            }
            exercise = parsed;
            cfg.exercise = parsed;
            renderAuthor();
            setStatus('pending', 'Imported — click Save to store on this placement');
        } catch (e) {
            window.alert('JSON parse error: ' + e.message);
        }
    }

    function authorViewJson() {
        var next = collectExerciseFromForm();
        var text = JSON.stringify(next, null, 2);
        window.prompt('Assignment JSON (copy):', text);
    }

    function authorRunPreview() {
        var next = collectExerciseFromForm();
        exercise = next;
        var preview = $('#previewHost');
        try {
            var files = {
                html: next.files.html.starter,
                css: next.files.css.starter,
                javascript: next.files.javascript.starter
            };
            Runtime.runInto(preview, files, { exercise: next });
            setStatus('success', 'Reference/starter preview running');
        } catch (e) {
            setStatus('error', e.message || String(e));
        }
    }

    function renderAuthor() {
        var app = $('#app');
        app.innerHTML = '';
        var titleEl = $('#exerciseTitle');
        if (titleEl) titleEl.textContent = (exercise.title || 'Edit assignment') + ' (Edit)';

        var meta = el('div', { className: 'author-meta' });
        meta.appendChild(labelInput('Title', 'auth-title', exercise.title || ''));
        meta.appendChild(labelInput('Id', 'auth-id', exercise.id || ''));
        var promptLabel = el('label', { text: 'Prompt (HTML)' });
        var promptTa = el('textarea', { id: 'auth-prompt', rows: '6' });
        promptTa.value = exercise.prompt || '';
        promptLabel.appendChild(promptTa);
        meta.appendChild(promptLabel);
        app.appendChild(meta);

        FILE_KEYS.forEach(function (key) {
            var f = (exercise.files && exercise.files[key]) || { mode: 'hidden', starter: '' };
            var block = el('section', { className: 'author-file' });
            block.appendChild(el('h2', { text: key.toUpperCase() }));
            var modeLabel = el('label', { text: 'Mode' });
            var sel = el('select', { id: 'auth-mode-' + key });
            ['editable', 'readonly', 'hidden'].forEach(function (m) {
                var opt = el('option', { value: m, text: m });
                if (f.mode === m) opt.selected = true;
                sel.appendChild(opt);
            });
            modeLabel.appendChild(sel);
            block.appendChild(modeLabel);
            var starterLabel = el('label', { text: 'Starter' });
            var ta = el('textarea', {
                className: 'code',
                id: 'auth-starter-' + key,
                rows: '10'
            });
            ta.value = f.starter || '';
            starterLabel.appendChild(ta);
            block.appendChild(starterLabel);
            var solLabel = el('label', { text: 'Reference solution (optional, instructor Load solution)' });
            var solTa = el('textarea', {
                className: 'code',
                id: 'auth-solution-' + key,
                rows: '8'
            });
            solTa.value = (exercise.solution && typeof exercise.solution[key] === 'string')
                ? exercise.solution[key] : '';
            solLabel.appendChild(solTa);
            block.appendChild(solLabel);
            app.appendChild(block);
        });

        var testsNote = el('p', { className: 'muted' });
        testsNote.innerHTML = 'Tests and advanced fields: use <strong>View JSON</strong> / '
            + '<strong>Import JSON</strong>. Phase 1 authoring focuses on metadata and starters.';
        app.appendChild(testsNote);

        var actions = el('div', { className: 'btn-row' });
        var btnSave = el('button', {
            type: 'button', className: 'btn btn-primary', text: 'Save'
        });
        var btnPreview = el('button', {
            type: 'button', className: 'btn btn-secondary', text: 'Run starter preview'
        });
        var btnView = el('button', {
            type: 'button', className: 'btn btn-ghost', text: 'View JSON'
        });
        var btnImport = el('button', {
            type: 'button', className: 'btn btn-ghost', text: 'Import JSON'
        });
        statusEl = el('span', { className: 'status status-pending' });
        actions.appendChild(btnSave);
        actions.appendChild(btnPreview);
        actions.appendChild(btnView);
        actions.appendChild(btnImport);
        actions.appendChild(statusEl);
        app.appendChild(actions);

        var previewWrap = el('section', { className: 'preview-block' });
        previewWrap.appendChild(el('h2', { text: 'Preview' }));
        previewWrap.appendChild(el('div', { id: 'previewHost', className: 'preview-host' }));
        app.appendChild(previewWrap);

        var consoleWrap = el('section', {
            className: 'console-block',
            id: 'consolePanel'
        });
        consoleWrap.appendChild(el('h2', { text: 'Console' }));
        var consoleList = el('div', { className: 'console-list' });
        consoleList.appendChild(el('div', {
            className: 'console-empty',
            text: 'Console output appears here when the preview runs.'
        }));
        consoleWrap.appendChild(consoleList);
        app.appendChild(consoleWrap);
        if (Console && Console.bind) Console.bind(consoleWrap);

        var footer = el('section', { className: 'author-footer' });
        footer.appendChild(el('p', {
            className: 'muted',
            text: 'Save changes, or save and open the student experience.'
        }));
        var footerRow = el('div', { className: 'btn-row' });
        var btnFooterSave = el('button', {
            type: 'button',
            className: 'btn btn-secondary',
            id: 'btnFooterSave',
            text: 'Save'
        });
        var btnSaveLearner = el('button', {
            type: 'button',
            className: 'btn btn-primary',
            id: 'btnSaveAndLearner',
            text: 'Save and Switch to Learner view'
        });
        footerRow.appendChild(btnFooterSave);
        footerRow.appendChild(btnSaveLearner);
        footer.appendChild(footerRow);
        app.appendChild(footer);

        btnSave.addEventListener('click', authorSave);
        btnFooterSave.addEventListener('click', authorSave);
        btnSaveLearner.addEventListener('click', authorSaveAndLearner);
        btnPreview.addEventListener('click', authorRunPreview);
        btnView.addEventListener('click', authorViewJson);
        btnImport.addEventListener('click', authorImportJson);
    }

    function labelInput(labelText, id, value) {
        var label = el('label', { text: labelText });
        var input = el('input', { id: id, type: 'text' });
        input.value = value;
        label.appendChild(input);
        return label;
    }

    // ---- Boot ----

    if (cfg.mode === 'author' && cfg.isInstructor) {
        renderAuthor();
    } else {
        renderLearner();
    }
})();
