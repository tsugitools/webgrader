<?php
/**
 * Local validation of generated starter/solution against converted tests.
 *
 * Phase 1: DOM checks via PHP DOMDocument.
 * Phase 2: call_function via Node VM; computed styles via optional jsdom.
 */
class UdemyLocalValidator
{
    /** Types evaluated in PHP without a browser. */
    public static $domTypes = array(
        'selector_exists',
        'selector_not_exists',
        'selector_count',
        'text_equals',
        'text_contains',
        'attribute_equals',
        'attribute_exists',
        'class_present',
    );

    /** Types evaluated with Node (no DOM layout engine required). */
    public static $nodeVmTypes = array(
        'function_exists',
        'function_result',
        'call_function',
    );

    /** Types that need a CSSOM / layout engine (jsdom or similar). */
    public static $browserTypes = array(
        'computed_style_equals',
        'computed_styles_equals',
        'click_changes_dom',
    );

    /**
     * @param string $html Assembled HTML document
     * @param array $convertedTests
     * @param array $originalTests
     * @return array{passed:array,failed:array,skipped:array,errors:array,warnings:array}
     */
    public static function run($html, array $convertedTests, array $originalTests)
    {
        $byId = array();
        foreach ($originalTests as $t) {
            if (is_array($t) && isset($t['id'])) {
                $byId[(string) $t['id']] = $t;
            }
        }

        $passed = array();
        $failed = array();
        $skipped = array();
        $errors = array();
        $warnings = array();

        $domTests = array();
        $nodeTests = array();
        $browserTests = array();

        foreach ($convertedTests as $meta) {
            $id = $meta['id'];
            if (!isset($byId[$id])) {
                $errors[] = array(
                    'code' => 'VALIDATION_MISSING_TEST',
                    'message' => 'Converted test "' . $id . '" missing from source tests.',
                );
                continue;
            }
            $test = $byId[$id];
            $type = isset($test['type']) ? (string) $test['type'] : '';
            if (in_array($type, self::$domTypes, true)) {
                $domTests[] = $test;
            } elseif (in_array($type, self::$nodeVmTypes, true)) {
                $nodeTests[] = $test;
            } elseif (in_array($type, self::$browserTypes, true)) {
                $browserTests[] = $test;
            } else {
                $skipped[] = array(
                    'id' => $id,
                    'detail' => 'No local validator for type ' . $type,
                );
            }
        }

        if (count($domTests) > 0) {
            $doc = self::loadHtml($html);
            if ($doc === null) {
                $errors[] = array(
                    'code' => 'HTML_PARSE_FAILED',
                    'message' => 'Generated HTML could not be parsed for local validation.',
                );
            } else {
                foreach ($domTests as $test) {
                    try {
                        $result = self::evaluateDom($doc, $test);
                        if ($result['pass']) {
                            $passed[] = $test['id'];
                        } else {
                            $failed[] = array(
                                'id' => $test['id'],
                                'detail' => $result['detail'],
                            );
                        }
                    } catch (Exception $e) {
                        $errors[] = array(
                            'code' => 'VALIDATION_EXCEPTION',
                            'message' => 'Test "' . $test['id'] . '": ' . $e->getMessage(),
                        );
                    }
                }
            }
        }

        foreach ($nodeTests as $test) {
            try {
                $result = self::evaluateNodeVm($html, $test);
                if (!empty($result['skipped'])) {
                    $skipped[] = array(
                        'id' => $test['id'],
                        'detail' => $result['detail'],
                    );
                    $warnings[] = array(
                        'code' => 'LOCAL_NODE_VALIDATION_SKIPPED',
                        'message' => 'Could not locally run JS test "' . $test['id']
                            . '": ' . $result['detail'],
                    );
                    continue;
                }
                if ($result['pass']) {
                    $passed[] = $test['id'];
                } else {
                    $failed[] = array(
                        'id' => $test['id'],
                        'detail' => $result['detail'],
                    );
                }
            } catch (Exception $e) {
                $errors[] = array(
                    'code' => 'VALIDATION_EXCEPTION',
                    'message' => 'Test "' . $test['id'] . '": ' . $e->getMessage(),
                );
            }
        }

        if (count($browserTests) > 0) {
            $browserResult = self::evaluateBrowserBatch($html, $browserTests);
            if (!empty($browserResult['unavailable'])) {
                foreach ($browserTests as $test) {
                    $skipped[] = array(
                        'id' => $test['id'],
                        'detail' => $browserResult['detail'],
                    );
                }
                $warnings[] = array(
                    'code' => 'LOCAL_COMPUTED_STYLE_UNVERIFIED',
                    'message' => 'Computed-style / event tests were not verified locally'
                        . ' (' . $browserResult['detail'] . ').'
                        . ' Confirm them inside Udemy.',
                );
            } else {
                $errors = array_merge($errors, $browserResult['errors']);
                foreach ($browserResult['passed'] as $id) {
                    $passed[] = $id;
                }
                foreach ($browserResult['failed'] as $fail) {
                    $failed[] = $fail;
                }
            }
        }

        return array(
            'passed' => $passed,
            'failed' => $failed,
            'skipped' => $skipped,
            'errors' => $errors,
            'warnings' => $warnings,
        );
    }

