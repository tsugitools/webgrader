<?php
/**
 * Emit Jasmine evaluation.js from declarative WebGrader tests (Phase 1–2).
 */
class UdemyTestEmitter
{
    /** Fully supported declarative types. */
    public static $supportedTypes = array(
        // Phase 1 DOM
        'selector_exists',
        'selector_not_exists',
        'selector_count',
        'text_equals',
        'text_contains',
        'attribute_equals',
        'class_present',
        // Phase 2 CSS / JS
        'computed_style_equals',
        'computed_styles_equals',
        'function_exists',
        'function_result',
        'call_function',
        'click_changes_dom',
    );

    /**
     * WebGrader-only checks: omit from Jasmine with an explicit warning
     * (never silent). Remaining convertible tests may still export.
     */
    public static $softSkipTypes = array(
        'html_validate',
        'css_validate',
        'css_rule_declares',
        'console_includes',
    );

    private static $htmlTypes = array(
        'selector_exists',
        'selector_not_exists',
        'selector_count',
        'text_equals',
        'text_contains',
        'attribute_equals',
        'class_present',
    );

    private static $cssTypes = array(
        'computed_style_equals',
        'computed_styles_equals',
    );

    private static $jsTypes = array(
        'function_exists',
        'function_result',
        'call_function',
        'click_changes_dom',
    );

    /**
     * @param array $tests
     * @return array{js:string,converted:array,warnings:array,errors:array,skipped:array}
     */
    public static function emit(array $tests)
    {
        $converted = array();
        $warnings = array();
        $errors = array();
        $skipped = array();
        $ids = array();
        $points = array();
        $needsStyleHelpers = false;

        $suites = array(
            'HTML structure' => array(),
            'CSS presentation' => array(),
            'JavaScript behavior' => array(),
        );

        foreach ($tests as $index => $test) {
            if (!is_array($test)) {
                $errors[] = array(
                    'code' => 'INVALID_TEST',
                    'message' => 'Test at index ' . $index . ' is not an object.',
                );
                continue;
            }

            $id = isset($test['id']) ? (string) $test['id'] : '';
            $type = isset($test['type']) ? (string) $test['type'] : '';
            $name = isset($test['name']) ? (string) $test['name'] : ($id !== '' ? $id : 'test-' . $index);

            if ($id === '') {
                $errors[] = array(
                    'code' => 'MISSING_TEST_ID',
                    'message' => 'Test at index ' . $index . ' is missing id.',
                );
                continue;
            }
            if (isset($ids[$id])) {
                $errors[] = array(
                    'code' => 'DUPLICATE_TEST_ID',
                    'message' => 'Duplicate test id: ' . $id,
                );
                continue;
            }
            $ids[$id] = true;

            if ($type === '') {
                $errors[] = array(
                    'code' => 'MISSING_TEST_TYPE',
                    'message' => 'Test ' . $id . ' is missing type.',
                );
                continue;
            }

            if ($type === 'jasmine') {
                $optIn = !empty($test['export']['udemy']);
                if (!$optIn) {
                    $errors[] = array(
                        'code' => 'RAW_JASMINE_NOT_OPTED_IN',
                        'message' => 'Raw Jasmine test "' . $id . '" requires export.udemy=true.',
                    );
                } else {
                    $errors[] = array(
                        'code' => 'RAW_JASMINE_UNSUPPORTED',
                        'message' => 'Raw Jasmine export is not supported yet (' . $id . ').',
                    );
                }
                continue;
            }

            if (in_array($type, self::$softSkipTypes, true)) {
                $warnings[] = array(
                    'code' => 'WEBGRADER_ONLY_TEST_SKIPPED',
                    'message' => 'WebGrader-only test "' . $id . '" (' . $type
                        . ') was not exported to Udemy Jasmine.',
                );
                $skipped[] = array('id' => $id, 'type' => $type);
                continue;
            }

            if (!in_array($type, self::$supportedTypes, true)) {
                $errors[] = array(
                    'code' => 'UNSUPPORTED_TEST_TYPE',
                    'message' => 'Unsupported test type "' . $type . '" for test "' . $id . '".',
                );
                continue;
            }

            $emitError = null;
            $body = self::emitBody($test, $emitError);
            if ($emitError !== null) {
                $errors[] = $emitError;
                continue;
            }

            if (in_array($type, self::$cssTypes, true)) {
                $needsStyleHelpers = true;
            }

            $pointValue = isset($test['points']) ? $test['points'] : null;
            if ($pointValue !== null && $pointValue !== '') {
                $points[] = (float) $pointValue;
            }

            $suiteName = self::suiteForType($type);
            $block = array();
            $block[] = '';
            $block[] = '    // WebGrader test: ' . self::jsComment($id);
            if ($pointValue !== null && $pointValue !== '') {
                $block[] = '    // WebGrader points: ' . $pointValue;
            }
            $block[] = '    it(' . self::jsString($name) . ', function () {';
            foreach ($body as $line) {
                $block[] = '        ' . $line;
            }
            $block[] = '    });';
            $suites[$suiteName] = array_merge($suites[$suiteName], $block);

            $converted[] = array(
                'id' => $id,
                'type' => $type,
                'name' => $name,
                'points' => $pointValue,
                'suite' => $suiteName,
            );
        }

        if (count($converted) === 0 && count($skipped) > 0 && count($errors) === 0) {
            $errors[] = array(
                'code' => 'NO_CONVERTIBLE_TESTS',
                'message' => 'No tests could be converted to Jasmine'
                    . ' (only WebGrader-only checks were present).',
            );
        }

        $lines = array();
        if ($needsStyleHelpers) {
            $lines = array_merge($lines, self::styleHelperLines());
            $lines[] = '';
        }

        foreach ($suites as $suiteName => $suiteLines) {
            if (count($suiteLines) === 0) {
                continue;
            }
            $lines[] = 'describe(' . self::jsString($suiteName) . ', function () {';
            foreach ($suiteLines as $line) {
                $lines[] = $line;
            }
            $lines[] = '});';
            $lines[] = '';
        }

        if (count(array_unique($points)) > 1) {
            $warnings[] = array(
                'code' => 'UNEQUAL_POINTS',
                'message' => 'Unequal WebGrader point weights may not be preserved in Udemy.',
            );
        }

        return array(
            'js' => implode("\n", $lines),
            'converted' => $converted,
            'warnings' => $warnings,
            'errors' => $errors,
            'skipped' => $skipped,
        );
    }

