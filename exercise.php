<?php
/**
 * Assignment defaults, built-in catalog resolution, and first-launch preload.
 *
 * Priority when loading a placement:
 *   1. Valid assignment already in lti_link.json
 *   2. Refresh from catalog when Settings key is set and JSON is missing / stale
 *   3. Full assignment in LTI custom_config / ?inherit=
 *   4. Empty stub
 */

require_once __DIR__ . '/assignments.php';

use \Tsugi\Core\LTIX;
use \Tsugi\Core\Settings;
use \Tsugi\Util\U;
use \Tsugi\UI\Lessons;

/**
 * Cache-bust token for main-thread JS/CSS.
 */
function webgrader_asset_bust() {
    static $bust = null;
    if ($bust !== null) {
        return $bust;
    }
    $files = array(
        __DIR__ . '/js/webgrader.js',
        __DIR__ . '/js/runtime.js',
        __DIR__ . '/js/tests.js',
        __DIR__ . '/js/validation.js',
        __DIR__ . '/js/html-validate.js',
        __DIR__ . '/js/css-validate.js',
        __DIR__ . '/js/axe-validate.js',
        __DIR__ . '/js/console-capture.js',
        __DIR__ . '/css/webgrader.css',
    );
    $parts = array();
    foreach ($files as $path) {
        $parts[] = is_readable($path) ? md5_file($path) : '';
    }
    $bust = substr(md5(implode('|', $parts)), 0, 12);
    return $bust;
}

/**
 * Empty assignment when nothing is configured yet.
 */
function webgrader_empty_exercise() {
    return array(
        'type' => 'webgrader',
        'schema_version' => 1,
        'id' => 'empty',
        'assignment_version' => 1,
        'title' => '',
        'prompt' => '<p>No assignment configured yet. Instructors: open Settings and choose an assignment, or use Edit to author one.</p>',
        'files' => array(
            'html' => array(
                'mode' => 'editable',
                'starter' => "<!DOCTYPE html>\n<html>\n<head>\n  <title>Untitled</title>\n</head>\n<body>\n\n</body>\n</html>\n",
            ),
            'css' => array(
                'mode' => 'hidden',
                'starter' => '',
            ),
            'javascript' => array(
                'mode' => 'hidden',
                'starter' => '',
            ),
        ),
        'runtime' => array(
            'preview' => true,
            'storage' => 'reset_on_run',
        ),
        'assets' => array(),
        'tests' => array(),
        'grading' => array(
            'maximum_points' => 0,
            'partial_credit' => true,
        ),
    );
}

/**
 * True if decoded array looks like a WebGrader assignment.
 */
function webgrader_is_valid_exercise($decoded) {
    return is_array($decoded)
        && isset($decoded['type'])
        && $decoded['type'] === 'webgrader'
        && isset($decoded['prompt'], $decoded['files']);
}

/**
 * Decode a JSON string into an assignment array, or null.
 */
