<?php
require "../auth.php";

$task = DBP\Task\Task::getById(5406011);

$task->run();
