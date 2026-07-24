#!/usr/bin/env php
<?php
/**
 * Lightweight regression tests for the Udemy exporter (Phase 1–2).
 *
 * Run: php tests/udemy-export/run.php
 */

$repoRoot = dirname(dirname(__DIR__));
require_once $repoRoot . '/export/udemy/Exporter.php';

$failed = 0;
$passed = 0;

function assert_true($cond, $message)
{
    global $failed, $passed;
    if ($cond) {
        $passed++;
        echo "  PASS  {$message}\n";
        return;
    }
    $failed++;
    echo "  FAIL  {$message}\n";
}

function assert_eq($actual, $expected, $message)
{
    assert_true($actual === $expected, $message . ' (got ' . var_export($actual, true)
        . ', expected ' . var_export($expected, true) . ')');
}

echo "UdemyHtmlBuilder\n";
$frag = UdemyHtmlBuilder::build(array(
    'html' => '<h1>Hi</h1>',
    'css' => 'h1 { color: red; }',
    'javascript' => 'console.log("</script>ok");',
), 'T');
assert_true(strpos($frag, '<style>') !== false, 'wraps fragment with style');
assert_true(strpos($frag, '<\\/script>') !== false || strpos($frag, '<\/script>') !== false,
    'escapes closing script in JS');
assert_true(strpos($frag, '<h1>Hi</h1>') !== false, 'includes HTML fragment');

$full = UdemyHtmlBuilder::build(array(
    'html' => "<!DOCTYPE html><html><head><title>X</title></head><body><p>A</p></body></html>",
    'css' => 'p{}',
    'javascript' => '1;',
));
assert_true(preg_match('/<style>\s*p\{\}\s*<\/style>\s*<\/head>/s', $full) === 1,
    'injects style before </head>');
assert_true(preg_match('/<script>\s*1;\s*<\/script>\s*<\/body>/s', $full) === 1,
    'injects script before </body>');

echo "UdemyTestEmitter\n";
$emit = UdemyTestEmitter::emit(array(
    array(
        'id' => 'has-h1',
        'name' => 'Has h1',
        'type' => 'selector_exists',
        'selector' => 'h1',
        'points' => 1,
    ),
    array(
        'id' => 'bad',
        'name' => 'HTML validates',
        'type' => 'html_validate',
        'points' => 1,
    ),
));
assert_eq(count($emit['converted']), 1, 'converts supported test');
assert_eq(count($emit['errors']), 0, 'validators soft-skip without hard error');
assert_eq(count($emit['warnings']), 1, 'validators emit warning');
assert_true(strpos($emit['js'], 'querySelector("h1")') !== false, 'emits selector_exists Jasmine');
assert_true(strpos($emit['js'], 'describe("HTML structure"') !== false, 'groups HTML suite');

$cssEmit = UdemyTestEmitter::emit(array(
    array(
        'id' => 'bg',
        'name' => 'Yellow',
        'type' => 'computed_style_equals',
        'selector' => '#title',
        'property' => 'background-color',
        'expected' => 'yellow',
        'points' => 1,
    ),
));
assert_true(strpos($cssEmit['js'], 'describe("CSS presentation"') !== false, 'groups CSS suite');
assert_true(strpos($cssEmit['js'], 'wgNormalizeComputed') !== false, 'includes style helpers');

$jsEmit = UdemyTestEmitter::emit(array(
    array(
        'id' => 'add',
        'name' => 'add_two',
        'type' => 'call_function',
        'function' => 'add_two',
        'arg_count' => 2,
        'trials' => 2,
        'expect_op' => 'sum',
        'points' => 1,
    ),
));
assert_true(strpos($jsEmit['js'], 'describe("JavaScript behavior"') !== false, 'groups JS suite');
assert_true(strpos($jsEmit['js'], 'window["add_two"](2, 3)') !== false, 'emits deterministic call');

$raw = UdemyTestEmitter::emit(array(
    array(
        'id' => 'raw',
        'type' => 'jasmine',
        'source' => 'expect(1).toBe(1);',
    ),
));
assert_true(count($raw['errors']) > 0, 'raw jasmine without opt-in errors');

echo "UdemyLocalValidator selectors\n";
assert_eq(
    UdemyLocalValidator::cssToXPath('ul li:first-child'),
    '//ul//li[count(preceding-sibling::*)=0]',
    'ul li:first-child xpath'
);
assert_eq(
    UdemyLocalValidator::cssToXPath('img[src="cat-v1.png"]'),
    '//img[@src="cat-v1.png"]',
    'attribute selector xpath'
);

