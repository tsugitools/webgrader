<?php
require_once "../config.php";

use \Tsugi\Grades\GradeUtil;
use \Tsugi\UI\MenuSet;

$row = GradeUtil::gradeLoad($_REQUEST['user_id']);

$menu = new MenuSet();
$menu->addLeft(__('Back to all grades'), 'grades.php');

$OUTPUT->header();
$OUTPUT->bodyStart();
$OUTPUT->topNav($menu);
$OUTPUT->flashMessages();
GradeUtil::gradeShowInfo($row, false);
$OUTPUT->footer();
