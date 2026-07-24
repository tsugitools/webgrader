<?php
/**
 * Instructor endpoint: preview or download a Udemy export ZIP for the current assignment.
 *
 * POST JSON body:
 *   { "exercise": { ...assignment... }, "action": "preview"|"download" }
 *
 * preview  → application/json compatibility report (no ZIP)
 * download → application/zip on success, or application/json errors
 */
require_once "../config.php";
require_once "exercise.php";
require_once __DIR__ . '/export/udemy/Exporter.php';

use \Tsugi\Core\LTIX;

$LAUNCH = LTIX::requireData();

if (!$USER->instructor) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(array('status' => 'failure', 'detail' => 'Instructor role required'));
    return;
}

$raw = file_get_contents('php://input');
$body = $raw ? json_decode($raw, true) : null;
$exercise = null;
$action = 'preview';

if (is_array($body)) {
    if (isset($body['exercise']) && is_array($body['exercise'])) {
        $exercise = $body['exercise'];
    } elseif (isset($body['type']) && $body['type'] === 'webgrader') {
        $exercise = $body;
    }
    if (!empty($body['action'])) {
        $action = (string) $body['action'];
    }
}

if (!$exercise && isset($_POST['exercise'])) {
    $exercise = json_decode($_POST['exercise'], true);
    if (!empty($_POST['action'])) {
        $action = (string) $_POST['action'];
    }
}

// Fall back to the placement JSON when the client sends no body.
if (!$exercise && isset($LINK) && $LINK && method_exists($LINK, 'getJson')) {
    $linkJson = $LINK->getJson();
    if (is_string($linkJson) && strlen($linkJson)) {
        $decoded = json_decode($linkJson, true);
        if (is_array($decoded)) {
            $exercise = $decoded;
        }
    }
}

if (!is_array($exercise)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(array(
        'status' => 'failure',
        'detail' => 'Expected JSON assignment object (exercise).',
    ));
    return;
}

if ($action !== 'download' && $action !== 'preview') {
    $action = 'preview';
}

try {
    $package = UdemyExporter::build($exercise, array(
        'repo_root' => __DIR__,
        'preview_only' => ($action === 'preview'),
    ));
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(array(
        'status' => 'failure',
        'detail' => $e->getMessage(),
    ));
    return;
}

if ($action === 'download') {
    if (!$package->ok || $package->zip_bytes === null) {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(array(
            'status' => 'failure',
            'detail' => 'Export is not downloadable. Review compatibility errors.',
            'ok' => false,
            'compatibility' => $package->compatibility,
            'warnings' => $package->warnings,
            'errors' => $package->errors,
            'tests' => $package->test_statuses,
            'repairs' => $package->repairs,
            'compatibility_md' => $package->compatibility_md,
        ));
        return;
    }
    UdemyZipBuilder::stream($package->zip_bytes, $package->download_name, true);
    return;
}

header('Content-Type: application/json');
echo json_encode(array(
    'status' => 'success',
    'ok' => $package->ok,
    'compatibility' => $package->compatibility,
    'download_name' => $package->download_name,
    'warnings' => $package->warnings,
    'errors' => $package->errors,
    'tests' => $package->test_statuses,
    'repairs' => $package->repairs,
    'compatibility_md' => $package->compatibility_md,
));
