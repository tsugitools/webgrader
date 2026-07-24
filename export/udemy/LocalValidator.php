<?php
/**
 * Local DOM validation of generated starter/solution against declarative tests.
 *
 * Phase 1 validates the same semantics as the Jasmine emitters without requiring
 * a browser. This is the exporter's "test your output" check.
 */
class UdemyLocalValidator
{
    /**
     * @param string $html
     * @param array $convertedTests From UdemyTestEmitter (id/type/name + original fields needed)
     * @param array $originalTests Full original test objects keyed/ordered
     * @return array{passed:array,failed:array,errors:array}
     */
    public static function run($html, array $convertedTests, array $originalTests)
    {
        $byId = array();
        foreach ($originalTests as $t) {
            if (is_array($t) && isset($t['id'])) {
                $byId[(string) $t['id']] = $t;
            }
        }

        $doc = self::loadHtml($html);
        if ($doc === null) {
            return array(
                'passed' => array(),
                'failed' => array(),
                'errors' => array(array(
                    'code' => 'HTML_PARSE_FAILED',
                    'message' => 'Generated HTML could not be parsed for local validation.',
                )),
            );
        }

        $passed = array();
        $failed = array();
        $errors = array();

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
            try {
                $result = self::evaluate($doc, $test);
                if ($result['pass']) {
                    $passed[] = $id;
                } else {
                    $failed[] = array(
                        'id' => $id,
                        'detail' => $result['detail'],
                    );
                }
            } catch (Exception $e) {
                $errors[] = array(
                    'code' => 'VALIDATION_EXCEPTION',
                    'message' => 'Test "' . $id . '": ' . $e->getMessage(),
                );
            }
        }

        return array(
            'passed' => $passed,
            'failed' => $failed,
            'errors' => $errors,
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

    private static function evaluate(DOMDocument $doc, array $test)
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
        }

        throw new Exception('Unhandled type in local validator: ' . $type);
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
     * Minimal CSS selector → XPath for Phase 1 selectors used in catalog assignments.
     * Supports: tag, #id, .class, [attr="value"], descendant/child combinators,
     * and :first-child / :last-child.
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
                if (!preg_match('/^\[([a-zA-Z_:][a-zA-Z0-9_:\-]*)=(["\'])(.*?)\2\](.*)$/', $rest, $m)) {
                    return null;
                }
                $predicates[] = '@' . $m[1] . '="' . self::xpathLiteral($m[3]) . '"';
                $rest = $m[4];
                continue;
            }
            return null;
        }

        if ($pseudo === 'first-child') {
            $predicates[] = 'position()=1';
            // CSS :first-child means first among element siblings. Approximate with
            // count(preceding-sibling::*)=0 which is closer.
            array_pop($predicates);
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
