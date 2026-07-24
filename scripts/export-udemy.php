#!/usr/bin/env php
<?php
/**
 * Export a WebGrader assignment to a Udemy review ZIP (stdout or --output).
 *
 * Usage:
 *   php scripts/export-udemy.php assignments/html/simple-list/assignment.json > simple-list-udemy.zip
 *   php scripts/export-udemy.php assignment.json --output simple-list-udemy.zip
 *   php scripts/export-udemy.php assignment.json --strict
 *   php scripts/export-udemy.php assignment.json --dump-members /tmp/out
 */

$repoRoot = dirname(__DIR__);
require_once $repoRoot . '/export/udemy/Exporter.php';

function udemy_export_usage()
{
    $msg = <<<TXT
Usage: php scripts/export-udemy.php <assignment.json> [options]

Options:
  --output <file>              Write ZIP to a file instead of stdout
  --strict                     Treat warnings as errors
  --allow-starter-pass-all     Allow starter that already passes every test
  --dump-members <dir>         Also write package members as files (debug)
  --help                       Show this help

TXT;
    fwrite(STDERR, $msg);
}

$argvCopy = $argv;
array_shift($argvCopy);

$assignmentPath = null;
$outputPath = null;
$dumpDir = null;
$strict = false;
$allowStarterPassAll = false;

while (count($argvCopy) > 0) {
    $arg = array_shift($argvCopy);
    if ($arg === '--help' || $arg === '-h') {
        udemy_export_usage();
        exit(0);
    }
    if ($arg === '--strict') {
        $strict = true;
        continue;
    }
    if ($arg === '--allow-starter-pass-all') {
        $allowStarterPassAll = true;
        continue;
    }
    if ($arg === '--output' || $arg === '-o') {
        $outputPath = array_shift($argvCopy);
        if ($outputPath === null) {
            fwrite(STDERR, "Missing value for --output\n");
            exit(2);
        }
        continue;
    }
    if ($arg === '--dump-members') {
        $dumpDir = array_shift($argvCopy);
        if ($dumpDir === null) {
            fwrite(STDERR, "Missing value for --dump-members\n");
            exit(2);
        }
        continue;
    }
    if ($arg !== '' && $arg[0] === '-') {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        udemy_export_usage();
        exit(2);
    }
    if ($assignmentPath === null) {
        $assignmentPath = $arg;
        continue;
    }
    fwrite(STDERR, "Unexpected argument: {$arg}\n");
    udemy_export_usage();
    exit(2);
}

if ($assignmentPath === null) {
    udemy_export_usage();
    exit(2);
}

try {
    $assignment = UdemyExporter::loadAssignmentFile($assignmentPath, $repoRoot);
    $package = UdemyExporter::build($assignment, array(
        'strict' => $strict,
        'allow_starter_pass_all' => $allowStarterPassAll,
        'repo_root' => $repoRoot,
    ));
} catch (Exception $e) {
    fwrite(STDERR, 'Export failed: ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDERR, 'Compatibility: ' . $package->compatibility . "\n");
foreach ($package->warnings as $w) {
    $msg = is_array($w) && isset($w['message']) ? $w['message'] : (string) $w;
    fwrite(STDERR, 'WARNING: ' . $msg . "\n");
}
foreach ($package->errors as $e) {
    $msg = is_array($e) && isset($e['message']) ? $e['message'] : (string) $e;
    fwrite(STDERR, 'ERROR: ' . $msg . "\n");
}

if ($dumpDir !== null) {
    if (!is_dir($dumpDir) && !mkdir($dumpDir, 0777, true) && !is_dir($dumpDir)) {
        fwrite(STDERR, "Could not create --dump-members directory: {$dumpDir}\n");
        exit(1);
    }
    foreach ($package->members as $name => $contents) {
        $target = rtrim($dumpDir, '/') . '/' . $name;
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
            fwrite(STDERR, "Could not create directory for member: {$name}\n");
            exit(1);
        }
        if (file_put_contents($target, $contents) === false) {
            fwrite(STDERR, "Could not write member: {$name}\n");
            exit(1);
        }
    }
    fwrite(STDERR, 'Wrote members to ' . $dumpDir . "\n");
}

if (!$package->ok || $package->zip_bytes === null) {
    fwrite(STDERR, "Export did not produce a ZIP (see errors above).\n");
    if (isset($package->members['COMPATIBILITY.md'])) {
        fwrite(STDERR, "\n" . $package->members['COMPATIBILITY.md']);
    }
    exit(1);
}

if ($outputPath !== null) {
    if (file_put_contents($outputPath, $package->zip_bytes) === false) {
        fwrite(STDERR, "Could not write ZIP to {$outputPath}\n");
        exit(1);
    }
    fwrite(STDERR, 'Wrote ' . $outputPath . ' (' . strlen($package->zip_bytes) . " bytes)\n");
    exit(0);
}

// Avoid mixing status with binary when stdout is a TTY.
if (function_exists('posix_isatty') && defined('STDOUT') && posix_isatty(STDOUT)) {
    fwrite(STDERR, "Refusing to write binary ZIP to a terminal. Use --output or redirect stdout.\n");
    exit(2);
}

UdemyZipBuilder::stream($package->zip_bytes, $package->download_name, false);
exit(0);