echo "UdemyExporter simple-list\n";
$assignment = UdemyExporter::loadAssignmentFile(
    'assignments/html/simple-list/assignment.json',
    $repoRoot
);
$package = UdemyExporter::build($assignment, array('repo_root' => $repoRoot));
assert_true($package->ok, 'simple-list export ok');
assert_eq($package->compatibility, 'compatible_with_warnings', 'simple-list has point-weight warning');
assert_true(isset($package->members['starter.html']), 'has starter.html');
assert_true(isset($package->members['solution.html']), 'has solution.html');
assert_true(isset($package->members['evaluation.js']), 'has evaluation.js');
assert_true(isset($package->members['manifest.json']), 'has manifest.json');
assert_true(isset($package->members['COMPATIBILITY.md']), 'has COMPATIBILITY.md');
assert_true($package->zip_bytes !== null && strlen($package->zip_bytes) > 100, 'ZIP bytes present');

$goldenDir = __DIR__ . '/golden/simple-list';
foreach (array('evaluation.js', 'instructions.md', 'COMPATIBILITY.md') as $name) {
    $goldenPath = $goldenDir . '/' . $name;
    if (!is_readable($goldenPath)) {
        assert_true(false, 'missing golden file ' . $name);
        continue;
    }
    $golden = file_get_contents($goldenPath);
    assert_eq($package->members[$name], $golden, 'golden match ' . $name);
}

echo "UdemyExporter headings (html_validate soft-skip)\n";
$headings = UdemyExporter::loadAssignmentFile(
    'assignments/html/headings-and-paragraphs/assignment.json',
    $repoRoot
);
$hpkg = UdemyExporter::build($headings, array('repo_root' => $repoRoot));
assert_true($hpkg->ok, 'headings export ok with soft-skipped validator');
assert_eq($hpkg->compatibility, 'compatible_with_warnings', 'headings compatible with warnings');

echo "UdemyExporter coloring-paragraphs\n";
$coloring = UdemyExporter::loadAssignmentFile(
    'assignments/css/coloring-paragraphs/assignment.json',
    $repoRoot
);
$cpkg = UdemyExporter::build($coloring, array('repo_root' => $repoRoot));
assert_true($cpkg->ok, 'coloring-paragraphs export ok');
assert_true(strpos($cpkg->members['evaluation.js'], 'CSS presentation') !== false, 'coloring has CSS suite');
assert_true(strpos($cpkg->members['solution.html'], 'background-color: yellow') !== false,
    'coloring solution embeds CSS');

echo "UdemyExporter add-two-and-square\n";
$addTwo = UdemyExporter::loadAssignmentFile(
    'assignments/javascript/add-two-and-square/assignment.json',
    $repoRoot
);
$apkg = UdemyExporter::build($addTwo, array('repo_root' => $repoRoot));
assert_true($apkg->ok, 'add-two-and-square export ok');
assert_true(strpos($apkg->members['evaluation.js'], 'JavaScript behavior') !== false, 'JS suite present');
assert_true(strpos($apkg->members['solution.html'], 'function add_two') !== false, 'solution embeds JS');

echo "UdemyExporter link-states (no convertible tests)\n";
$links = UdemyExporter::loadAssignmentFile(
    'assignments/css/link-states/assignment.json',
    $repoRoot
);
$lpkg = UdemyExporter::build($links, array('repo_root' => $repoRoot));
assert_true(!$lpkg->ok, 'link-states export not ok');
assert_eq($lpkg->compatibility, 'unsupported', 'link-states unsupported');

echo "UdemyExporter strict mode\n";
$strict = UdemyExporter::build($assignment, array(
    'repo_root' => $repoRoot,
    'strict' => true,
));
assert_true(!$strict->ok, 'strict mode fails on warnings');

echo "UdemyExporter missing solution\n";
$bad = $assignment;
unset($bad['solution']);
$badPkg = UdemyExporter::build($bad, array('repo_root' => $repoRoot));
assert_true(!$badPkg->ok, 'missing solution fails');
$codes = array();
foreach ($badPkg->errors as $e) {
    $codes[] = $e['code'];
}
assert_true(in_array('MISSING_SOLUTION', $codes, true), 'MISSING_SOLUTION code');

echo "\n";
if ($failed > 0) {
    echo "FAILED: {$failed}  PASSED: {$passed}\n";
    exit(1);
}
echo "All {$passed} assertions passed.\n";
exit(0);
