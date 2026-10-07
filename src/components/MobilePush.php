<?php
namespace skeeks\cms\mobile\components;

use skeeks\cms\mobile\models\CmsMobileInstallation as Installation;
use skeeks\cms\mobile\models\CmsPushDelivery as Delivery;
use skeeks\cms\models\CmsUserSession;
use Yii;
use yii\web\BadRequestHttpException;
use yii\web\ConflictHttpException;

/** Opt-in mobile registration and durable per-recipient delivery intents. */
class MobilePush extends \yii\base\Component implements \yii\base\BootstrapInterface
{
    public $enabled = false;
    /** app id => ['projectId' => '...', 'channelId' => '...']; server-owned. */
    public $apps = [];
    /** Select explicitly when several native apps share one web origin. */
    public $bridgeAppId;
    public $userAgentMarker = 'SkeekSMobile/';
    public $identityClass = \skeeks\cms\models\CmsUser::class;
    public $transport = ['class' => \skeeks\cms\mobile\services\push\FcmTransport::class];

    public function bootstrap($app)
    {
        if (!$this->enabled) { return; }
        $app->userSessions->userAgentLabels[$this->userAgentMarker] = 'Мобильное приложение';
        $app->userSessions->on(\skeeks\cms\components\UserSessions::EVENT_REVOKED,
            static function (\skeeks\cms\events\UserSessionsRevokedEvent $event) {
                Installation::updateAll([
                    'token' => null, 'token_hash' => null, 'permission' => 'signed_out',
                    'generation' => new \yii\db\Expression('generation + 1'),
                ], ['session_id' => $event->sessionIds, 'realm' => $event->realm, 'cms_user_id' => $event->userId]);
            });
        if (!($app instanceof \yii\web\Application)) { return; }
        $app->userSessions->on(\skeeks\cms\components\UserSessions::EVENT_RENDER_DETAILS,
            static function (\skeeks\cms\events\UserSessionDetailsEvent $event) use ($app) {
                $current = $app->user instanceof \skeeks\cms\web\SessionUser ? $app->user->currentSession : null;
                if (!$current || (int)$event->session->cms_user_id !== (int)$current->cms_user_id
                    || $event->session->realm !== $current->realm) { return; }
                $installations = Installation::find()->where(['session_id' => $event->session->id,
                    'cms_user_id' => $current->cms_user_id, 'realm' => $current->realm])->all();
                $event->html .= $event->view->render('@skeeks/cms/mobile/views/session-details', [
                    'session' => $event->session, 'current' => $current, 'installations' => $installations,
                ]);
            });
        $app->view->on(\yii\web\View::EVENT_BEGIN_PAGE, function () use ($app) {
            $appId = $this->getBridgeAppId();
            if ($this->canUseBridge()) {
                \skeeks\cms\mobile\widgets\assets\MobilePushAsset::register($app->view);
                $app->view->registerMetaTag(['name' => 'skeeks-push-register', 'content' => \yii\helpers\Url::to(['/cms-mobile/push-v1/register'])]);
                $app->view->registerMetaTag(['name' => 'skeeks-push-app', 'content' => $appId]);
                $app->view->registerMetaTag(['name' => 'skeeks-push-authenticated', 'content' => $app->user->isGuest ? '0' : '1']);
            }
        });
    }

    public function getBridgeAppId(): ?string
    {
        $id = $this->bridgeAppId ?: (count($this->apps) === 1 ? array_key_first($this->apps) : null);
        return $id && isset($this->apps[$id]) ? $id : null;
    }

    public function canUseBridge(): bool
    {
        return $this->enabled && $this->getBridgeAppId() !== null && $this->userAgentMarker !== ''
            && Yii::$app instanceof \yii\web\Application
            && strpos((string)Yii::$app->request->userAgent, $this->userAgentMarker) !== false;
    }

