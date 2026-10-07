<?php
namespace skeeks\cms\mobile\controllers;

use Yii;
use yii\web\UnauthorizedHttpException;

class MobilePushController extends \yii\web\Controller
{
    public function init()
    {
        parent::init();
        // Push addresses and installation credentials must never enter request/SQL dumps.
        foreach (Yii::$app->log->targets as $target) {
            $target->maskVars = array_merge($target->maskVars, ['_POST.token', '_POST.installation_secret']);
            $target->except = array_merge($target->except, ['yii\\db\\Command::*']);
        }
        if (isset(Yii::$app->log->targets['debug'])) {
            Yii::$app->log->targets['debug']->enabled = false;
        }
    }

    public function behaviors()
    {
        return ['verbs' => ['class' => \yii\filters\VerbFilter::class, 'actions' => [
            'register' => ['POST'], 'toggle' => ['POST'], 'test' => ['POST'],
        ]]];
    }

    public function actionRegister()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        Yii::$app->response->headers->set('Cache-Control', 'no-store');
        $user = Yii::$app->user;
        if (!Yii::$app->mobilePush->enabled || !($user instanceof \skeeks\cms\web\SessionUser) || !$user->currentSession) {
            throw new UnauthorizedHttpException('Войдите в приложение.');
        }
        $session = $user->currentSession;
        $key = 'mobile-register:'.$session->id;
        if (!Yii::$app->cache->add($key, 1, 2)) { throw new \yii\web\TooManyRequestsHttpException('Повторите через несколько секунд.'); }
        // Form encoding retains Yii's normal CSRF validation. No user_id is accepted.
        try {
            return Yii::$app->mobilePush->register($session, Yii::$app->request->post())->toArray();
        } catch (\skeeks\cms\mobile\exceptions\TokenConflictException $e) {
            Yii::$app->response->statusCode = 409;
            return ['code' => 'token_conflict', 'message' => $e->getMessage()];
        }
    }

    private function ownedInstallation(): \skeeks\cms\mobile\models\CmsMobileInstallation
    {
        $user = Yii::$app->user;
        $current = $user instanceof \skeeks\cms\web\SessionUser ? $user->currentSession : null;
        if (!Yii::$app->mobilePush->enabled || !$current) { throw new UnauthorizedHttpException('Войдите в приложение.'); }
        $row = \skeeks\cms\mobile\models\CmsMobileInstallation::findOne([
            'id' => (int)Yii::$app->request->post('id'), 'realm' => $current->realm, 'cms_user_id' => $current->cms_user_id,
        ]);
        if (!$row) { throw new \yii\web\NotFoundHttpException('Устройство не найдено.'); }
        return $row;
    }

    private function returnRoute(): string
    {
        // Only the two profile destinations are accepted, never arbitrary redirects.
        $route = Yii::$app->request->post('profile') === 'admin' ? '/cms/admin-profile/devices' : '/cms/upa-personal/devices';
        return \yii\helpers\Url::to([$route]);
    }

    public function actionToggle()
    {
        $installation = $this->ownedInstallation();
        \skeeks\cms\mobile\models\CmsMobileInstallation::updateAll([
            'enabled' => $installation->enabled ? 0 : 1,
            'generation' => new \yii\db\Expression('generation + 1'),
        ], ['id' => $installation->id, 'realm' => $installation->realm, 'cms_user_id' => Yii::$app->user->id]);
        Yii::$app->session->setFlash('success', 'Настройка уведомлений сохранена.');
        return $this->redirect($this->returnRoute());
    }

    public function actionTest()
    {
        $installation = $this->ownedInstallation();
        if (!Yii::$app->cache->add('test-push:'.Yii::$app->user->id, 1, 60)) {
            throw new \yii\web\TooManyRequestsHttpException('Повторная проверка доступна через минуту.');
        }
        $ids = Yii::$app->mobilePush->enqueue((int)Yii::$app->user->id,
            'test:'.Yii::$app->security->generateRandomString(24), $this->returnRoute(), (int)$installation->id);
        Yii::$app->session->setFlash($ids ? 'success' : 'warning', $ids
            ? 'Проверка поставлена в очередь. Доставка ещё не подтверждена.' : 'Устройство сейчас недоступно для push.');
        return $this->redirect($this->returnRoute());
    }
}