    private static function suiteForType($type)
    {
        if (in_array($type, self::$cssTypes, true)) {
            return 'CSS presentation';
        }
        if (in_array($type, self::$jsTypes, true)) {
            return 'JavaScript behavior';
        }
        return 'HTML structure';
    }

    private static function styleHelperLines()
    {
        return array(
            '// WebGrader Udemy export helpers for computed-style comparisons',
            'function wgIsColorProperty(prop) {',
            '    var p = String(prop || "").toLowerCase();',
            '    return p === "color" || p.indexOf("color") !== -1 || p === "fill" || p === "stroke";',
            '}',
            'function wgIsOffsetProperty(prop) {',
            '    var p = String(prop || "").toLowerCase();',
            '    return p === "top" || p === "right" || p === "bottom" || p === "left";',
            '}',
            'function wgNormalizeOffset(value) {',
            '    var v = String(value || "").trim().toLowerCase();',
            '    return v === "0" ? "0px" : v;',
            '}',
            'function wgNormalizeColor(value) {',
            '    var raw = String(value || "").trim();',
            '    if (!raw || !document.body) return raw;',
            '    var probe = document.createElement("div");',
            '    probe.style.backgroundColor = raw;',
            '    document.body.appendChild(probe);',
            '    var resolved = window.getComputedStyle(probe).backgroundColor;',
            '    document.body.removeChild(probe);',
            '    return (resolved || raw).trim();',
            '}',
            'function wgNormalizeComputed(prop, value) {',
            '    var v = String(value || "").trim();',
            '    if (wgIsColorProperty(prop)) return wgNormalizeColor(v);',
            '    if (wgIsOffsetProperty(prop)) return wgNormalizeOffset(v);',
            '    return v;',
            '}',
        );
    }

