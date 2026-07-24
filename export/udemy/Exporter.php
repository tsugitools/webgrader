<?php
/**
 * Phase 1–2 Udemy exporter: assignment JSON → in-memory package members + ZIP.
 */
require_once __DIR__ . '/HtmlBuilder.php';
require_once __DIR__ . '/TestEmitter.php';
require_once __DIR__ . '/CompatibilityReport.php';
require_once __DIR__ . '/LocalValidator.php';
require_once __DIR__ . '/ZipBuilder.php';

class UdemyExporter
{
    const SCHEMA_VERSION = 1;
    const EXPORT_VERSION = 2;

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
            $warnings = array_merge($warnings, $solVal['warnings']);
            if (count($solVal['failed']) > 0) {
                foreach ($solVal['failed'] as $fail) {
                    $errors[] = array(
                        'code' => 'SOLUTION_FAILED_TEST',
                        'message' => 'Reference solution failed test "' . $fail['id']
                            . '": ' . $fail['detail'],
                    );
                }
            } elseif (count($solVal['passed']) > 0) {
                $convertedExtras[] = 'Local solution validation';
            }

            $startVal = UdemyLocalValidator::run($starterHtml, $emitted['converted'], $tests);
            $errors = array_merge($errors, $startVal['errors']);
            $warnings = array_merge($warnings, $startVal['warnings']);

            $runCount = count($startVal['passed']) + count($startVal['failed']);
            $allPass = $runCount > 0
                && count($startVal['failed']) === 0
                && count($startVal['passed']) === $runCount;

