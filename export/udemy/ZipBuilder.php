<?php
/**
 * Build an in-memory / temp-file ZIP from string members and stream bytes.
 */
class UdemyZipBuilder
{
    /**
     * @param array $members Map of archive path => string contents
     * @return string ZIP bytes
     * @throws RuntimeException
     */
    public static function build(array $members)
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('PHP ZipArchive extension is required for Udemy export.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'wgudemy');
        if ($tmp === false) {
            throw new RuntimeException('Could not create temporary file for ZIP.');
        }

        // ZipArchive needs a .zip suffix on some platforms.
        $zipPath = $tmp . '.zip';
        @unlink($tmp);

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException('Could not open temporary ZIP (code ' . $opened . ').');
        }

        try {
            foreach ($members as $name => $contents) {
                $name = str_replace('\\', '/', (string) $name);
                if ($name === '' || strpos($name, '..') !== false || $name[0] === '/') {
                    throw new RuntimeException('Invalid ZIP member name: ' . $name);
                }
                if ($zip->addFromString($name, (string) $contents) === false) {
                    throw new RuntimeException('Failed to add ZIP member: ' . $name);
                }
            }
            if ($zip->close() === false) {
                throw new RuntimeException('Failed to finalize ZIP archive.');
            }
        } catch (Exception $e) {
            @$zip->close();
            @unlink($zipPath);
            throw $e;
        }

        $bytes = file_get_contents($zipPath);
        @unlink($zipPath);
        if ($bytes === false) {
            throw new RuntimeException('Failed to read temporary ZIP bytes.');
        }
        return $bytes;
    }

    /**
     * Stream ZIP bytes to stdout (CLI) or as a download response.
     *
     * @param string $bytes
     * @param string $downloadName
     * @param bool $asHttp
     */
    public static function stream($bytes, $downloadName, $asHttp = false)
    {
        $downloadName = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $downloadName);
        if ($downloadName === '') {
            $downloadName = 'udemy-export.zip';
        }

        if ($asHttp) {
            if (headers_sent() === false) {
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $downloadName . '"');
                header('Content-Length: ' . strlen($bytes));
                header('Cache-Control: no-store');
            }
        }

        echo $bytes;
    }
}
