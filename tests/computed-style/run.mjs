#!/usr/bin/env node
/**
 * Regression tests: computed CSS properties vs transformed geometry.
 *
 * Run: node tests/computed-style/run.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '../..');
const testsJsPath = path.join(repoRoot, 'js/tests.js');

let failed = 0;
let passed = 0;

function assertTrue(cond, message) {
  if (cond) {
    passed += 1;
    console.log('  PASS  ' + message);
    return;
  }
  failed += 1;
  console.log('  FAIL  ' + message);
}

function loadWebGraderTests() {
  const code = fs.readFileSync(testsJsPath, 'utf8');
  const sandbox = { console };
  sandbox.window = sandbox;
  vm.createContext(sandbox);
  vm.runInContext(code, sandbox);
  return sandbox.WebGraderTests;
}

function chromePath() {
  const candidates = [
    process.env.CHROME_PATH,
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/Applications/Chromium.app/Contents/MacOS/Chromium',
    'google-chrome',
    'chromium',
    'chromium-browser',
  ].filter(Boolean);
  for (const c of candidates) {
    if (c.includes('/') && fs.existsSync(c)) return c;
    if (!c.includes('/')) {
      const which = spawnSync('which', [c], { encoding: 'utf8' });
      if (which.status === 0) return which.stdout.trim();
    }
  }
  return null;
}

console.log('cssNumericEquals / findTransform (vm)');
const T = loadWebGraderTests();
assertTrue(!!T && typeof T.cssNumericEquals === 'function', 'exports cssNumericEquals');
assertTrue(T.cssNumericEquals('2px', '2px') === true, '2px equals 2px');
assertTrue(T.cssNumericEquals('2.000px', '2px') === true, '2.000px equals 2px');
assertTrue(T.cssNumericEquals('1.995px', '2px') === true, '1.995px within 0.01 of 2px');
assertTrue(T.cssNumericEquals('1.6667px', '2px') === false, '1.6667px is not a 2px computed border');
assertTrue(T.cssNumericEquals('solid', 'solid') === null, 'non-numeric returns null');
assertTrue(T.computedValuesEqual({}, 'border-top-width', '2px', '2px') === true,
  'computedValuesEqual border-top-width 2px');
assertTrue(T.computedValuesEqual({}, 'border-top-width', '1.6667px', '2px') === false,
  'does not treat scaled geometry 1.6667px as 2px');
assertTrue(T.computedValuesEqual({}, 'border-top-style', 'solid', 'solid') === true,
  'style string still exact-matches');

const win = {
  getComputedStyle(el) {
    return { transform: el._transform || 'none' };
  },
};
const stage = {
  nodeType: 1,
  tagName: 'DIV',
  id: 'stage',
  parentElement: null,
  ownerDocument: { defaultView: win },
  _transform: 'matrix(0.833333, 0, 0, 0.833333, 0, 0)',
};
const box = {
  nodeType: 1,
  tagName: 'P',
  id: 'box',
  parentElement: stage,
  ownerDocument: { defaultView: win },
  _transform: 'none',
};
const found = T.findTransform(box, win);
assertTrue(!!found, 'findTransform locates ancestor transform');
assertTrue(found && found.element === stage, 'transform is on #stage, not the target');
assertTrue(found && String(found.transform).indexOf('0.833333') !== -1,
  'reports the scale matrix string');

console.log('scaled 2px solid red border (headless Chrome)');
const chrome = chromePath();
if (!chrome) {
  failed += 1;
  console.log('  FAIL  Chrome/Chromium not found (set CHROME_PATH)');
} else {
  const htmlPath = path.join(__dirname, 'scaled-border.html');
  const result = spawnSync(chrome, [
    '--headless=new',
    '--disable-gpu',
    '--allow-file-access-from-files',
    '--virtual-time-budget=4000',
    '--dump-dom',
    'file://' + htmlPath,
  ], { encoding: 'utf8', timeout: 20000 });

  const html = (result.stdout || '') + (result.stderr || '');
  const match = html.match(/<pre id="result">([^<]*)<\/pre>/);
  if (!match) {
    failed += 1;
    console.log('  FAIL  could not read #result from Chrome DOM dump');
    if (result.status !== 0) {
      console.log('         chrome status ' + result.status);
    }
  } else {
    let data;
    try {
      data = JSON.parse(match[1]);
    } catch (e) {
      failed += 1;
      console.log('  FAIL  invalid JSON from #result: ' + (e && e.message ? e.message : e));
      data = null;
    }
    if (data) {
      assertTrue(data.widthCloseToTwo === true, 'getComputedStyle border width is 2px under transform');
      assertTrue(data.gradePass === true, 'computed_styles_equals passes under scale(0.833333)');
      assertTrue(data.widthPass === true, 'computed_style_equals border-top-width 2px passes');
      assertTrue(data.zoomGradePass === true,
        'computed_styles_equals passes under ancestor zoom (used value may be 1.83333px)');
      assertTrue(data.foundZoom && (data.foundZoom.id === 'zoom-stage' || data.foundZoom.zoom),
        'findTransform reports the zoom ancestor');
      assertTrue(data.foundTransform && data.foundTransform.id === 'stage',
        'findTransform identifies #stage');
      assertTrue(data.foundTransform && typeof data.foundTransform.matrixA === 'number'
        && Math.abs(data.foundTransform.matrixA - 0.833333) < 0.001,
        'DOMMatrix parses the ancestor scale');
      assertTrue(String(data.gradeDetail).indexOf('transform on') !== -1,
        'grade detail reports the transform diagnostically');
      assertTrue(data.visualOneSideBorderApprox > 1.5 && data.visualOneSideBorderApprox < 1.8,
        'rendered geometry is ~1.6667px, distinct from computed 2px');
      assertTrue(data.numericRejectsScaledGeometry === true,
        'does not accept 1.6667px as the CSS border width');
    }
  }
}

console.log('');
if (failed > 0) {
  console.log('FAILED: ' + failed + '  PASSED: ' + passed);
  process.exit(1);
}
console.log('All ' + passed + ' assertions passed.');
process.exit(0);