            if ($allPass && !$allowStarterPassAll) {
                $errors[] = array(
                    'code' => 'STARTER_PASSES_ALL',
                    'message' => 'Starter already passes every locally verified exported test.'
                        . ' Provide incomplete starter content, or pass --allow-starter-pass-all.',
                );
            } elseif (count($startVal['failed']) > 0) {
                $convertedExtras[] = 'Starter fails at least one test (expected)';
            } elseif ($runCount === 0) {
                // Browser-only tests skipped locally: ensure editable starter ≠ solution.
                if (!self::starterDiffersFromSolution($starterFiles, $solutionFiles, $assignment)) {
                    if (!$allowStarterPassAll) {
                        $errors[] = array(
                            'code' => 'STARTER_MATCHES_SOLUTION',
                            'message' => 'Editable starter content matches the solution and'
                                . ' no tests were verified locally. Incomplete starter required.',
                        );
                    }
                } else {
                    $convertedExtras[] = 'Starter differs from solution (local CSS/JS checks skipped)';
                }
            }
        } elseif (count($errors) === 0 && count($emitted['converted']) === 0) {
            $warnings[] = array(
                'code' => 'NO_TESTS',
                'message' => 'Assignment has no convertible tests.',
            );
        }

        $warnings = self::uniqueMessages($warnings);

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
        if ($ok && empty($options['preview_only'])) {
            $zipBytes = UdemyZipBuilder::build($members);
        }

        $testStatuses = self::buildTestStatuses($tests, $emitted);
        $repairs = self::suggestRepairs($errors, $warnings, $testStatuses);

        return (object) array(
            'ok' => $ok,
            'compatibility' => $report['level'],
            'members' => $members,
            'warnings' => $warnings,
            'errors' => $errors,
            'converted_tests' => $emitted['converted'],
            'skipped_tests' => isset($emitted['skipped']) ? $emitted['skipped'] : array(),
            'test_statuses' => $testStatuses,
            'repairs' => $repairs,
            'compatibility_md' => $report['markdown'],
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
            } elseif ($mode === 'hidden'
                && trim((string) (isset($files[$lang]['starter']) ? $files[$lang]['starter'] : '')) !== ''
            ) {
                // Hidden non-empty content is still embedded in the combined HTML.
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

    /**
     * Per-test export status for authoring preview.
     *
     * @param array $tests
     * @param array $emitted
     * @return array
     */
    private static function buildTestStatuses(array $tests, array $emitted)
    {
        $convertedById = array();
        foreach ($emitted['converted'] as $c) {
            $convertedById[$c['id']] = $c;
        }
        $skippedById = array();
        if (!empty($emitted['skipped']) && is_array($emitted['skipped'])) {
            foreach ($emitted['skipped'] as $s) {
                if (isset($s['id'])) {
                    $skippedById[$s['id']] = $s;
                }
            }
        }
        $errorById = array();
        foreach ($emitted['errors'] as $e) {
            if (!is_array($e) || empty($e['message'])) {
                continue;
            }
            if (preg_match('/(?:test\s+")([^"]+)"/i', $e['message'], $m)
                || preg_match('/"([^"]+)"/', $e['message'], $m)
            ) {
                $errorById[$m[1]] = $e;
            }
        }

        $out = array();
        foreach ($tests as $index => $test) {
            if (!is_array($test)) {
                continue;
            }
            $id = isset($test['id']) ? (string) $test['id'] : ('index-' . $index);
            $type = isset($test['type']) ? (string) $test['type'] : '';
            $name = isset($test['name']) ? (string) $test['name'] : $id;
            if (isset($convertedById[$id])) {
                $out[] = array(
                    'id' => $id,
                    'type' => $type,
                    'name' => $name,
                    'export' => 'converted',
                    'message' => 'Exported to Jasmine.',
                );
            } elseif (isset($skippedById[$id])) {
                $out[] = array(
                    'id' => $id,
                    'type' => $type,
                    'name' => $name,
                    'export' => 'skipped',
                    'message' => 'WebGrader-only; omitted from Udemy Jasmine with a warning.',
                );
            } elseif (isset($errorById[$id])) {
                $out[] = array(
                    'id' => $id,
                    'type' => $type,
                    'name' => $name,
                    'export' => 'unsupported',
                    'message' => $errorById[$id]['message'],
                );
            } else {
                $out[] = array(
                    'id' => $id,
                    'type' => $type,
                    'name' => $name,
                    'export' => 'unsupported',
                    'message' => $type !== ''
                        ? ('Not exported (' . $type . ').')
                        : 'Not exported.',
                );
            }
        }
        return $out;
    }

    /**
     * Short instructor-facing repair suggestions.
     *
     * @param array $errors
     * @param array $warnings
     * @param array $testStatuses
     * @return string[]
     */
    private static function suggestRepairs(array $errors, array $warnings, array $testStatuses)
    {
        $repairs = array();
        $types = array();
        foreach ($testStatuses as $t) {
            if (isset($t['type'])) {
                $types[$t['type']] = true;
            }
        }

        if (!empty($types['html_validate']) || !empty($types['css_validate'])) {
            $repairs[] = 'HTML/CSS validator tests stay in WebGrader only; they are skipped for Udemy. Keep declarative DOM or computed-style tests for Udemy grading.';
        }
        if (!empty($types['css_rule_declares'])) {
            $repairs[] = 'Replace css_rule_declares with computed_style_equals where the style is observable, or keep the exercise WebGrader-only for :hover/:visited rules.';
        }
        if (!empty($types['console_includes'])) {
            $repairs[] = 'console_includes is not exported yet. Prefer call_function / DOM assertions for Udemy, or keep this exercise WebGrader-only.';
        }

        foreach ($errors as $e) {
            $code = is_array($e) && isset($e['code']) ? $e['code'] : '';
            if ($code === 'MISSING_SOLUTION') {
                $repairs[] = 'Add a reference solution (Edit → Reference solution, or solution.html in JSON) before exporting.';
            } elseif ($code === 'ASSETS_NOT_EXPORTED' || $code === 'MISSING_ASSET') {
                $repairs[] = 'Asset export is deferred. Remove required assets for Udemy, or wait for a later exporter phase.';
            } elseif ($code === 'NO_CONVERTIBLE_TESTS') {
                $repairs[] = 'Add at least one declarative test Udemy can run (selector_*, text_*, attribute_*, computed_style_*, call_function).';
            } elseif ($code === 'STARTER_PASSES_ALL' || $code === 'STARTER_MATCHES_SOLUTION') {
                $repairs[] = 'Make the starter incomplete so learners have work to do before Check solution.';
            }
        }

        return array_values(array_unique($repairs));
    }

    /**
     * True when any editable logical file differs between starter and solution.
     */
    private static function starterDiffersFromSolution(
        array $starterFiles,
        array $solutionFiles,
        array $assignment
    ) {
        $files = isset($assignment['files']) && is_array($assignment['files'])
            ? $assignment['files']
            : array();
        foreach (array('html', 'css', 'javascript') as $lang) {
            $mode = isset($files[$lang]['mode']) ? (string) $files[$lang]['mode'] : 'editable';
            if ($mode !== 'editable' && $mode !== 'optional') {
                continue;
            }
            $a = isset($starterFiles[$lang]) ? trim((string) $starterFiles[$lang]) : '';
            $b = isset($solutionFiles[$lang]) ? trim((string) $solutionFiles[$lang]) : '';
            if ($a !== $b) {
                return true;
            }
        }
        return false;
    }

    /**
     * Deduplicate warning/error objects by code + message.
     *
     * @param array $items
     * @return array
     */
    private static function uniqueMessages(array $items)
    {
        $seen = array();
        $out = array();
        foreach ($items as $item) {
            $key = is_array($item)
                ? ((isset($item['code']) ? $item['code'] : '') . '|'
                    . (isset($item['message']) ? $item['message'] : ''))
                : (string) $item;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
        }
        return $out;
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
