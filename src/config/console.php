<?php
return ['modules' => ['cms-mobile' => [
    'class' => \skeeks\cms\mobile\Module::class,
    'controllerNamespace' => 'skeeks\\cms\\mobile\\console\\controllers',
]], 'controllerMap' => ['migrate' => [
    'migrationPath' => ['@skeeks/cms/mobile/migrations'],
]]];