    public function register(CmsUserSession $session, array $input): Installation
    {
        $appId = $input['app_id'] ?? '';
        $id = $input['installation_id'] ?? '';
        $secret = $input['installation_secret'] ?? '';
        $permission = $input['permission'] ?? '';
        $token = $input['token'] ?? null;
        if (!is_string($appId) || !isset($this->apps[$appId]) ||
            !is_string($id) || !preg_match('/^[a-f0-9]{32,64}$/D', $id) ||
            !is_string($secret) || !preg_match('/^[a-f0-9]{64}$/D', $secret) ||
            !in_array($permission, ['granted', 'denied', 'prompt'], true) ||
            ($input['platform'] ?? '') !== 'android' ||
            !is_string($input['app_version'] ?? null) || strlen($input['app_version']) > 40 ||
            ($token !== null && (!is_string($token) || strlen($token) > 4096 || strlen($token) < 16)) ||
            ($permission === 'granted' && !$token)) {
            throw new BadRequestHttpException('Некорректная регистрация устройства.');
        }
        return Installation::getDb()->transaction(function () use ($session, $input, $appId, $id, $secret, $permission, $token) {
            // Serialize registration with revocation on the owning login record.
            CmsUserSession::updateAll(['last_seen_at' => new \yii\db\Expression('last_seen_at')], ['id' => $session->id]);
            $session->refresh();
            if ($session->revoked_at || $session->expires_at <= time()) {
                throw new \yii\web\UnauthorizedHttpException('Войдите снова.');
            }
            $row = Installation::findOne(['realm' => $session->realm, 'app_id' => $appId, 'installation_id' => $id]);
            if ($row) {
                // Lock the installation and re-read after a concurrent account switch.
                Installation::updateAll(['last_seen_at' => time()], ['id' => $row->id]);
                $row->refresh();
            }
            if ($row && !hash_equals($row->secret_hash, hash('sha256', $secret))) {
                throw new ConflictHttpException('Установка уже зарегистрирована.');
            }
            if ($row && (int)$row->session_id > (int)$session->id) {
                throw new ConflictHttpException('Установка уже привязана к более новому входу.');
            }
            if ($permission === 'granted' && Installation::find()->where([
                'realm' => $session->realm, 'app_id' => $appId, 'token_hash' => hash('sha256', $token),
            ])->andWhere(['<>', 'id', $row ? $row->id : 0])->exists()) {
                throw new \skeeks\cms\mobile\exceptions\TokenConflictException();
            }
            if (!$row) {
                $row = new Installation();
                $row->setAttributes(['realm' => $session->realm, 'app_id' => $appId, 'installation_id' => $id,
                    'secret_hash' => hash('sha256', $secret), 'enabled' => 1, 'generation' => 1], false);
            } elseif ((int)$row->session_id !== (int)$session->id) {
                $row->generation++;
                $row->enabled = 1;
            }
            $row->setAttributes([
                'session_id' => $session->id, 'cms_user_id' => $session->cms_user_id,
                'platform' => $input['platform'], 'app_version' => $input['app_version'],
                'permission' => $permission, 'token' => $permission === 'granted' ? $token : null,
                'token_hash' => $permission === 'granted' ? hash('sha256', $token) : null,
                'last_seen_at' => time(),
            ], false);
            try { $row->save(false); } catch (\yii\db\IntegrityException $e) {
                // Never transfer a token belonging to a different installation based on token alone.
                if ($permission === 'granted' && Installation::find()->where([
                    'realm' => $session->realm, 'app_id' => $appId, 'token_hash' => hash('sha256', $token),
                ])->andWhere(['<>', 'id', $row->id ?: 0])->exists()) {
                    throw new \skeeks\cms\mobile\exceptions\TokenConflictException();
                }
                throw new ConflictHttpException('Повторите регистрацию устройства.');
            }
            return $row;
        });
    }

