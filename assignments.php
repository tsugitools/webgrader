<?php
/**
 * Built-in WebGrader assignments.
 *
 * Select via Settings → Exercise, or on first launch with LTI custom:
 *
 *   "custom": [ { "key": "exercise", "value": "HeadingsAndParagraphs" } ]
 *
 * Each key maps to a directory under assignments/ containing assignment.json.
 */

$assignments = array(
    'HeadingsAndParagraphs' => 'HTML: Headings and Paragraphs',
    'LinksAndImages' => 'HTML: Links and Images',
    'SimpleList' => 'HTML: A Simple List',
    'ValidatedHtmlPage' => 'HTML: Validated HTML Page',
    'ColoringParagraphs' => 'CSS: Coloring Paragraphs',
);

/**
 * Relative path under this tool to an assignment directory, or null.
 */
function webgrader_builtin_relpath($key) {
    $map = array(
        'HeadingsAndParagraphs' => 'assignments/html/headings-and-paragraphs',
        'LinksAndImages' => 'assignments/html/links-and-images',
        'SimpleList' => 'assignments/html/simple-list',
        'ValidatedHtmlPage' => 'assignments/html/validated-html-page',
        'ColoringParagraphs' => 'assignments/css/coloring-paragraphs',
    );
    return isset($map[$key]) ? $map[$key] : null;
}

/**
 * Load a built-in assignment by catalog key, or null.
 */
function webgrader_builtin_exercise($key) {
    global $assignments;
    if (!$key || !is_string($key) || !isset($assignments[$key])) {
        return null;
    }
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $key)) {
        return null;
    }
    $rel = webgrader_builtin_relpath($key);
    if (!$rel) {
        return null;
    }
    $path = __DIR__ . '/' . $rel . '/assignment.json';
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    $exercise = json_decode($raw, true);
    if (!is_array($exercise)) {
        return null;
    }
    $exercise['builtin'] = $key;
    $exercise['builtin_rev'] = md5_file($path);
    if (!isset($exercise['source']) || !is_array($exercise['source'])) {
        $exercise['source'] = array();
    }
    $exercise['source']['assignment_id'] = isset($exercise['id']) ? $exercise['id'] : $key;
    $exercise['source']['path'] = $rel . '/assignment.json';
    return $exercise;
}

/**
 * Fingerprint of a built-in assignment.json file.
 */
function webgrader_builtin_rev($key) {
    $rel = webgrader_builtin_relpath($key);
    if (!$rel) {
        return null;
    }
    $path = __DIR__ . '/' . $rel . '/assignment.json';
    return is_readable($path) ? md5_file($path) : null;
}
