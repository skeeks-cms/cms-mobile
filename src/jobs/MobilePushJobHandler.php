<?php
namespace skeeks\cms\mobile\jobs;

use skeeks\cms\mobile\models\CmsPushDelivery as Delivery;
use skeeks\cms\mobile\models\CmsMobileInstallation as Installation;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\runtime\JobContext;
use Yii;

class MobilePushJobHandler extends \skeeks\cms\job\handlers\AbstractJobHandler
{
    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $id = (int)$context->get('deliveryId');
        $delivery = Delivery::findOne($id);
        if (!$delivery || $delivery->status !== 'queued') { return; }
        $reporter->setTotal(1);
        $reporter->setStage('check', 'Проверка подключения устройства');
        $snapshot = ['deliveryId' => $id, 'recipientId' => (int)$delivery->cms_user_id];
        $push = Yii::$app->mobilePush;
        $installation = Installation::findOne($delivery->installation_id);
        if (!$push->enabled || !$installation || $installation->realm !== Yii::$app->userSessions->realm ||
            (int)$installation->cms_user_id !== (int)$delivery->cms_user_id ||
            (int)$installation->generation !== (int)$delivery->generation ||
            !$push->isDeliverable($installation) || !$push->validRoute($delivery->route)) {
            $this->finish($id, 'cancelled', 'CONNECTION_INACTIVE');
            $reporter->setResult($snapshot + ['status' => 'cancelled', 'providerCode' => 'CONNECTION_INACTIVE', 'completedAt' => time()]);
            $reporter->countSkipped();
            $reporter->advance();
            $reporter->setStage('complete', 'Отправка отменена: подключение неактивно или ссылка недопустима');
            return;
        }
        // This is an outcome marker, not a second queue/lease implementation.
        // A crash after this point must remain visibly uncertain and must not resend.
        if (!Delivery::updateAll(['status' => 'sending', 'updated_at' => time()], ['id' => $id, 'status' => 'queued'])) { return; }
        $snapshot['device'] = MobilePushReport::device($installation);
        $reporter->setResult($snapshot + ['status' => 'sending']);
        $reporter->setStage('send', 'Отправка запроса в Firebase');
        $app = $push->apps[$installation->app_id] ?? [];
        try {
            $result = Yii::createObject($push->transport)->send($app, $installation->token, $delivery->event_key, $delivery->route);
        } catch (\Throwable $e) {
            // An adapter exception may contain the outgoing token or credentials.
            $result = ['status' => 'unknown', 'code' => 'TRANSPORT_OUTCOME_UNKNOWN'];
        }
        if ($result['status'] === 'invalid') {
            Installation::updateAll(['token' => null, 'token_hash' => null, 'permission' => 'invalid'],
                ['id' => $installation->id, 'token_hash' => $installation->token_hash, 'generation' => $installation->generation]);
        }
        if ($result['status'] === 'retry') {
            $retries = (int)($context->getCursor()['retries'] ?? 0);
            if ($retries < 3) {
                $this->finish($id, 'queued', $result['code']);
                $context->setCursor(['retries' => $retries + 1]);
                $reporter->setResult($snapshot + ['status' => 'queued', 'providerCode' => MobilePushReport::providerCode($result['code'])]);
                $reporter->setStage('retry', 'Сервис временно недоступен; назначена повторная попытка');
                $context->requestRequeue(max($result['delay'], 60 * (2 ** $retries)));
            }
            $result['status'] = 'failed';
        }
        $this->finish($id, $result['status'], $result['code']);
        $reporter->setResult($snapshot + ['status' => $result['status'], 'providerCode' => MobilePushReport::providerCode($result['code']), 'completedAt' => time()]);
        $reporter->advance();
        if (!in_array($result['status'], ['accepted', 'cancelled'], true)) {
            $reporter->countError();
            $reporter->setStage('complete', 'Отправка завершена без подтверждения Firebase');
            throw new \skeeks\cms\job\exceptions\JobPermanentException('Push: '.MobilePushReport::providerCode($result['code']));
        }
        if ($result['status'] === 'accepted') { $reporter->countSuccess(); }
        else { $reporter->countSkipped(); }
        $reporter->setStage('complete', $result['status'] === 'accepted' ? 'Firebase принял уведомление для отправки на устройство' : 'Уведомление не отправлено');
    }

    private function finish(int $id, string $status, string $code): void
    {
        Delivery::updateAll(['status' => $status, 'provider_code' => $code, 'updated_at' => time()], ['id' => $id]);
    }
}
