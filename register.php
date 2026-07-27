<?php

require_once __DIR__ . '/assignments.php';

$REGISTER_LTI2 = array(
    "name" => "WebGrader",
    "FontAwesome" => "fa-code",
    "short_name" => "WebGrader",
    "description" => "Interactive browser autograder for introductory HTML, CSS, and JavaScript. Learners edit source, run a preview, and are graded on observable page behavior.",
    "messages" => array("launch", "launch_grade"),
    "targets" => array("window", "iframe"),
    "privacy_level" => "name_only",
    "license" => "Apache",
    "languages" => array(
        "English",
    ),
    "source_url" => "https://github.com/tsugitools/webgrader",
    "placements" => array(
    ),
    // Optional: mstore install shows a dropdown; selected value becomes LTI custom.
    // Use the function (not $assignments): register.php is require()'d inside a
    // function, so require_once may skip redefining the $assignments variable.
    // add_to_get also puts exercise=... on the launch URL (LMS custom fallback).
    "custom" => array(
        "exercise" => array(
            "add_to_get" => true,
            "options" => webgrader_assignment_catalog(),
        ),
    ),
);
