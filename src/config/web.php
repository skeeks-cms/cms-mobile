<?php
return [
    'modules' => ['cms-mobile' => [
        'class' => \skeeks\cms\mobile\Module::class,
        'controllerMap' => ['push-v1' => \skeeks\cms\mobile\controllers\MobilePushController::class],
    ]],
];
