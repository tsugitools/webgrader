<?php
/**
 * Combine WebGrader logical HTML / CSS / JavaScript into one HTML document.
 */
class UdemyHtmlBuilder
{
    /**
     * @param array $files Map with optional keys html, css, javascript
     * @param string $title Document title when wrapping a fragment
     * @return string
     */
    public static function build(array $files, $title = 'Exercise')
    {
        $html = isset($files['html']) ? (string) $files['html'] : '';
        $css = isset($files['css']) ? (string) $files['css'] : '';
        $js = isset($files['javascript']) ? (string) $files['javascript'] : '';

        $styleBlock = trim($css) !== ''
            ? "<style>\n" . $css . "\n</style>\n"
            : '';
        $scriptBlock = trim($js) !== ''
            ? "<script>\n" . self::escapeScriptContent($js) . "\n</script>\n"
            : '';

        $lower = strtolower($html);
        $hasHtml = strpos($lower, '<html') !== false;
        $hasHead = strpos($lower, '<head') !== false;
        $hasBody = strpos($lower, '<body') !== false;

        if ($hasHtml) {
            $out = $html;
            if ($styleBlock !== '') {
                if ($hasHead && preg_match('/<\/head>/i', $out)) {
                    $out = preg_replace('/<\/head>/i', $styleBlock . '</head>', $out, 1);
                } elseif ($hasBody && preg_match('/<body[^>]*>/i', $out)) {
                    $out = preg_replace('/<body([^>]*)>/i', '<body$1>' . "\n" . $styleBlock, $out, 1);
                } else {
                    $out = $styleBlock . $out;
                }
            }
            if ($scriptBlock !== '') {
                if (preg_match('/<\/body>/i', $out)) {
                    $out = preg_replace('/<\/body>/i', $scriptBlock . '</body>', $out, 1);
                } else {
                    $out .= $scriptBlock;
                }
            }
            return $out;
        }

        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return "<!DOCTYPE html>\n"
            . "<html lang=\"en\">\n"
            . "<head>\n"
            . "  <meta charset=\"utf-8\">\n"
            . "  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . "  <title>{$safeTitle}</title>\n"
            . $styleBlock
            . "</head>\n"
            . "<body>\n"
            . $html . "\n"
            . $scriptBlock
            . "</body>\n"
            . "</html>\n";
    }

    /**
     * Prevent early </script> termination when embedding student JS.
     */
    public static function escapeScriptContent($js)
    {
        // Case-insensitive close-script sequences break out of <script>.
        return preg_replace('/<\/script/i', '<\\/script', (string) $js);
    }
}
