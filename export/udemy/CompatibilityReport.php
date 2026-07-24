<?php
/**
 * Build manifest.json and COMPATIBILITY.md for a Udemy export package.
 */
class UdemyCompatibilityReport
{
    const LEVEL_COMPATIBLE = 'compatible';
    const LEVEL_WARNINGS = 'compatible_with_warnings';
    const LEVEL_UNSUPPORTED = 'unsupported';

    /** Date of last successful manual verification inside a live Udemy exercise. */
    const LAST_VERIFIED = '2026-07-24';

    /**
     * @param array $context
     * @return array{level:string,manifest:array,markdown:string}
     */
    public static function build(array $context)
    {
        $errors = isset($context['errors']) ? $context['errors'] : array();
        $warnings = isset($context['warnings']) ? $context['warnings'] : array();
        $converted = isset($context['converted']) ? $context['converted'] : array();
        $generatedFiles = isset($context['generated_files']) ? $context['generated_files'] : array();
        $extras = isset($context['converted_extras']) ? $context['converted_extras'] : array();

        if (count($errors) > 0) {
            $level = self::LEVEL_UNSUPPORTED;
        } elseif (count($warnings) > 0) {
            $level = self::LEVEL_WARNINGS;
        } else {
            $level = self::LEVEL_COMPATIBLE;
        }

        $manifest = array(
            'format' => 'webgrader-udemy-export',
            'version' => 1,
            'udemy_export_version' => 2,
            'last_verified' => self::LAST_VERIFIED,
            'source_assignment' => isset($context['source_assignment'])
                ? $context['source_assignment']
                : null,
            'compatibility' => $level,
            'generated_files' => array_values($generatedFiles),
            'warnings' => array_values($warnings),
            'errors' => array_values($errors),
        );

        $markdown = self::markdown($level, $converted, $extras, $warnings, $errors);

        return array(
            'level' => $level,
            'manifest' => $manifest,
            'markdown' => $markdown,
        );
    }

    private static function markdown($level, $converted, $extras, $warnings, $errors)
    {
        $label = array(
            self::LEVEL_COMPATIBLE => 'Compatible',
            self::LEVEL_WARNINGS => 'Compatible with warnings',
            self::LEVEL_UNSUPPORTED => 'Unsupported',
        );
        $status = isset($label[$level]) ? $label[$level] : $level;

        $lines = array();
        $lines[] = '# Udemy Export Compatibility';
        $lines[] = '';
        $lines[] = 'Overall status: ' . $status;
        $lines[] = '';
        $lines[] = '## Converted';
        $lines[] = '';

        if (count($extras) === 0 && count($converted) === 0) {
            $lines[] = '- (none)';
        } else {
            foreach ($extras as $item) {
                $lines[] = '- ' . $item;
            }
            if (count($converted) > 0) {
                $lines[] = '- ' . count($converted) . ' declarative test'
                    . (count($converted) === 1 ? '' : 's');
            }
        }

        $lines[] = '';
        $lines[] = '## Warnings';
        $lines[] = '';
        if (count($warnings) === 0) {
            $lines[] = '- (none)';
        } else {
            foreach ($warnings as $w) {
                $lines[] = '- ' . self::itemMessage($w);
            }
        }

        $lines[] = '';
        $lines[] = '## Unsupported';
        $lines[] = '';
        if (count($errors) === 0) {
            $lines[] = '- (none)';
        } else {
            foreach ($errors as $e) {
                $lines[] = '- ' . self::itemMessage($e);
            }
        }

        $lines[] = '';
        $lines[] = '## Instructor Checklist';
        $lines[] = '';
        $lines[] = '- Enter the generated files into a test Udemy coding exercise.';
        $lines[] = '- Verify the solution passes every Jasmine test.';
        $lines[] = '- Verify the starter fails the intended tests.';
        $lines[] = '- Confirm asset paths.';
        $lines[] = '- Preview the exercise as a learner.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private static function itemMessage($item)
    {
        if (is_array($item) && isset($item['message'])) {
            return (string) $item['message'];
        }
        return (string) $item;
    }
}