    /**
     * @param array $test
     * @param array|null $error
     * @return string[]
     */
    private static function emitBody(array $test, &$error)
    {
        $error = null;
        $type = $test['type'];

        switch ($type) {
            case 'selector_exists':
            case 'selector_not_exists':
            case 'selector_count':
            case 'text_equals':
            case 'text_contains':
            case 'attribute_equals':
            case 'class_present':
            case 'computed_style_equals':
            case 'computed_styles_equals':
            case 'click_changes_dom':
                return self::emitDomishBody($test, $error);

            case 'function_exists':
                return self::emitFunctionExists($test, $error);

            case 'function_result':
                return self::emitFunctionResult($test, $error);

            case 'call_function':
                return self::emitCallFunction($test, $error);
        }

        $error = array(
            'code' => 'UNSUPPORTED_TEST_TYPE',
            'message' => 'Unsupported test type "' . $type . '".',
        );
        return array();
    }

    private static function emitDomishBody(array $test, &$error)
    {
        $type = $test['type'];

        if ($type === 'click_changes_dom') {
            $clickSel = isset($test['click_selector'])
                ? (string) $test['click_selector']
                : (isset($test['selector']) ? (string) $test['selector'] : '');
            if ($clickSel === '') {
                $error = array(
                    'code' => 'MISSING_CLICK_SELECTOR',
                    'message' => 'Test "' . $test['id'] . '" requires click_selector.',
                );
                return array();
            }
            $assertSel = isset($test['assert_selector'])
                ? (string) $test['assert_selector']
                : '';
            $assertType = isset($test['assert_type'])
                ? (string) $test['assert_type']
                : 'selector_exists';
            if ($assertSel === '' && isset($test['then']) && is_array($test['then'])) {
                $assertSel = isset($test['then']['selector']) ? (string) $test['then']['selector'] : '';
                $assertType = isset($test['then']['type']) ? (string) $test['then']['type'] : 'selector_exists';
            }
            if ($assertSel === '') {
                $error = array(
                    'code' => 'MISSING_ASSERT_SELECTOR',
                    'message' => 'Test "' . $test['id'] . '" requires assert_selector (or then.selector).',
                );
                return array();
            }
            $lines = array(
                'var btn = document.querySelector(' . self::jsString($clickSel) . ');',
                'expect(btn).not.toBeNull();',
                'btn.click();',
            );
            if ($assertType === 'selector_not_exists') {
                $lines[] = 'expect(document.querySelector(' . self::jsString($assertSel) . ')).toBeNull();';
            } elseif ($assertType === 'text_equals' && array_key_exists('expected', $test)) {
                $expected = self::normalizeText((string) $test['expected']);
                $lines[] = 'var el = document.querySelector(' . self::jsString($assertSel) . ');';
                $lines[] = 'expect(el).not.toBeNull();';
                $lines[] = 'var actual = (el.textContent || "").replace(/\\s+/g, " ").trim();';
                $lines[] = 'expect(actual).toBe(' . self::jsString($expected) . ');';
            } else {
                $lines[] = 'expect(document.querySelector(' . self::jsString($assertSel) . ')).not.toBeNull();';
            }
            return $lines;
        }

        $selector = isset($test['selector']) ? (string) $test['selector'] : '';
        if ($selector === '') {
            $error = array(
                'code' => 'MISSING_SELECTOR',
                'message' => 'Test "' . $test['id'] . '" requires selector.',
            );
            return array();
        }
        $sel = self::jsString($selector);

        switch ($type) {
            case 'selector_exists':
                return array(
                    'expect(document.querySelector(' . $sel . ')).not.toBeNull();',
                );

            case 'selector_not_exists':
                return array(
                    'expect(document.querySelector(' . $sel . ')).toBeNull();',
                );

            case 'selector_count':
                if (!array_key_exists('expected', $test)) {
                    $error = array(
                        'code' => 'MISSING_EXPECTED',
                        'message' => 'Test "' . $test['id'] . '" requires expected count.',
                    );
                    return array();
                }
                return array(
                    'expect(document.querySelectorAll(' . $sel . ').length).toBe('
                        . ((int) $test['expected']) . ');',
                );

            case 'text_equals':
                if (!array_key_exists('expected', $test)) {
                    $error = array(
                        'code' => 'MISSING_EXPECTED',
                        'message' => 'Test "' . $test['id'] . '" requires expected text.',
                    );
                    return array();
                }
                $expected = self::normalizeText((string) $test['expected']);
                return array(
                    'var el = document.querySelector(' . $sel . ');',
                    'expect(el).not.toBeNull();',
                    'var actual = (el.textContent || "").replace(/\\s+/g, " ").trim();',
                    'expect(actual).toBe(' . self::jsString($expected) . ');',
                );

            case 'text_contains':
                if (!array_key_exists('expected', $test)) {
                    $error = array(
                        'code' => 'MISSING_EXPECTED',
                        'message' => 'Test "' . $test['id'] . '" requires expected substring.',
                    );
                    return array();
                }
                return array(
                    'var el = document.querySelector(' . $sel . ');',
                    'expect(el).not.toBeNull();',
                    'var actual = (el.textContent || "").replace(/\\s+/g, " ").trim();',
                    'expect(actual.indexOf(' . self::jsString((string) $test['expected'])
                        . ')).not.toBe(-1);',
                );

            case 'attribute_equals':
                $attr = isset($test['attribute']) ? (string) $test['attribute'] : '';
                if ($attr === '' || !array_key_exists('expected', $test)) {
                    $error = array(
                        'code' => 'MISSING_ATTRIBUTE_FIELDS',
                        'message' => 'Test "' . $test['id'] . '" requires attribute and expected.',
                    );
                    return array();
                }
                return array(
                    'var el = document.querySelector(' . $sel . ');',
                    'expect(el).not.toBeNull();',
                    'expect(el.getAttribute(' . self::jsString($attr) . ')).toBe('
                        . self::jsString((string) $test['expected']) . ');',
                );

            case 'class_present':
                $className = isset($test['class'])
                    ? (string) $test['class']
                    : (isset($test['expected']) ? (string) $test['expected'] : '');
                if ($className === '') {
                    $error = array(
                        'code' => 'MISSING_CLASS',
                        'message' => 'Test "' . $test['id'] . '" requires class (or expected).',
                    );
                    return array();
                }
                return array(
                    'var el = document.querySelector(' . $sel . ');',
                    'expect(el).not.toBeNull();',
                    'expect(el.classList.contains(' . self::jsString($className) . ')).toBe(true);',
                );

            case 'computed_style_equals':
                $prop = isset($test['property']) ? (string) $test['property'] : '';
                if ($prop === '' || !array_key_exists('expected', $test)) {
                    $error = array(
                        'code' => 'MISSING_STYLE_FIELDS',
                        'message' => 'Test "' . $test['id'] . '" requires property and expected.',
                    );
                    return array();
                }
                return array(
                    'var el = document.querySelector(' . $sel . ');',
                    'expect(el).not.toBeNull();',
                    'var prop = ' . self::jsString($prop) . ';',
                    'var actual = window.getComputedStyle(el).getPropertyValue(prop).trim();',
                    'var expected = ' . self::jsString(trim((string) $test['expected'])) . ';',
                    'expect(wgNormalizeComputed(prop, actual)).toBe(wgNormalizeComputed(prop, expected));',
                );

            case 'computed_styles_equals':
                if (!isset($test['expected']) || !is_array($test['expected'])
                    || self::isListArray($test['expected'])
                ) {
                    $error = array(
                        'code' => 'MISSING_STYLE_OBJECT',
                        'message' => 'Test "' . $test['id'] . '" requires expected object of CSS properties.',
                    );
                    return array();
                }
                if (count($test['expected']) === 0) {
                    $error = array(
                        'code' => 'EMPTY_STYLE_OBJECT',
                        'message' => 'Test "' . $test['id'] . '" expected object is empty.',
                    );
                    return array();
                }
                $lines = array(
                    'var el = document.querySelector(' . $sel . ');',
                    'expect(el).not.toBeNull();',
                    'var cs = window.getComputedStyle(el);',
                );
                foreach ($test['expected'] as $prop => $expected) {
                    $prop = (string) $prop;
                    $lines[] = '(function () {';
                    $lines[] = '    var prop = ' . self::jsString($prop) . ';';
                    $lines[] = '    var actual = cs.getPropertyValue(prop).trim();';
                    $lines[] = '    var expected = ' . self::jsString(trim((string) $expected)) . ';';
                    $lines[] = '    expect(wgNormalizeComputed(prop, actual))'
                        . '.toBe(wgNormalizeComputed(prop, expected));';
                    $lines[] = '})();';
                }
                return $lines;
        }

        $error = array(
            'code' => 'UNSUPPORTED_TEST_TYPE',
            'message' => 'Unsupported test type "' . $type . '".',
        );
        return array();
    }

