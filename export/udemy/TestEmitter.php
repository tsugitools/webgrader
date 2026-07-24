<?php
/**
 * Emit Jasmine evaluation.js from declarative WebGrader tests (Phase 1).
 */
class UdemyTestEmitter
{
    /** @var string[] Phase 1 + trivial DOM siblings from the design's Phase-One list */
    public static $supportedTypes = array(
        'selector_exists',
        'selector_not_exists',
        'selector_count',
        'text_equals',
        'text_contains',
        'attribute_equals',
    );

    /**
     * @param array $tests
     * @return array{js:string,converted:array,warnings:array,errors:array}
     */
    public static function emit(array $tests)
    {
        $converted = array();
        $warnings = array();
        $errors = array();
        $ids = array();
        $points = array();

        $suiteLines = array();
        $suiteLines[] = 'describe("HTML structure", function () {';

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
                        'code' => 'RAW_JASMINE_PHASE1',
                        'message' => 'Raw Jasmine export is not supported in Phase 1 (' . $id . ').',
                    );
                }
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

            $pointValue = isset($test['points']) ? $test['points'] : null;
            if ($pointValue !== null && $pointValue !== '') {
                $points[] = (float) $pointValue;
            }

            $suiteLines[] = '';
            $suiteLines[] = '    // WebGrader test: ' . self::jsComment($id);
            if ($pointValue !== null && $pointValue !== '') {
                $suiteLines[] = '    // WebGrader points: ' . $pointValue;
            }
            $suiteLines[] = '    it(' . self::jsString($name) . ', function () {';
            foreach ($body as $line) {
                $suiteLines[] = '        ' . $line;
            }
            $suiteLines[] = '    });';

            $converted[] = array(
                'id' => $id,
                'type' => $type,
                'name' => $name,
                'points' => $pointValue,
            );
        }

        $suiteLines[] = '});';
        $suiteLines[] = '';

        if (count(array_unique($points)) > 1) {
            $warnings[] = array(
                'code' => 'UNEQUAL_POINTS',
                'message' => 'Unequal WebGrader point weights may not be preserved in Udemy.',
            );
        }

        return array(
            'js' => implode("\n", $suiteLines),
            'converted' => $converted,
            'warnings' => $warnings,
            'errors' => $errors,
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
                $count = (int) $test['expected'];
                return array(
                    'expect(document.querySelectorAll(' . $sel . ').length).toBe(' . $count . ');',
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
                $needle = (string) $test['expected'];
                return array(
                    'var el = document.querySelector(' . $sel . ');',
                    'expect(el).not.toBeNull();',
                    'var actual = (el.textContent || "").replace(/\\s+/g, " ").trim();',
                    'expect(actual.indexOf(' . self::jsString($needle) . ')).not.toBe(-1);',
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
        }

        $error = array(
            'code' => 'UNSUPPORTED_TEST_TYPE',
            'message' => 'Unsupported test type "' . $type . '".',
        );
        return array();
    }

    private static function normalizeText($text)
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $text));
    }

    private static function jsString($value)
    {
        return json_encode((string) $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function jsComment($value)
    {
        return str_replace(array("\r", "\n", '*/'), array(' ', ' ', '* /'), (string) $value);
    }
}
