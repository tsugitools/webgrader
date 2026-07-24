#!/usr/bin/env php
<?php
/**
 * Lightweight regression tests for the Phase 1 Udemy exporter.
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
assert_eq(count($emit['errors']), 1, 'errors on unsupported type');
assert_true(strpos($emit['js'], 'querySelector("h1")') !== false, 'emits selector_exists Jasmine');

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

echo "UdemyExporter headings (html_validate unsupported)\n";
$headings = UdemyExporter::loadAssignmentFile(
    'assignments/html/headings-and-paragraphs/assignment.json',
    $repoRoot
);
$hpkg = UdemyExporter::build($headings, array('repo_root' => $repoRoot));
assert_true(!$hpkg->ok, 'headings export not ok');
assert_eq($hpkg->compatibility, 'unsupported', 'headings unsupported');
assert_true($hpkg->zip_bytes === null, 'no ZIP for unsupported');

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
