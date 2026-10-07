<?php
return [
    'bootstrap' => ['mobilePush'],
    'components' => [
        'mobilePush' => ['class' => \skeeks\cms\mobile\components\MobilePush::class],
        'jobQueueFactory' => ['queues' => ['mobile-push' => []]],
        'jobRegistry' => ['types' => [
            // Preserve the original job identity for already queued local work.
            'cms.mobile-push' => [
                'type' => 'cms.mobile-push', 'title' => 'Отправка уведомления на телефон',
                'handler' => \skeeks\cms\mobile\jobs\MobilePushJobHandler::class,
                'queue' => 'mobile-push', 'visibility' => 'transient',
                'timeout' => 60, 'leaseSeconds' => 90, 'maxAttempts' => 1,
                'idempotent' => false, 'overlapPolicy' => 'skip',
                'permission' => \skeeks\cms\rbac\CmsManager::PERMISSION_ROLE_ADMIN_ACCESS,
                'dedupKey' => static function ($payload) { return 'push:'.$payload['deliveryId']; },
            ],
        ]],
    ],
];