    private static function emitFunctionExists(array $test, &$error)
    {
        $fn = self::functionName($test);
        if ($fn === '') {
            $error = array(
                'code' => 'MISSING_FUNCTION_NAME',
                'message' => 'Test "' . $test['id'] . '" requires function (or name).',
            );
            return array();
        }
        return array(
            'expect(typeof window[' . self::jsString($fn) . ']).toBe("function");',
        );
    }

    private static function emitFunctionResult(array $test, &$error)
    {
        $fn = self::functionName($test);
        if ($fn === '' || !array_key_exists('expected', $test)) {
            $error = array(
                'code' => 'MISSING_FUNCTION_RESULT_FIELDS',
                'message' => 'Test "' . $test['id'] . '" requires function and expected.',
            );
            return array();
        }
        $args = isset($test['args']) && is_array($test['args']) ? $test['args'] : array();
        $argJs = array();
        foreach ($args as $arg) {
            $argJs[] = self::jsValue($arg);
        }
        return array(
            'expect(typeof window[' . self::jsString($fn) . ']).toBe("function");',
            'expect(window[' . self::jsString($fn) . '](' . implode(', ', $argJs) . ')).toBe('
                . self::jsValue($test['expected']) . ');',
        );
    }

    private static function emitCallFunction(array $test, &$error)
    {
        $fn = self::functionName($test);
        $op = isset($test['expect_op']) ? (string) $test['expect_op'] : '';
        if ($fn === '' || ($op !== 'sum' && $op !== 'square')) {
            $error = array(
                'code' => 'INVALID_CALL_FUNCTION',
                'message' => 'Test "' . $test['id']
                    . '" requires function and expect_op of "sum" or "square".',
            );
            return array();
        }

        $arity = isset($test['arg_count']) ? (int) $test['arg_count'] : 1;
        $trials = isset($test['trials']) ? (int) $test['trials'] : 3;
        if ($trials < 1) {
            $trials = 1;
        }
        if ($arity < 1) {
            $arity = 1;
        }

        // Deterministic args (Udemy must not depend on Math.random).
        $cases = self::deterministicCallCases($op, $arity, $trials);
        $lines = array(
            'expect(typeof window[' . self::jsString($fn) . ']).toBe("function");',
        );
        foreach ($cases as $case) {
            $argJs = array();
            foreach ($case['args'] as $arg) {
                $argJs[] = (string) (int) $arg;
            }
            $lines[] = 'expect(window[' . self::jsString($fn) . ']('
                . implode(', ', $argJs) . ')).toBe(' . self::jsValue($case['expected']) . ');';
        }
        return $lines;
    }

