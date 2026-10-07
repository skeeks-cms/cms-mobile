<?php
namespace skeeks\cms\mobile\jobs;

use skeeks\cms\job\models\CmsJobRun;
use skeeks\cms\job\reports\JobRunReport;
use skeeks\cms\mobile\models\CmsMobileInstallation;
use skeeks\cms\mobile\models\CmsPushDelivery;
use skeeks\cms\models\CmsUserSession;
use Yii;

/** Safe, bounded projection; historical sends never inherit a device's new owner. */
class MobilePushReport extends JobRunReport
{
    public $view = '@skeeks/cms/mobile/views/jobs/push';
    public $permission = \skeeks\cms\rbac\CmsManager::PERMISSION_ROLE_ADMIN_ACCESS;

    public static function device(CmsMobileInstallation $installation): array
    {
        $session = CmsUserSession::findOne(['id' => $installation->session_id,
            'cms_user_id' => $installation->cms_user_id, 'realm' => $installation->realm]);
        return ['platform' => $installation->platform, 'appId' => $installation->app_id,
            'appVersion' => $installation->app_version, 'label' => $session ? $session->label : null];
    }

    public static function providerCode(string $code): string
    {
        return preg_match('/^(ACCEPTED|CONNECTION_INACTIVE|UNREGISTERED|GOOGLE_AUTH_NOT_INSTALLED|PROJECT_NOT_CONFIGURED|AUTH_FAILED|AUTH_CONNECTION_FAILED|AUTH_TEMPORARILY_UNAVAILABLE|CONNECTION_FAILED|NETWORK_OUTCOME_UNKNOWN|TRANSPORT_OUTCOME_UNKNOWN|HTTP_[0-9]{3})$/D', $code)
            ? $code : 'PROVIDER_ERROR';
    }

    public function details(CmsJobRun $run): array
    {
        $result = $run->result;
        $id = (int)($run->payload['deliveryId'] ?? 0);
        $delivery = $id > 0 ? CmsPushDelivery::findOne($id) : null;
        $snapshot = (int)($result['deliveryId'] ?? 0) === $id ? $result : [];
        $status = $delivery ? $delivery->status : ($snapshot['status'] ?? 'unavailable');
        $labels = ['queued' => 'Ожидает отправки', 'sending' => 'Запрос к Firebase начат',
            'accepted' => 'Принято Firebase', 'cancelled' => 'Не отправлено',
            'invalid' => 'Адрес устройства недействителен', 'failed' => 'Ошибка отправки',
            'unknown' => 'Результат отправки неизвестен'];
        $messages = ['accepted' => 'Firebase принял уведомление для отправки на устройство. Получение и прочтение на телефоне не подтверждены.',
            'cancelled' => 'Отправка отменена: подключение неактивно, пользователь вышел, настройки отключены или ссылка недопустима.',
            'invalid' => 'Firebase больше не может отправлять на этот адрес. Подписка отключена; требуется повторная регистрация приложения.',
            'unknown' => 'Сервис не подтвердил результат. Автоматическая повторная отправка отключена, чтобы не отправить уведомление дважды.',
            'sending' => 'Запрос начат, но подтверждение результата ещё не сохранено. После аварийного завершения нельзя считать уведомление отправленным.',
            'queued' => 'Уведомление ожидает обработки. При временной ошибке сервис может назначить повтор.',
            'failed' => 'Сервис не принял уведомление. Проверьте код ответа и журнал выполнения.'];
        $device = is_array($snapshot['device'] ?? null) ? $snapshot['device'] : [];
        $userId = $delivery ? (int)$delivery->cms_user_id : (int)($snapshot['recipientId'] ?? 0);
        if (!$device && $delivery) {
            $installation = CmsMobileInstallation::findOne(['id' => $delivery->installation_id,
                'cms_user_id' => $delivery->cms_user_id, 'generation' => $delivery->generation,
                'realm' => Yii::$app->userSessions->realm]);
            if ($installation) { $device = self::device($installation); }
        }
        $text = static function ($value) { return is_scalar($value) && (string)$value !== '' ? mb_substr((string)$value, 0, 255) : '—'; };
        $code = $delivery ? $delivery->provider_code : ($snapshot['providerCode'] ?? '');
        $time = $snapshot['completedAt'] ?? ($delivery && !in_array($status, ['queued', 'sending'], true) ? $delivery->updated_at : null);
        $rows = [
            'Получатель' => $userId ? 'Пользователь №'.$userId : 'Сведения не сохранены',
            'Устройство' => $text($device['label'] ?? null),
            'Платформа' => $text(['android' => 'Android', 'ios' => 'iOS'][$device['platform'] ?? ''] ?? null),
            'Приложение' => $text($device['appId'] ?? null),
            'Версия приложения' => $text($device['appVersion'] ?? null),
            'Отправка' => $id ? '№'.$id : '—',
            'Ответ сервиса' => $code ? self::providerCode((string)$code) : 'Не получен',
            'Время результата' => $time ? Yii::$app->formatter->asDatetime((int)$time) : 'Не сохранено',
        ];
        return ['status' => $labels[$status] ?? 'Сведения об отправке недоступны',
            'message' => $messages[$status] ?? 'Запись отправки удалена или не была сохранена. Успех фонового задания сам по себе не подтверждает получение уведомления.',
            'rows' => $rows, 'active' => in_array($run->status, CmsJobRun::activeStatuses(), true)];
    }
}
