<?php
/**
 * Instructor endpoint: save assignment JSON into lti_link.json.
 * Body: application/json assignment object, or form field "exercise".
 */
require_once "../config.php";
require_once "exercise.php";

use \Tsugi\Core\LTIX;

header('Content-Type: application/json');

$LAUNCH = LTIX::requireData();

if (!$USER->instructor) {
    http_response_code(403);
    echo json_encode(array('status' => 'failure', 'detail' => 'Instructor role required'));
    return;
}

$raw = file_get_contents('php://input');
$exercise = null;
if ($raw && strlen($raw) > 0) {
    $exercise = json_decode($raw, true);
}
if (!$exercise && isset($_POST['exercise'])) {
    $exercise = json_decode($_POST['exercise'], true);
}

if (!is_array($exercise)) {
    http_response_code(400);
    echo json_encode(array('status' => 'failure', 'detail' => 'Expected JSON assignment object'));
    return;
}

if (!isset($exercise['prompt']) || !strlen(trim($exercise['prompt']))) {
    http_response_code(400);
    echo json_encode(array('status' => 'failure', 'detail' => 'Missing required field: prompt'));
    return;
}

if (!isset($exercise['files']) || !is_array($exercise['files'])) {
    http_response_code(400);
    echo json_encode(array('status' => 'failure', 'detail' => 'Missing required field: files'));
    return;
}

// Normalize required defaults
if (!isset($exercise['type'])) $exercise['type'] = 'webgrader';
if (!isset($exercise['schema_version'])) $exercise['schema_version'] = 1;
if (!isset($exercise['id']) || !strlen(trim((string) $exercise['id']))) {
    $exercise['id'] = 'custom-' . substr(md5(json_encode($exercise)), 0, 8);
}
if (!isset($exercise['assignment_version'])) $exercise['assignment_version'] = 1;
if (!isset($exercise['title'])) $exercise['title'] = '';
if (!isset($exercise['tests']) || !is_array($exercise['tests'])) $exercise['tests'] = array();
if (!isset($exercise['assets']) || !is_array($exercise['assets'])) $exercise['assets'] = array();

// Instructor edits freeze the catalog copy so builtin_rev updates do not overwrite.
$exercise['builtin_rev'] = 'custom';

if ($exercise['type'] !== 'webgrader') {
    http_response_code(400);
    echo json_encode(array('status' => 'failure', 'detail' => 'type must be webgrader'));
    return;
}

$LINK->setJson(json_encode($exercise));
echo json_encode(array('status' => 'success'));
