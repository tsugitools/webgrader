<?php
/**
 * Phase 1 Udemy exporter: assignment JSON → in-memory package members + ZIP.
 */
require_once __DIR__ . '/HtmlBuilder.php';
require_once __DIR__ . '/TestEmitter.php';
require_once __DIR__ . '/CompatibilityReport.php';
require_once __DIR__ . '/LocalValidator.php';
require_once __DIR__ . '/ZipBuilder.php';

class UdemyExporter
{
    const SCHEMA_VERSION = 1;
    const EXPORT_VERSION = 1;

    /**
     * @param array $assignment Decoded assignment JSON
     * @param array $options strict (bool), allow_starter_pass_all (bool), repo_root (string)
     * @return object Package with members, compatibility, warnings, errors, zip_bytes|null
     */
    public static function build(array $assignment, array $options = array())
    {
        $strict = !empty($options['strict']);
        $allowStarterPassAll = !empty($options['allow_starter_pass_all']);
        $repoRoot = isset($options['repo_root'])
            ? rtrim((string) $options['repo_root'], '/')
            : dirname(dirname(__DIR__));

        $warnings = array();
        $errors = array();
        $convertedExtras = array();

        self::validateAssignmentShape($assignment, $errors);

        $title = isset($assignment['title']) ? (string) $assignment['title'] : 'Exercise';
        $sourceId = isset($assignment['id']) ? (string) $assignment['id'] : 'unknown';

        $starterFiles = self::resolveFileSources($assignment, 'starter', $errors);
        $solutionFiles = self::resolveFileSources($assignment, 'solution', $errors);

        self::collectModeWarnings($assignment, $warnings);
        self::checkAssetsPhase1($assignment, $warnings, $errors, $repoRoot);

        $tests = isset($assignment['tests']) && is_array($assignment['tests'])
            ? $assignment['tests']
            : array();

        $emitted = UdemyTestEmitter::emit($tests);
        $warnings = array_merge($warnings, $emitted['warnings']);
        $errors = array_merge($errors, $emitted['errors']);

        $starterHtml = '';
        $solutionHtml = '';
        if (count($errors) === 0) {
            $starterHtml = UdemyHtmlBuilder::build($starterFiles, $title);
            $solutionHtml = UdemyHtmlBuilder::build($solutionFiles, $title);
            $convertedExtras[] = 'Starter HTML/CSS/JavaScript';
            $convertedExtras[] = 'Reference solution';
        }

        $instructions = self::buildInstructionsMarkdown($assignment);
        $convertedExtras[] = 'Instructions';

        $hintsMd = self::buildHintsMarkdown($assignment);
        if ($hintsMd !== null) {
            $convertedExtras[] = self::countHints($assignment) . ' hint'
                . (self::countHints($assignment) === 1 ? '' : 's');
        }

        $solutionExplanation = self::buildSolutionExplanationMarkdown($assignment);
        if ($solutionExplanation !== null) {
            $convertedExtras[] = 'Solution explanation';
        }

        // Local validation only when generation succeeded so far.
        if (count($errors) === 0 && count($emitted['converted']) > 0) {
            $solVal = UdemyLocalValidator::run($solutionHtml, $emitted['converted'], $tests);
            $errors = array_merge($errors, $solVal['errors']);
            if (count($solVal['failed']) > 0) {
                foreach ($solVal['failed'] as $fail) {
                    $errors[] = array(
                        'code' => 'SOLUTION_FAILED_TEST',
                        'message' => 'Reference solution failed test "' . $fail['id']
                            . '": ' . $fail['detail'],
                    );
                }
            } else {
                $convertedExtras[] = 'Local solution validation';
            }

            $startVal = UdemyLocalValidator::run($starterHtml, $emitted['converted'], $tests);
            $errors = array_merge($errors, $startVal['errors']);
            $allPass = count($startVal['failed']) === 0
                && count($startVal['passed']) === count($emitted['converted'])
                && count($emitted['converted']) > 0;
            if ($allPass && !$allowStarterPassAll) {
                $errors[] = array(
                    'code' => 'STARTER_PASSES_ALL',
                    'message' => 'Starter already passes every exported test.'
                        . ' Provide incomplete starter content, or pass --allow-starter-pass-all.',
                );
            } elseif (!$allPass) {
                $convertedExtras[] = 'Starter fails at least one test (expected)';
            }
        } elseif (count($errors) === 0 && count($emitted['converted']) === 0) {
            $warnings[] = array(
                'code' => 'NO_TESTS',
                'message' => 'Assignment has no convertible tests.',
            );
        }

        if ($strict && count($warnings) > 0 && count($errors) === 0) {
            foreach ($warnings as $w) {
                $errors[] = array(
                    'code' => 'STRICT_' . (isset($w['code']) ? $w['code'] : 'WARNING'),
                    'message' => 'Strict mode: ' . (isset($w['message']) ? $w['message'] : (string) $w),
                );
            }
        }

        $members = array();
        if (count($errors) === 0) {
            $members['starter.html'] = $starterHtml;
            $members['solution.html'] = $solutionHtml;
            $members['evaluation.js'] = $emitted['js'];
            $members['instructions.md'] = $instructions;
            if ($hintsMd !== null) {
                $members['hints.md'] = $hintsMd;
            }
            if ($solutionExplanation !== null) {
                $members['solution-explanation.md'] = $solutionExplanation;
            }
        }

        $ok = count($errors) === 0;

        // On failure, keep only report artifacts on the return object (no ZIP).
        if (!$ok) {
            $members = array();
        }

        $report = UdemyCompatibilityReport::build(array(
            'source_assignment' => $sourceId,
            'generated_files' => array_merge(array_keys($members), array(
                'manifest.json',
                'COMPATIBILITY.md',
            )),
            'converted' => $emitted['converted'],
            'converted_extras' => $convertedExtras,
            'warnings' => $warnings,
            'errors' => $errors,
        ));

        $members['manifest.json'] = json_encode(
            $report['manifest'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . "\n";
        $members['COMPATIBILITY.md'] = $report['markdown'];
        $report['manifest']['generated_files'] = array_keys($members);
        $members['manifest.json'] = json_encode(
            $report['manifest'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . "\n";

        $zipBytes = null;
        if ($ok) {
            $zipBytes = UdemyZipBuilder::build($members);
        }

        return (object) array(
            'ok' => $ok,
            'compatibility' => $report['level'],
            'members' => $members,
            'warnings' => $warnings,
            'errors' => $errors,
            'converted_tests' => $emitted['converted'],
            'zip_bytes' => $zipBytes,
            'download_name' => self::downloadName($assignment),
        );
    }

    /**
     * Load assignment JSON from a path relative to repo or absolute.
     *
     * @return array
     */
    public static function loadAssignmentFile($path, $repoRoot = null)
    {
        if ($repoRoot === null) {
            $repoRoot = dirname(dirname(__DIR__));
        }
        $full = $path;
        if ($path !== '' && $path[0] !== '/') {
            $full = rtrim($repoRoot, '/') . '/' . ltrim($path, '/');
        }
        if (!is_readable($full)) {
            throw new RuntimeException('Assignment file not readable: ' . $path);
        }
        $raw = file_get_contents($full);
        if ($raw === false) {
            throw new RuntimeException('Could not read assignment file: ' . $path);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON in assignment file: ' . $path);
        }
        return $data;
    }

    private static function validateAssignmentShape(array $assignment, array &$errors)
    {
        if (!isset($assignment['type']) || $assignment['type'] !== 'webgrader') {
            $errors[] = array(
                'code' => 'INVALID_TYPE',
                'message' => 'Assignment type must be "webgrader".',
            );
        }
        $schema = isset($assignment['schema_version']) ? (int) $assignment['schema_version'] : 0;
        if ($schema !== self::SCHEMA_VERSION) {
            $errors[] = array(
                'code' => 'UNSUPPORTED_SCHEMA',
                'message' => 'Unsupported schema_version '
                    . (isset($assignment['schema_version']) ? $assignment['schema_version'] : '(missing)')
                    . '; expected ' . self::SCHEMA_VERSION . '.',
            );
        }
        if (!isset($assignment['files']) || !is_array($assignment['files'])) {
            $errors[] = array(
                'code' => 'MISSING_FILES',
                'message' => 'Assignment is missing files object.',
            );
        }
    }

    /**
     * Resolve starter or solution sources from WebGrader's real JSON shape.
     *
     * Starter: files.{html,css,javascript}.starter
     * Solution: solution.{html,css,javascript} OR files.*.solution
     *
     * @param string $which starter|solution
     * @return array{html:string,css:string,javascript:string}
     */
    private static function resolveFileSources(array $assignment, $which, array &$errors)
    {
        $out = array('html' => '', 'css' => '', 'javascript' => '');
        $files = isset($assignment['files']) && is_array($assignment['files'])
            ? $assignment['files']
            : array();

        foreach (array('html', 'css', 'javascript') as $lang) {
            $file = isset($files[$lang]) && is_array($files[$lang]) ? $files[$lang] : array();
            if ($which === 'starter') {
                $out[$lang] = isset($file['starter']) ? (string) $file['starter'] : '';
            } else {
                if (isset($assignment['solution'][$lang])) {
                    $out[$lang] = (string) $assignment['solution'][$lang];
                } elseif (isset($file['solution'])) {
                    $out[$lang] = (string) $file['solution'];
                } else {
                    // Fall back to starter for hidden/readonly unchanged files.
                    $mode = isset($file['mode']) ? (string) $file['mode'] : 'editable';
                    if ($mode === 'hidden' || $mode === 'readonly') {
                        $out[$lang] = isset($file['starter']) ? (string) $file['starter'] : '';
                    }
                }
            }
        }

        if ($which === 'starter') {
            $hasAny = trim($out['html'] . $out['css'] . $out['javascript']) !== '';
            if (!$hasAny) {
                $errors[] = array(
                    'code' => 'MISSING_STARTER',
                    'message' => 'Required starter content is missing.',
                );
            }
        } else {
            // Solution must include HTML for Phase 1 HTML exercises; CSS/JS may be empty.
            if (trim($out['html']) === '') {
                $errors[] = array(
                    'code' => 'MISSING_SOLUTION',
                    'message' => 'Required solution HTML is missing.',
                );
            }
        }

        return $out;
    }

    private static function collectModeWarnings(array $assignment, array &$warnings)
    {
        $files = isset($assignment['files']) && is_array($assignment['files'])
            ? $assignment['files']
            : array();
        foreach (array('html', 'css', 'javascript') as $lang) {
            if (!isset($files[$lang]['mode'])) {
                continue;
            }
            $mode = (string) $files[$lang]['mode'];
            if ($mode === 'readonly') {
                $warnings[] = array(
                    'code' => 'READONLY_NOT_PRESERVED',
                    'message' => strtoupper($lang)
                        . ' is read-only in WebGrader but may be editable in Udemy.',
                );
            } elseif ($mode === 'hidden' && trim((string) (isset($files[$lang]['starter']) ? $files[$lang]['starter'] : '')) !== '') {
                $warnings[] = array(
                    'code' => 'HIDDEN_INCLUDED',
                    'message' => strtoupper($lang)
                        . ' is hidden in WebGrader but was included in the combined Udemy HTML.',
                );
            }
        }
    }

    private static function checkAssetsPhase1(array $assignment, array &$warnings, array &$errors, $repoRoot)
    {
        $assets = isset($assignment['assets']) && is_array($assignment['assets'])
            ? $assignment['assets']
            : array();
        if (count($assets) === 0) {
            return;
        }

        // Phase 1: do not copy assets; report required assets as unsupported.
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $path = isset($asset['path']) ? (string) $asset['path'] : '';
            $required = !isset($asset['required']) || $asset['required'];
            if ($path === '') {
                continue;
            }
            if (strpos($path, '..') !== false || strpos($path, 'assignments/') !== 0) {
                $errors[] = array(
                    'code' => 'INVALID_ASSET_PATH',
                    'message' => 'Asset path rejected: ' . $path,
                );
                continue;
            }
            $full = rtrim($repoRoot, '/') . '/' . $path;
            if ($required && !is_readable($full)) {
                $errors[] = array(
                    'code' => 'MISSING_ASSET',
                    'message' => 'Required asset missing: ' . $path,
                );
                continue;
            }
            if ($required) {
                $errors[] = array(
                    'code' => 'ASSETS_NOT_EXPORTED',
                    'message' => 'Phase 1 does not export assets (' . $path
                        . '). Remove assets or wait for Phase 3.',
                );
            } else {
                $warnings[] = array(
                    'code' => 'OPTIONAL_ASSET_SKIPPED',
                    'message' => 'Optional asset not exported: ' . $path,
                );
            }
        }
    }

    private static function buildInstructionsMarkdown(array $assignment)
    {
        $lines = array();
        $title = isset($assignment['title']) ? (string) $assignment['title'] : 'Exercise';
        $lines[] = '# ' . $title;
        $lines[] = '';

        if (!empty($assignment['learning_objective'])) {
            $lines[] = '## Learning objective';
            $lines[] = '';
            $lines[] = trim((string) $assignment['learning_objective']);
            $lines[] = '';
        }

        $lines[] = '## Instructions';
        $lines[] = '';
        if (!empty($assignment['instructions'])) {
            $lines[] = trim((string) $assignment['instructions']);
        } elseif (!empty($assignment['prompt'])) {
            $lines[] = self::promptHtmlToMarkdown((string) $assignment['prompt']);
        } else {
            $lines[] = '(No instructions provided.)';
        }
        $lines[] = '';

        if (!empty($assignment['estimated_minutes'])) {
            $lines[] = '_Estimated time: ' . (int) $assignment['estimated_minutes'] . ' minutes._';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Convert WebGrader prompt HTML into readable Markdown-ish plain text.
     */
    private static function promptHtmlToMarkdown($html)
    {
        $html = (string) $html;
        $html = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html);
        $html = preg_replace('/<\s*\/\s*p\s*>/i', "\n\n", $html);
        $html = preg_replace('/<\s*\/\s*li\s*>/i', "\n", $html);
        $html = preg_replace('/<\s*li[^>]*>/i', '- ', $html);
        $html = preg_replace('/<\s*\/\s*(ul|ol)\s*>/i', "\n", $html);
        $html = preg_replace('/<\s*code[^>]*>/i', '`', $html);
        $html = preg_replace('/<\s*\/\s*code\s*>/i', '`', $html);
        $html = preg_replace('/<\s*strong[^>]*>/i', '**', $html);
        $html = preg_replace('/<\s*\/\s*strong\s*>/i', '**', $html);
        $html = preg_replace('/<\s*em[^>]*>/i', '_', $html);
        $html = preg_replace('/<\s*\/\s*em\s*>/i', '_', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/ *\n */", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", trim($text));
        return $text;
    }

    private static function buildHintsMarkdown(array $assignment)
    {
        if (empty($assignment['hints']) || !is_array($assignment['hints'])) {
            return null;
        }
        $lines = array('# Hints', '');
        $i = 1;
        foreach ($assignment['hints'] as $hint) {
            $lines[] = $i . '. ' . trim((string) $hint);
            $i++;
        }
        $lines[] = '';
        return implode("\n", $lines);
    }

    private static function countHints(array $assignment)
    {
        return empty($assignment['hints']) || !is_array($assignment['hints'])
            ? 0
            : count($assignment['hints']);
    }

    private static function buildSolutionExplanationMarkdown(array $assignment)
    {
        if (empty($assignment['solution_explanation'])) {
            return null;
        }
        return "# Solution explanation\n\n"
            . trim((string) $assignment['solution_explanation']) . "\n";
    }

    private static function downloadName(array $assignment)
    {
        $id = isset($assignment['id']) ? (string) $assignment['id'] : 'assignment';
        $slug = preg_replace('/[^a-zA-Z0-9]+/', '-', $id);
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'assignment';
        }
        return strtolower($slug) . '-udemy.zip';
    }
}
