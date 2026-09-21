/**
 * Optional local computed-style / click validation using jsdom when available.
 *
 * Usage: node local-browser-check.mjs <payload.json>
 * Prints one JSON object to stdout.
 *
 * Looks for jsdom in NODE_PATH, nearby node_modules, or a global install.
 * If jsdom cannot be loaded, prints { unavailable: true, detail: "..." }.
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);

function loadJsdom() {
  const candidates = [
    'jsdom',
    path.resolve(__dirname, '../../node_modules/jsdom'),
    path.resolve(__dirname, '../../../node_modules/jsdom'),
    path.resolve(process.cwd(), 'node_modules/jsdom'),
  ];
  for (const c of candidates) {
    try {
      return require(c);
    } catch {
      // try next
    }
  }
  return null;
}

function isColorProperty(prop) {
  const p = String(prop || '').toLowerCase();
  return p === 'color' || p.includes('color') || p === 'fill' || p === 'stroke';
}

function isOffsetProperty(prop) {
  const p = String(prop || '').toLowerCase();
  return p === 'top' || p === 'right' || p === 'bottom' || p === 'left';
}

function normalizeOffset(value) {
  const v = String(value || '').trim().toLowerCase();
  return v === '0' ? '0px' : v;
}

function normalizeColor(doc, win, value) {
  const raw = String(value || '').trim();
  if (!raw || !doc.body) return raw;
  const probe = doc.createElement('div');
  probe.style.backgroundColor = raw;
  doc.body.appendChild(probe);
  const resolved = win.getComputedStyle(probe).backgroundColor;
  doc.body.removeChild(probe);
  return (resolved || raw).trim();
}

function cssNumericEquals(actual, expected, epsilon = 0.01) {
  const aStr = String(actual == null ? '' : actual).trim();
  const eStr = String(expected == null ? '' : expected).trim();
  if (!/^[-+]?\d/.test(aStr) || !/^[-+]?\d/.test(eStr)) return null;
  const aNum = parseFloat(aStr);
  const eNum = parseFloat(eStr);
  if (!Number.isFinite(aNum) || !Number.isFinite(eNum)) return null;
  let aUnit = aStr.replace(/^[-+]?\d*\.?\d+(e[-+]?\d+)?/i, '').trim().toLowerCase();
  let eUnit = eStr.replace(/^[-+]?\d*\.?\d+(e[-+]?\d+)?/i, '').trim().toLowerCase();
  if (aUnit === '' && eUnit === 'px') aUnit = 'px';
  if (eUnit === '' && aUnit === 'px') eUnit = 'px';
  if (aUnit !== eUnit) return false;
  return Math.abs(aNum - eNum) < epsilon;
}

function parseDomMatrix(win, transform) {
  if (!transform || transform === 'none') return null;
  const DM = win.DOMMatrix;
  if (typeof DM !== 'function') return null;
  try {
    return new DM(transform);
  } catch {
    return null;
  }
}

function findTransform(win, element) {
  let el = element;
  while (el && el.nodeType === 1) {
    const style = win.getComputedStyle(el);
    const transform = style && style.transform;
    if (transform && transform !== 'none') {
      return {
        element: el,
        transform,
        matrix: parseDomMatrix(win, transform),
      };
    }
    const zoom = style && style.zoom;
    if (zoom && zoom !== 'normal') {
      const zNum = parseFloat(zoom);
      if (Number.isFinite(zNum) && Math.abs(zNum - 1) > 0.001) {
        return {
          element: el,
          transform: 'zoom(' + zoom + ')',
          matrix: null,
          zoom,
        };
      }
    }
    el = el.parentElement;
  }
  return null;
}

function resolveCssValue(doc, win, contextEl, prop, value) {
  const raw = String(value == null ? '' : value).trim();
  if (!raw || !doc.createElement || !win.getComputedStyle) return raw;
  const parent = contextEl && contextEl.nodeType === 1 ? contextEl : doc.body;
  if (!parent) return raw;
  const probe = doc.createElement('wg-probe');
  probe.style.cssText = 'position:absolute;left:-9999px;visibility:hidden;display:block;';
  probe.style.setProperty(prop, raw, 'important');
  const p = String(prop || '').toLowerCase();
  if (p.includes('border') && p.includes('width')) {
    probe.style.setProperty('border-style', 'solid', 'important');
  }
  if (isOffsetProperty(prop)) {
    probe.style.setProperty('position', 'fixed', 'important');
  }
  parent.appendChild(probe);
  const resolved = win.getComputedStyle(probe).getPropertyValue(prop).trim();
  parent.removeChild(probe);
  return resolved || raw;
}

function computedEqual(doc, win, prop, actual, expected, contextEl) {
  let a = String(actual || '').trim();
  let e = String(expected || '').trim();
  if (isColorProperty(prop)) {
    return normalizeColor(doc, win, a) === normalizeColor(doc, win, e);
  }
  if (String(prop || '').toLowerCase() === 'transform') {
    if (a === e) return true;
    const aNone = !a || a === 'none';
    const eNone = !e || e === 'none';
    if (aNone || eNone) return aNone && eNone;
    const am = parseDomMatrix(win, a);
    const em = parseDomMatrix(win, e);
    if (!am || !em) return a === e;
    return Math.abs(am.a - em.a) < 0.01 && Math.abs(am.b - em.b) < 0.01
      && Math.abs(am.c - em.c) < 0.01 && Math.abs(am.d - em.d) < 0.01
      && Math.abs(am.e - em.e) < 0.01 && Math.abs(am.f - em.f) < 0.01;
  }
  if (isOffsetProperty(prop)) {
    a = normalizeOffset(a);
    e = normalizeOffset(e);
  }
  const resolved = resolveCssValue(doc, win, contextEl, prop, e);
  const resolvedOff = isOffsetProperty(prop) ? normalizeOffset(resolved) : resolved;
  const numeric = cssNumericEquals(a, resolvedOff, 0.01);
  if (numeric !== null) return numeric;
  return a === resolvedOff || a === e;
}

function transformNote(win, el) {
  const info = findTransform(win, el);
  if (!info) return '';
  const tag = (info.element.tagName || 'element').toLowerCase();
  const who = info.element.id ? tag + '#' + info.element.id : tag;
  return ' [transform on ' + who + ': ' + info.transform + ']';
}

function runTest(dom, test) {
  const doc = dom.window.document;
  const win = dom.window;
  const type = test.type;

  if (type === 'click_changes_dom') {
    const clickSel = test.click_selector || test.selector || '';
    const assertSel = test.assert_selector
      || (test.then && test.then.selector)
      || '';
    const assertType = test.assert_type
      || (test.then && test.then.type)
      || 'selector_exists';
    const btn = doc.querySelector(clickSel);
    if (!btn) {
      return { pass: false, detail: 'No click target for ' + clickSel };
    }
    btn.click();
    if (assertType === 'selector_not_exists') {
      const ok = !doc.querySelector(assertSel);
      return { pass: ok, detail: ok ? 'Absent after click' : 'Still present after click' };
    }
    if (assertType === 'text_equals') {
      const el = doc.querySelector(assertSel);
      if (!el) return { pass: false, detail: 'No assert target for ' + assertSel };
      const actual = String(el.textContent || '').replace(/\s+/g, ' ').trim();
      const expected = String(test.expected || '').replace(/\s+/g, ' ').trim();
      return {
        pass: actual === expected,
        detail: 'Got "' + actual + '", expected "' + expected + '"',
      };
    }
    const ok = !!doc.querySelector(assertSel);
    return { pass: ok, detail: ok ? 'Found after click' : 'Missing after click' };
  }

  const el = doc.querySelector(test.selector);
  if (!el) {
    return { pass: false, detail: 'No match for ' + test.selector };
  }

  if (type === 'computed_style_equals') {
    const prop = String(test.property || '').trim();
    const actual = win.getComputedStyle(el).getPropertyValue(prop).trim();
    const expected = String(test.expected || '').trim();
    const pass = computedEqual(doc, win, prop, actual, expected, el);
    return {
      pass,
      detail: 'Got "' + actual + '", expected "' + expected + '"' + transformNote(win, el),
    };
  }

  if (type === 'computed_styles_equals') {
    const expected = test.expected || {};
    const cs = win.getComputedStyle(el);
    const mismatches = [];
    for (const prop of Object.keys(expected)) {
      const actual = cs.getPropertyValue(prop).trim();
      const exp = String(expected[prop]).trim();
      if (!computedEqual(doc, win, prop, actual, exp, el)) {
        mismatches.push(prop + ': got "' + actual + '", expected "' + exp + '"');
      }
    }
    const note = transformNote(win, el);
    return {
      pass: mismatches.length === 0,
      detail: (mismatches.length ? mismatches.join('; ') : 'Matched') + note,
    };
  }

  return { pass: false, detail: 'Unhandled browser test type ' + type };
}

function main() {
  const jsonPath = process.argv[2];
  if (!jsonPath) {
    process.stdout.write(JSON.stringify({
      unavailable: true,
      detail: 'missing payload path',
    }));
    return;
  }

  const jsdomMod = loadJsdom();
  if (!jsdomMod || !jsdomMod.JSDOM) {
    process.stdout.write(JSON.stringify({
      unavailable: true,
      detail: 'jsdom not installed (optional; npm i jsdom for local CSS checks)',
    }));
    return;
  }

  let payload;
  try {
    payload = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));
  } catch (e) {
    process.stdout.write(JSON.stringify({
      unavailable: true,
      detail: 'could not read payload: ' + (e && e.message ? e.message : String(e)),
    }));
    return;
  }

  const { JSDOM } = jsdomMod;
  const passed = [];
  const failed = [];
  const errors = [];

  for (const test of payload.tests || []) {
    try {
      // Fresh DOM per test so click/state does not leak.
      const dom = new JSDOM(String(payload.html || ''), {
        runScripts: 'dangerously',
        resources: 'usable',
      });
      const result = runTest(dom, test);
      if (result.pass) {
        passed.push(test.id);
      } else {
        failed.push({ id: test.id, detail: result.detail || 'failed' });
      }
      dom.window.close();
    } catch (e) {
      errors.push({
        code: 'BROWSER_VALIDATION_EXCEPTION',
        message: 'Test "' + test.id + '": ' + (e && e.message ? e.message : String(e)),
      });
    }
  }

  process.stdout.write(JSON.stringify({ passed, failed, errors }));
}

main();