    /**
     * @return DOMDocument|null
     */
    private static function loadHtml($html)
    {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadHTML(
            (string) $html,
            LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $ok ? $doc : null;
    }

    private static function evaluateDom(DOMDocument $doc, array $test)
    {
        $type = $test['type'];
        $selector = isset($test['selector']) ? (string) $test['selector'] : '';
        $xpath = self::cssToXPath($selector);
        if ($xpath === null) {
            throw new Exception('Unsupported or invalid selector for local validation: ' . $selector);
        }

        $xp = new DOMXPath($doc);
        $nodes = $xp->query($xpath);
        if ($nodes === false) {
            throw new Exception('XPath evaluation failed for selector: ' . $selector);
        }

        switch ($type) {
            case 'selector_exists':
                return array(
                    'pass' => $nodes->length > 0,
                    'detail' => $nodes->length > 0
                        ? 'Found ' . $selector
                        : 'No match for ' . $selector,
                );

            case 'selector_not_exists':
                return array(
                    'pass' => $nodes->length === 0,
                    'detail' => $nodes->length === 0
                        ? 'Correctly absent'
                        : 'Unexpected match for ' . $selector,
                );

            case 'selector_count':
                $expected = (int) $test['expected'];
                return array(
                    'pass' => $nodes->length === $expected,
                    'detail' => 'Found ' . $nodes->length . ', expected ' . $expected,
                );

            case 'text_equals':
                if ($nodes->length === 0) {
                    return array('pass' => false, 'detail' => 'No match for ' . $selector);
                }
                $actual = self::textOf($nodes->item(0));
                $expected = self::normalizeText((string) $test['expected']);
                return array(
                    'pass' => $actual === $expected,
                    'detail' => 'Got "' . $actual . '", expected "' . $expected . '"',
                );

            case 'text_contains':
                if ($nodes->length === 0) {
                    return array('pass' => false, 'detail' => 'No match for ' . $selector);
                }
                $actual = self::textOf($nodes->item(0));
                $needle = (string) $test['expected'];
                $ok = strpos($actual, $needle) !== false;
                return array(
                    'pass' => $ok,
                    'detail' => $ok
                        ? 'Contains "' . $needle . '"'
                        : 'Text does not contain "' . $needle . '"',
                );

            case 'attribute_equals':
                if ($nodes->length === 0) {
                    return array('pass' => false, 'detail' => 'No match for ' . $selector);
                }
                $el = $nodes->item(0);
                if (!($el instanceof DOMElement)) {
                    return array('pass' => false, 'detail' => 'Match is not an element');
                }
                $attr = (string) $test['attribute'];
                if (!$el->hasAttribute($attr)) {
                    return array('pass' => false, 'detail' => 'Attribute ' . $attr . ' is missing');
                }
                $actual = $el->getAttribute($attr);
                $expected = (string) $test['expected'];
                return array(
                    'pass' => $actual === $expected,
                    'detail' => 'Got "' . $actual . '", expected "' . $expected . '"',
                );

            case 'attribute_exists':
                if ($nodes->length === 0) {
                    return array('pass' => false, 'detail' => 'No match for ' . $selector);
                }
                $el = $nodes->item(0);
                if (!($el instanceof DOMElement)) {
                    return array('pass' => false, 'detail' => 'Match is not an element');
                }
                $attr = (string) $test['attribute'];
                $ok = $el->hasAttribute($attr);
                return array(
                    'pass' => $ok,
                    'detail' => $ok
                        ? 'Attribute ' . $attr . ' is present'
                        : 'Attribute ' . $attr . ' is missing',
                );

            case 'class_present':
                if ($nodes->length === 0) {
                    return array('pass' => false, 'detail' => 'No match for ' . $selector);
                }
                $el = $nodes->item(0);
                if (!($el instanceof DOMElement)) {
                    return array('pass' => false, 'detail' => 'Match is not an element');
                }
                $className = isset($test['class'])
                    ? (string) $test['class']
                    : (string) $test['expected'];
                $classAttr = ' ' . trim($el->getAttribute('class')) . ' ';
                $ok = strpos($classAttr, ' ' . $className . ' ') !== false;
                return array(
                    'pass' => $ok,
                    'detail' => $ok
                        ? 'Has class ' . $className
                        : 'Missing class ' . $className,
                );
        }

        throw new Exception('Unhandled DOM type in local validator: ' . $type);
    }

    private static function evaluateNodeVm($html, array $test)
    {
        if (!self::nodeAvailable()) {
            return array(
                'pass' => false,
                'skipped' => true,
                'detail' => 'node binary not available',
            );
        }

        $script = self::extractScripts($html);
        $payload = array(
            'script' => $script,
            'test' => $test,
        );
        if ($test['type'] === 'call_function') {
            $fn = !empty($test['function']) ? (string) $test['function'] : '';
            $op = isset($test['expect_op']) ? (string) $test['expect_op'] : '';
            $arity = isset($test['arg_count']) ? (int) $test['arg_count'] : 1;
            $trials = isset($test['trials']) ? (int) $test['trials'] : 3;
            $payload['cases'] = UdemyTestEmitter::deterministicCallCases($op, $arity, $trials);
            $payload['function'] = $fn;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $cmd = 'node -e ' . escapeshellarg(self::nodeVmRunnerSource()) . ' '
            . escapeshellarg($json);
        $out = self::shellExec($cmd);
        if ($out === null) {
            return array(
                'pass' => false,
                'skipped' => true,
                'detail' => 'node execution failed',
            );
        }

        $decoded = json_decode($out, true);
        if (!is_array($decoded) || !isset($decoded['pass'])) {
            return array(
                'pass' => false,
                'skipped' => true,
                'detail' => 'invalid node validator response: ' . substr($out, 0, 200),
            );
        }
        return array(
            'pass' => !empty($decoded['pass']),
            'detail' => isset($decoded['detail']) ? (string) $decoded['detail'] : '',
        );
    }

    private static function evaluateBrowserBatch($html, array $tests)
    {
        $scriptPath = dirname(__FILE__) . '/local-browser-check.mjs';
        if (!is_readable($scriptPath)) {
            return array(
                'unavailable' => true,
                'detail' => 'local-browser-check.mjs missing',
                'passed' => array(),
                'failed' => array(),
                'errors' => array(),
            );
        }
        if (!self::nodeAvailable()) {
            return array(
                'unavailable' => true,
                'detail' => 'node binary not available',
                'passed' => array(),
                'failed' => array(),
                'errors' => array(),
            );
        }

        $payload = json_encode(array(
            'html' => $html,
            'tests' => $tests,
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $tmp = tempnam(sys_get_temp_dir(), 'wgudemyhtml');
        if ($tmp === false) {
            return array(
                'unavailable' => true,
                'detail' => 'could not create temp file',
                'passed' => array(),
                'failed' => array(),
                'errors' => array(),
            );
        }
        $jsonPath = $tmp . '.json';
        @unlink($tmp);
        file_put_contents($jsonPath, $payload);

        $cmd = 'node ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($jsonPath);
        $out = self::shellExec($cmd);
        @unlink($jsonPath);

        if ($out === null) {
            return array(
                'unavailable' => true,
                'detail' => 'browser check failed to run',
                'passed' => array(),
                'failed' => array(),
                'errors' => array(),
            );
        }

        $decoded = json_decode($out, true);
        if (!is_array($decoded)) {
            return array(
                'unavailable' => true,
                'detail' => 'invalid browser check response',
                'passed' => array(),
                'failed' => array(),
                'errors' => array(),
            );
        }
        if (!empty($decoded['unavailable'])) {
            return array(
                'unavailable' => true,
                'detail' => isset($decoded['detail']) ? (string) $decoded['detail'] : 'jsdom unavailable',
                'passed' => array(),
                'failed' => array(),
                'errors' => array(),
            );
        }

        return array(
            'unavailable' => false,
            'detail' => '',
            'passed' => isset($decoded['passed']) ? $decoded['passed'] : array(),
            'failed' => isset($decoded['failed']) ? $decoded['failed'] : array(),
            'errors' => isset($decoded['errors']) ? $decoded['errors'] : array(),
        );
    }

    private static function nodeVmRunnerSource()
    {
        return <<<'JS'
const vm = require('vm');
const payload = JSON.parse(process.argv[1]);
const script = String(payload.script || '');
const test = payload.test || {};
const sandbox = {};
vm.createContext(sandbox);
try {
  vm.runInContext(script, sandbox, { timeout: 2000 });
} catch (e) {
  process.stdout.write(JSON.stringify({
    pass: false,
    detail: 'Script evaluation failed: ' + (e && e.message ? e.message : String(e))
  }));
  process.exit(0);
}

function functionName(t) {
  return t.function || t.fn || '';
}

try {
  const type = test.type;
  if (type === 'function_exists') {
    const name = functionName(test);
    const ok = typeof sandbox[name] === 'function';
    process.stdout.write(JSON.stringify({
      pass: ok,
      detail: ok ? name + ' exists' : name + ' is not a function'
    }));
    process.exit(0);
  }
  if (type === 'function_result') {
    const name = functionName(test);
    if (typeof sandbox[name] !== 'function') {
      process.stdout.write(JSON.stringify({ pass: false, detail: name + ' is not a function' }));
      process.exit(0);
    }
    const args = Array.isArray(test.args) ? test.args : [];
    const actual = sandbox[name].apply(sandbox, args);
    const ok = actual === test.expected;
    process.stdout.write(JSON.stringify({
      pass: ok,
      detail: ok ? 'Matched' : ('Got ' + JSON.stringify(actual) + ', expected ' + JSON.stringify(test.expected))
    }));
    process.exit(0);
  }
  if (type === 'call_function') {
    const name = payload.function || functionName(test);
    if (typeof sandbox[name] !== 'function') {
      process.stdout.write(JSON.stringify({ pass: false, detail: name + ' is not a function' }));
      process.exit(0);
    }
    const cases = Array.isArray(payload.cases) ? payload.cases : [];
    for (const c of cases) {
      const actual = sandbox[name].apply(sandbox, c.args);
      if (actual !== c.expected) {
        process.stdout.write(JSON.stringify({
          pass: false,
          detail: name + '(' + c.args.join(', ') + ') returned ' + JSON.stringify(actual)
            + ', expected ' + JSON.stringify(c.expected)
        }));
        process.exit(0);
      }
    }
    process.stdout.write(JSON.stringify({ pass: true, detail: 'Passed ' + cases.length + ' trial(s)' }));
    process.exit(0);
  }
  process.stdout.write(JSON.stringify({ pass: false, detail: 'Unhandled node test type' }));
} catch (e) {
  process.stdout.write(JSON.stringify({
    pass: false,
    detail: e && e.message ? e.message : String(e)
  }));
}
JS;
    }

    private static function extractScripts($html)
    {
        $out = '';
        if (preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', (string) $html, $m)) {
            foreach ($m[1] as $body) {
                $out .= $body . "\n";
            }
        }
        return $out;
    }

    private static function nodeAvailable()
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }
        $out = self::shellExec('node -v');
        $available = is_string($out) && strpos($out, 'v') === 0;
        return $available;
    }

    private static function shellExec($cmd)
    {
        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $proc = proc_open($cmd, $descriptors, $pipes, null, null);
        if (!is_resource($proc)) {
            return null;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0 && $stdout === '') {
            return null;
        }
        return is_string($stdout) ? trim($stdout) : null;
    }

    private static function textOf(DOMNode $node)
    {
        return self::normalizeText($node->textContent);
    }

    private static function normalizeText($text)
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $text));
    }

    /**
     * Minimal CSS selector → XPath for catalog selectors.
     *
     * @return string|null
     */
    public static function cssToXPath($selector)
    {
        $selector = trim((string) $selector);
        if ($selector === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $selector);
        if ($parts === false || count($parts) === 0) {
            return null;
        }

        $xpath = '';
        foreach ($parts as $part) {
            $step = self::simpleSelectorToXPath($part);
            if ($step === null) {
                return null;
            }
            $xpath .= '//' . $step;
        }
        return $xpath === '' ? null : $xpath;
    }

    private static function simpleSelectorToXPath($simple)
    {
        $simple = trim($simple);
        if ($simple === '' || $simple === '*') {
            return '*';
        }

        $pseudo = null;
        if (preg_match('/^(.*):(first-child|last-child)$/', $simple, $m)) {
            $simple = $m[1];
            $pseudo = $m[2];
        }

        $tag = '*';
        $predicates = array();
        $rest = $simple;

        if (preg_match('/^([a-zA-Z][a-zA-Z0-9]*)(.*)$/', $rest, $m)) {
            $tag = strtolower($m[1]);
            $rest = $m[2];
        } elseif ($rest !== '' && $rest[0] !== '.' && $rest[0] !== '#' && $rest[0] !== '[') {
            return null;
        }

        while ($rest !== '') {
            if ($rest[0] === '#') {
                if (!preg_match('/^#([a-zA-Z0-9_\-]+)(.*)$/', $rest, $m)) {
                    return null;
                }
                $predicates[] = '@id="' . self::xpathLiteral($m[1]) . '"';
                $rest = $m[2];
                continue;
            }
            if ($rest[0] === '.') {
                if (!preg_match('/^\.([a-zA-Z0-9_\-]+)(.*)$/', $rest, $m)) {
                    return null;
                }
                $cls = self::xpathLiteral($m[1]);
                $predicates[] = 'contains(concat(" ", normalize-space(@class), " "), " ' . $cls . ' ")';
                $rest = $m[2];
                continue;
            }
            if ($rest[0] === '[') {
                if (preg_match('/^\[([a-zA-Z_:][a-zA-Z0-9_:\-]*)=(["\'])(.*?)\2\](.*)$/', $rest, $m)) {
                    $predicates[] = '@' . $m[1] . '="' . self::xpathLiteral($m[3]) . '"';
                    $rest = $m[4];
                    continue;
                }
                // Attribute presence: [for], [alt], etc.
                if (preg_match('/^\[([a-zA-Z_:][a-zA-Z0-9_:\-]*)\](.*)$/', $rest, $m)) {
                    $predicates[] = '@' . $m[1];
                    $rest = $m[2];
                    continue;
                }
                return null;
            }
            return null;
        }

        if ($pseudo === 'first-child') {
            $predicates[] = 'count(preceding-sibling::*)=0';
        } elseif ($pseudo === 'last-child') {
            $predicates[] = 'count(following-sibling::*)=0';
        }

        $out = $tag;
        if (count($predicates) > 0) {
            $out .= '[' . implode(' and ', $predicates) . ']';
        }
        return $out;
    }

    private static function xpathLiteral($value)
    {
        return str_replace('"', '', (string) $value);
    }
}
