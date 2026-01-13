<?php
require "../admin/auth.php";

if (date('H') < 12) {
    foreach (\DBP\Model\PeriodicTask::getRepo()->findBy(['owner' => 'DBP\PeriodicTask\AutoSpendCredit']) as $task) {
        if ($task->getOwnerInstance() && $task->getOwnerInstance()->isValid()) {
            $task->getOwnerInstance()->run();
        }
    }


    foreach (\DBP\Model\PeriodicTask::getRepo()->findBy(['owner' => 'DBP\PeriodicTask\RecreateExpiredCredit']) as $task) {
        if ($task->getOwnerInstance() && $task->getOwnerInstance()->isValid()) {
            $task->getOwnerInstance()->run();
        }
    }
} else {
    foreach (\DBP\Model\PeriodicTask::getRepo()->findBy(['owner' => 'DBP\PeriodicTask\VoidExpiredCredit']) as $task) {
        if ($task->getOwnerInstance() && $task->getOwnerInstance()->isValid()) {
            $task->getOwnerInstance()->run();
        }
    }
}