function webgrader_decode_exercise_json($raw) {
    if (!$raw || !is_string($raw) || U::isEmpty($raw)) {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (webgrader_is_valid_exercise($decoded)) {
        return $decoded;
    }
    return null;
}

/**
 * Resolve built-in assignment key from link settings / LTI custom / ?exercise=.
 *
 * @return string|null
 */
function webgrader_resolve_exercise_key() {
    global $assignments, $LINK;

    $assn = null;
    if ($LINK) {
        $assn = Settings::linkGetCustom('exercise');
        if ($assn === '0' || $assn === 0 || $assn === false || $assn === '') {
            $assn = null;
        }
    }

    if (!$LINK && isset($_GET['exercise'])) {
        $g = $_GET['exercise'];
        if (is_string($g) && isset($assignments[$g])) {
            $assn = $g;
        }
    }

    if ($assn && isset($assignments[$assn])) {
        return $assn;
    }
    return null;
}

/**
 * Pull full assignment JSON from LTI custom_config, then lessons.json via ?inherit=.
 */
function webgrader_load_custom_exercise() {
    global $CFG;

    $custom = LTIX::ltiCustomGet('config');
    $exercise = webgrader_decode_exercise_json($custom);
    if ($exercise) {
        return $exercise;
    }

    if (isset($_GET['inherit']) && isset($CFG->lessons)) {
        $lessons = new Lessons($CFG->lessons);
        if ($lessons) {
            $lti = $lessons->getLtiByRlid($_GET['inherit']);
            if (isset($lti->custom) && is_array($lti->custom)) {
                foreach ($lti->custom as $c) {
                    if (isset($c->key, $c->json) && $c->key === 'config') {
                        if (is_string($c->json)) {
                            $exercise = webgrader_decode_exercise_json($c->json);
                        } else {
                            $asArray = json_decode(json_encode($c->json), true);
                            if (webgrader_is_valid_exercise($asArray)) {
                                $exercise = $asArray;
                            }
                        }
                        if ($exercise) {
                            return $exercise;
                        }
                    }
                }
            }
        }
    }

    return null;
}

/**
 * Load assignment from link JSON; else custom config; else built-in; else empty.
 *
 * @return array{exercise: array, assignmentKey: ?string}
 */
function webgrader_load_exercise($LINK) {
    $assignmentKey = webgrader_resolve_exercise_key();

    $raw = null;
    if ($LINK && method_exists($LINK, 'getJson')) {
        $raw = $LINK->getJson();
    }
    $existing = webgrader_decode_exercise_json($raw);

    if ($assignmentKey) {
        $builtin = webgrader_builtin_exercise($assignmentKey);
        if ($builtin) {
            $jsonBuiltin = (is_array($existing) && isset($existing['builtin']))
                ? $existing['builtin']
                : null;
            $jsonRev = (is_array($existing) && isset($existing['builtin_rev']))
                ? $existing['builtin_rev']
                : null;
            $fileRev = isset($builtin['builtin_rev']) ? $builtin['builtin_rev'] : null;
            $isCustom = ($jsonRev === 'custom');
            $stale = !$isCustom && $fileRev && $jsonRev !== $fileRev;
            if (!$existing || $jsonBuiltin !== $assignmentKey || $stale) {
                if ($LINK && method_exists($LINK, 'setJson') && !empty($LINK->id)) {
                    $LINK->setJson(json_encode($builtin));
                }
                return array(
                    'exercise' => $builtin,
                    'assignmentKey' => $assignmentKey,
                );
            }
            return array(
                'exercise' => $existing,
                'assignmentKey' => $assignmentKey,
            );
        }
    }

    if ($existing) {
        return array(
            'exercise' => $existing,
            'assignmentKey' => $assignmentKey,
        );
    }

    $fromCustom = webgrader_load_custom_exercise();
    if ($fromCustom) {
        if ($LINK && method_exists($LINK, 'setJson') && !empty($LINK->id)) {
            $LINK->setJson(json_encode($fromCustom));
        }
        return array(
            'exercise' => $fromCustom,
            'assignmentKey' => $assignmentKey,
        );
    }

    return array(
        'exercise' => webgrader_empty_exercise(),
        'assignmentKey' => $assignmentKey,
    );
}

/**
 * Decode a student submission from result JSON, or null.
 */
function webgrader_decode_submission($raw) {
    if (!$raw || !is_string($raw) || U::isEmpty($raw)) {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }
    // Full submission object
    if (isset($decoded['schema']) && $decoded['schema'] === 'webgrader-submission'
        && isset($decoded['files']) && is_array($decoded['files'])) {
        return $decoded;
    }
    // Nested under a key (future-proof)
    if (isset($decoded['webgrader_submission']) && is_array($decoded['webgrader_submission'])) {
        $sub = $decoded['webgrader_submission'];
        if (isset($sub['files']) && is_array($sub['files'])) {
            return $sub;
        }
    }
    return null;
}

/**
 * Load current learner submission from RESULT JSON.
 */
function webgrader_load_submission($RESULT) {
    if (!$RESULT || !method_exists($RESULT, 'getJson')) {
        return null;
    }
    return webgrader_decode_submission($RESULT->getJson());
}