    /**
     * Fixed trial vectors mirroring WebGrader's 2–10 range without randomness.
     *
     * @return array<int,array{args:int[],expected:int}>
     */
    public static function deterministicCallCases($op, $arity, $trials)
    {
        $pool = array(
            array(2, 3),
            array(4, 5),
            array(7, 2),
            array(9, 8),
            array(3, 10),
            array(6, 4),
        );
        $out = array();
        for ($t = 0; $t < $trials; $t++) {
            $pair = $pool[$t % count($pool)];
            $args = array();
            for ($i = 0; $i < $arity; $i++) {
                $args[] = $pair[$i % count($pair)];
            }
            if ($op === 'sum') {
                $expected = array_sum($args);
            } else {
                $expected = $args[0] * $args[0];
            }
            $out[] = array('args' => $args, 'expected' => $expected);
        }
        return $out;
    }

    private static function functionName(array $test)
    {
        if (!empty($test['function'])) {
            return (string) $test['function'];
        }
        // Avoid using learner-facing "name" (test title) as a function id.
        if (!empty($test['fn'])) {
            return (string) $test['fn'];
        }
        return '';
    }

    private static function isListArray(array $arr)
    {
        if (count($arr) === 0) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    private static function normalizeText($text)
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $text));
    }

    private static function jsString($value)
    {
        return json_encode((string) $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function jsValue($value)
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function jsComment($value)
    {
        return str_replace(array("\r", "\n", '*/'), array(' ', ' ', '* /'), (string) $value);
    }
}