    public function enqueue(int $userId, string $eventKey, string $route, ?int $onlyInstallation = null): array
    {
        if (!$this->enabled || !Yii::$app->has('jobs')) { throw new \LogicException('Push-очередь не настроена.'); }
        if (!preg_match('/^[a-zA-Z0-9:._-]{1,128}$/D', $eventKey) || !$this->validRoute($route)) {
            throw new \InvalidArgumentException('Некорректное событие или маршрут.');
        }
        $query = Installation::find()->where(['realm' => Yii::$app->userSessions->realm,
            'cms_user_id' => $userId, 'enabled' => 1, 'permission' => 'granted']);
        if ($onlyInstallation !== null) { $query->andWhere(['id' => $onlyInstallation]); }
        $ids = [];
        foreach ($query->all() as $installation) {
            if (!$this->isDeliverable($installation)) { continue; }
            $id = Delivery::getDb()->transaction(function () use ($installation, $userId, $eventKey, $route) {
                $key = ['event_key' => $eventKey, 'installation_id' => $installation->id,
                    'cms_user_id' => $userId, 'generation' => $installation->generation];
                $existing = Delivery::findOne($key);
                if ($existing) { return $existing->id; }
                $delivery = new Delivery();
                $delivery->setAttributes(['event_key' => $eventKey, 'installation_id' => $installation->id,
                    'generation' => $installation->generation, 'cms_user_id' => $userId, 'route' => $route,
                    'status' => 'queued', 'created_at' => time(), 'updated_at' => time()], false);
                try { $delivery->save(false); } catch (\yii\db\IntegrityException $e) {
                    $existing = Delivery::findOne($key);
                    if (!$existing) { throw $e; }
                    return $existing->id;
                }
                Yii::$app->jobs->push('cms.mobile-push', ['deliveryId' => (int)$delivery->id]);
                return $delivery->id;
            });
            $ids[] = (int)$id;
        }
        return $ids;
    }

    public function isDeliverable(Installation $installation): bool
    {
        if (!$installation->enabled || $installation->permission !== 'granted' || !$installation->token) { return false; }
        $session = CmsUserSession::findOne(['id' => $installation->session_id, 'revoked_at' => null,
            'realm' => $installation->realm, 'cms_user_id' => $installation->cms_user_id]);
        if (!$session || $session->expires_at <= time()) { return false; }
        // Account deletion, blocking and auth-key rotation invalidate delivery too.
        $identityClass = $this->identityClass;
        // IDs are global within this DB; ownership is already bound to the session.
        // findIdentity() scopes to the web site's current context, unavailable in workers.
        $identity = is_a($identityClass, \skeeks\cms\models\CmsUser::class, true)
            ? $identityClass::find()->active()->andWhere(['id' => $installation->cms_user_id])->one()
            : $identityClass::findIdentity($installation->cms_user_id);
        return $identity && hash_equals($session->auth_key_hash, hash('sha256', (string)$identity->getAuthKey()));
    }

    public function validRoute(string $route): bool
    {
        return strlen($route) <= 1000 && preg_match('~^/(?!/)[^\\\\\x00-\x20]*$~D', $route)
            && !preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|2f|5c)/i', $route);
    }

    public function cleanupDeliveries(int $retentionSeconds = 2592000, int $limit = 500): int
    {
        if ($retentionSeconds < 86400 || $limit < 1 || $limit > 10000) { throw new \InvalidArgumentException('Invalid retention or batch size.'); }
        $condition = ['and', ['status' => ['accepted', 'failed', 'invalid', 'cancelled', 'unknown']],
            ['installation_id' => Installation::find()->select('id')->where(['realm' => Yii::$app->userSessions->realm])],
            ['<', 'updated_at', time() - $retentionSeconds]];
        $ids = Delivery::find()->select('id')->where($condition)->orderBy('id')->limit($limit)->column();
        return $ids ? Delivery::deleteAll(['and', ['id' => $ids], $condition]) : 0;
    }
}
