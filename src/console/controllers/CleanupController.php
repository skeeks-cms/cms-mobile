<?php
namespace skeeks\cms\mobile\console\controllers;

class CleanupController extends \yii\console\Controller
{
    public function actionIndex($retentionDays = 30, $limit = 500)
    {
        $count = \Yii::$app->mobilePush->cleanupDeliveries((int)$retentionDays * 86400, (int)$limit);
        $this->stdout("Removed delivery results: $count\n");
        return 0;
    }
}
