<?php
/** Isolated SQLite + real Yii User lifecycle; no site data or external delivery. */
define('YII_ENABLE_ERROR_HANDLER', false);
$loader = require $argv[1];
$loader->addPsr4('skeeks\\cms\\mobile\\', dirname(__DIR__).'/src');
require dirname($argv[1]).'/yiisoft/yii2/Yii.php';
require dirname((new ReflectionClass(\skeeks\cms\components\UserSessions::class))->getFileName(), 2).'/migrations/m261007_140000_user_sessions.php';
require dirname(__DIR__).'/src/migrations/m261007_150000_mobile_installations.php';

use skeeks\cms\web\SessionUser;
use skeeks\cms\models\CmsUserSession;
use skeeks\cms\mobile\models\CmsMobileInstallation;
use skeeks\cms\components\UserSessions;
use skeeks\cms\mobile\components\MobilePush;
use skeeks\cms\mobile\services\push\FcmTransport;

class TestIdentity implements yii\web\IdentityInterface
{
    public $id;
    public static function findIdentity($id) { $i = new self(); $i->id = $id; return $i; }
    public static function findIdentityByAccessToken($token, $type = null) { return null; }
    public function getId() { return $this->id; }
    public function getAuthKey() { return 'test-auth-key-'.$this->id; }
    public function validateAuthKey($key) { return $key === $this->getAuthKey(); }
}
class MemorySession extends yii\web\Session
{
    public $data = [];
    public function init() {}
    public function open() {}
    public function close() {}
    public function getIsActive() { return true; }
    public function getHasSessionId() { return true; }
    public function regenerateID($deleteOldSession = false) {}
    public function get($key, $defaultValue = null) { return $this->data[$key] ?? $defaultValue; }
    public function set($key, $value) { $this->data[$key] = $value; }
    public function remove($key) { $value = $this->data[$key] ?? null; unset($this->data[$key]); return $value; }
    public function destroy() { $this->data = []; }
}
class TestRequest extends yii\web\Request
{
    public $jar;
    public function getCookies() { return $this->jar; }
    public function getUserAgent() { return 'Mozilla/5.0 Android SkeekSMobile/0.1'; }
    public function getUserIP() { return '127.0.0.1'; }
    public function getIsSecureConnection() { return true; }
}
class TestUser extends SessionUser
{
    protected function regenerateCsrfToken() {}
}
$app = new yii\web\Application(['id' => 'sessions-test', 'basePath' => __DIR__, 'components' => [
    'request' => ['class' => TestRequest::class, 'cookieValidationKey' => 'test-only', 'scriptFile' => __FILE__, 'scriptUrl' => '/index.php'],
    'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
    'cache' => ['class' => yii\caching\ArrayCache::class],
    'session' => ['class' => MemorySession::class],
    'userSessions' => ['class' => UserSessions::class],
    'mobilePush' => ['class' => MobilePush::class, 'identityClass' => TestIdentity::class,
        'apps' => ['com.skeeks.mobile' => ['projectId' => 'skeeks-mobile']]],
]]);
$migration = new m261007_140000_user_sessions(['db' => $app->db, 'compact' => true]);
ob_start(); $migration->safeUp(); (new m261007_150000_mobile_installations(['db' => $app->db, 'compact' => true]))->safeUp(); ob_end_clean();
$app->mobilePush->enabled = true; $app->mobilePush->bootstrap($app);
$checks = 0;
function check($ok, $message) { global $checks; ++$checks; if (!$ok) throw new RuntimeException($message); }
function requestUser($session = null, array $cookies = []): TestUser {
    global $app;
    $app->set('session', $session ?: new MemorySession());
    $app->set('response', new yii\web\Response());
    $app->request->jar = new yii\web\CookieCollection($cookies);
    $app->set('user', new TestUser(['identityClass' => TestIdentity::class, 'trackSessions' => true, 'enableAutoLogin' => true]));
    return $app->user;
}
$first = requestUser();
check($first->login(TestIdentity::findIdentity(1), 3600), 'login');
$firstSession = $app->session;
$firstId = $first->currentSession->id;
$cookies = $app->response->cookies->toArray();
check(count($cookies) === 2, 'remember me has login and per-session cookie');
check(!array_key_exists('secret_hash', $first->currentSession->toArray()), 'secret hidden from serialization');
$again = requestUser($firstSession, $cookies);
check($again->id === 1 && $again->currentSession->id === $firstId, 'session survives next request');
$restored = requestUser(null, $cookies);
check($restored->id === 1 && $restored->currentSession->id === $firstId, 'remember me reuses same registry record');
$second = requestUser(); $second->login(TestIdentity::findIdentity(1), 3600);
$secondId = $second->currentSession->id;
$secondSession = $app->session;
check($secondId !== $firstId, 'independent device');
check($app->userSessions->revoke(2, $firstId) === 0, 'cannot revoke another account');
$app->userSessions->revoke(1, $firstId);
check(requestUser($firstSession, $cookies)->isGuest, 'revoked session denied');
check(requestUser(null, $cookies)->isGuest, 'revoked remember me denied');
check(!requestUser($secondSession)->isGuest, 'other device preserved');
$input = ['app_id' => 'com.skeeks.mobile', 'installation_id' => str_repeat('a', 64),
    'installation_secret' => str_repeat('b', 64), 'platform' => 'android', 'app_version' => '0.1',
    'permission' => 'granted', 'token' => str_repeat('t', 100), 'user_id' => 999];
$install = $app->mobilePush->register($app->user->currentSession, $input);
check((int)$install->cms_user_id === 1, 'owner is authenticated session, not client input');
$repeat = $app->mobilePush->register($app->user->currentSession, $input);
check($repeat->id === $install->id && CmsMobileInstallation::find()->count() == 1, 'registration idempotent');
$resetInput = $input; $resetInput['installation_id'] = str_repeat('d', 64);
try { $app->mobilePush->register($app->user->currentSession, $resetInput); check(false, 'token stolen after storage reset'); }
catch (skeeks\cms\mobile\exceptions\TokenConflictException $e) { check(true, 'token conflict has recoverable typed response'); }
$install->refresh();
check($install->token === $input['token'], 'token conflict preserves previous installation');
$wrong = $input; $wrong['installation_secret'] = str_repeat('c', 64);
try { $app->mobilePush->register($app->user->currentSession, $wrong); check(false, 'wrong install secret accepted'); }
catch (yii\web\ConflictHttpException $e) { check(true, 'installation ownership'); }
$newToken = $input; $newToken['token'] = str_repeat('u', 100);
$updated = $app->mobilePush->register($app->user->currentSession, $newToken);
check($updated->token === $newToken['token'] && $updated->id === $install->id, 'token refresh');
$third = requestUser(); $third->login(TestIdentity::findIdentity(2), 3600);
$otherInstall = $app->mobilePush->register($third->currentSession, $newToken);
check((int)$otherInstall->cms_user_id === 2 && (int)$otherInstall->generation > (int)$install->generation, 'account switch fences old deliveries');
try { $app->mobilePush->register(CmsUserSession::findOne($secondId), $newToken); check(false, 'old session reclaimed installation'); }
catch (yii\web\ConflictHttpException $e) { check(true, 'late registration cannot undo account switch'); }
$third->logout(); $otherInstall->refresh();
check($otherInstall->token === null && $otherInstall->permission === 'signed_out', 'logout removes delivery address');
$user = requestUser($secondSession);
$app->userSessions->revoke(1, null, (int)$secondId);
check(!$user->isGuest && requestUser($secondSession)->currentSession->id === $secondId, 'logout others retains current');
$app->userSessions->revoke(1);
check(requestUser($secondSession)->isGuest, 'logout all');
$legacySession = new MemorySession(); $legacySession->set('__id', 1); $legacySession->set('__authKey', 'test-auth-key-1');
check(requestUser($legacySession)->isGuest, 'untracked legacy session requires fresh login');
$legacyCookies = $cookies; unset($legacyCookies['_identity_SESSION']);
check(requestUser(null, $legacyCookies)->isGuest, 'legacy remember me cannot resurrect access');
$expired = requestUser(); $expired->login(TestIdentity::findIdentity(1), 3600);
$expiredId = $expired->currentSession->id; $expiredSession = $app->session;
CmsUserSession::updateAll(['expires_at' => time() - 1], ['id' => $expiredId]);
check(requestUser($expiredSession)->isGuest, 'expired registry denied');
$plain = requestUser(); $plain->trackSessions = false;
check($plain->login(TestIdentity::findIdentity(1), 0), 'opt-out preserves Yii login');
check($app->mobilePush->validRoute('/~upa/cms/upa-personal/devices'), 'internal route');
foreach (['https://evil.test', '//evil.test', '/\\evil.test', '/%2fevil.test', "/\nfoo"] as $route) {
    check(!$app->mobilePush->validRoute($route), 'reject unsafe route');
}
check(FcmTransport::classify(200, ['name' => 'projects/test/messages/1'])['status'] === 'accepted', 'accepted is not read');
check(FcmTransport::classify(400, ['error' => ['status' => 'INVALID_ARGUMENT']])['status'] === 'failed', 'payload error does not disable token');
check(FcmTransport::classify(404, ['error' => ['details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]])['status'] === 'invalid', 'explicit unregistered token');
check(FcmTransport::classify(503, [], '120')['delay'] === 120, 'retry-after');
check(FcmTransport::classify(401, [])['status'] === 'failed', 'auth errors do not disable token');

// Execute the real delivery handler against isolated rows and a deterministic adapter.
class TestQueue extends yii\base\Component {
    public $calls = [];
    public function push($type, $payload) { $this->calls[] = [$type, $payload]; }
}
class TestTransport extends yii\base\BaseObject {
    public static $calls = 0;
    public static $result = ['status' => 'accepted', 'code' => 'ACCEPTED'];
    public function send($app, $token, $event, $route) { ++self::$calls; return self::$result; }
}
class TestPushContext extends skeeks\cms\job\runtime\JobContext {
    public $deliveryId;
    public $testCursor = [];
    public function getPayload() { return ['deliveryId' => $this->deliveryId]; }
    public function getCursor() { return $this->testCursor; }
    public function setCursor(array $cursor) { $this->testCursor = $cursor; }
}
class TestPushReporter implements skeeks\cms\job\contracts\JobReporterInterface {
    public $result = [], $stages = [], $total = null, $processed = 0, $success = 0, $skipped = 0, $errors = 0;
    public function setStage(string $stage, ?string $message = null): void { $this->stages[] = [$stage, $message]; }
    public function setTotal(?int $total): void { $this->total = $total; }
    public function advance(int $by = 1): void { $this->processed += $by; }
    public function countSuccess(int $by = 1): void { $this->success += $by; }
    public function countWarning(int $by = 1): void {}
    public function countError(int $by = 1): void { $this->errors += $by; }
    public function countSkipped(int $by = 1): void { $this->skipped += $by; }
    public function info(string $message, array $context = []): void {}
    public function warning(string $message, array $context = []): void {}
    public function error(string $message, array $context = []): void {}
    public function itemError(string $itemType, $itemId, string $message, array $row = []): void {}
    public function heartbeat(): void {}
    public function isCancelled(): bool { return false; }
    public function addArtifact(string $type, string $path, array $options = []): skeeks\cms\job\models\CmsJobRunArtifact { throw new LogicException('unused'); }
    public function setResult(array $result): void { $this->result = $result; }
}
$app->set('jobs', new TestQueue());
$app->mobilePush->enabled = true;
$app->mobilePush->transport = ['class' => TestTransport::class];
$sender = requestUser(); $sender->login(TestIdentity::findIdentity(1), 3600);
$senderSession = $app->session;
$activeInstall = $app->mobilePush->register($sender->currentSession, $input);
$ids = $app->mobilePush->enqueue(1, 'event:1:user:1', '/profile');
check(count($ids) === 1 && count($app->jobs->calls) === 1, 'eligible installation queued');
check($ids === $app->mobilePush->enqueue(1, 'event:1:user:1', '/profile') && count($app->jobs->calls) === 1, 'event deduplicated');
$handler = new skeeks\cms\mobile\jobs\MobilePushJobHandler();
$reporter = new TestPushReporter();
$context = new TestPushContext(['deliveryId' => $ids[0]]);
$handler->run($context, $reporter); $handler->run($context, $reporter);
check(TestTransport::$calls === 1, 'completed delivery never sent twice');
check(skeeks\cms\mobile\models\CmsPushDelivery::findOne($ids[0])->status === 'accepted', 'provider acceptance recorded');
check($reporter->total === 1 && $reporter->processed === 1 && $reporter->success === 1, 'accepted delivery counts once');
check(array_column($reporter->stages, 0) === ['check', 'send', 'complete'], 'delivery stages recorded');
$acceptedSnapshot = $reporter->result;
check(strpos(json_encode($acceptedSnapshot), $input['token']) === false && !isset($acceptedSnapshot['device']['installation_id']), 'snapshot contains no push address or installation secret');
$app->db->createCommand()->createTable('cms_job_run', ['id' => 'pk', 'status' => 'string', 'payload_json' => 'text', 'result_json' => 'text'])->execute();
$run = new skeeks\cms\job\models\CmsJobRun(['id' => 1, 'status' => 'succeeded']);
$run->setPayload(['deliveryId' => $ids[0]]);
$run->setResult(['deliveryId' => $ids[0], 'status' => 'accepted']);
$report = new skeeks\cms\mobile\jobs\MobilePushReport();
$legacyDetails = $report->details($run);
check($legacyDetails['status'] === 'Принято Firebase' && $legacyDetails['rows']['Платформа'] === 'Android', 'legacy history projects saved delivery');
check(strpos($legacyDetails['message'], 'не подтверждены') !== false, 'acceptance does not claim receipt');
$pending = $app->mobilePush->enqueue(1, 'event:2:user:1', '/profile');
$switch = requestUser($senderSession); $switch->login(TestIdentity::findIdentity(2), 3600);
check(CmsUserSession::findOne($activeInstall->session_id)->revoked_at !== null, 'lazy identity account switch revokes former session');
$activeInstall = $app->mobilePush->register($switch->currentSession, $input);
$otherOwnerIds = $app->mobilePush->enqueue(2, 'event:1:user:1', '/profile');
check(count($otherOwnerIds) === 1 && $otherOwnerIds !== $ids, 'dedup includes new owner and generation');
check($report->details($run)['rows']['Приложение'] === '—', 'legacy report does not inherit new owner device metadata');
$run->setResult($acceptedSnapshot);
check($report->details($run)['rows']['Приложение'] === 'com.skeeks.mobile', 'new history preserves sending-time device snapshot');
$handler->run(new TestPushContext(['deliveryId' => $pending[0]]), $reporter);
check(TestTransport::$calls === 1 && skeeks\cms\mobile\models\CmsPushDelivery::findOne($pending[0])->status === 'cancelled', 'queued old-account delivery cancelled');
check($reporter->skipped === 1 && $reporter->result['status'] === 'cancelled', 'inactive connection records skipped outcome');
$pending = $app->mobilePush->enqueue(2, 'event:3:user:2', '/profile');
$switch->logout();
$handler->run(new TestPushContext(['deliveryId' => $pending[0]]), $reporter);
check(TestTransport::$calls === 1, 'logout cancels queued send');
$retryUser = requestUser(); $retryUser->login(TestIdentity::findIdentity(2), 3600);
$app->mobilePush->register($retryUser->currentSession, $input);
$pending = $app->mobilePush->enqueue(2, 'event:4:user:2', '/profile');
TestTransport::$result = ['status' => 'retry', 'code' => 'HTTP_503', 'delay' => 120];
$context = new TestPushContext(['deliveryId' => $pending[0]]);
try { $handler->run($context, $reporter); check(false, 'retry not requested'); }
catch (skeeks\cms\job\exceptions\JobRequeueException $e) { check($e->delay === 120 && $context->testCursor['retries'] === 1, 'definite provider failure uses queue continuation'); }
TestTransport::$result = ['status' => 'unknown', 'code' => 'NETWORK_OUTCOME_UNKNOWN'];
try { $handler->run($context, $reporter); check(false, 'unknown outcome accepted'); }
catch (skeeks\cms\job\exceptions\JobPermanentException $e) { check(true, 'unknown outcome stops automatic retries'); }
$calls = TestTransport::$calls; $handler->run($context, $reporter);
check(TestTransport::$calls === $calls, 'unknown delivery not resent');
check(skeeks\cms\mobile\models\CmsPushDelivery::findOne($pending[0])->status === 'unknown', 'unknown outcome visible');
check($reporter->errors === 1 && $reporter->result['status'] === 'unknown', 'uncertain outcome records error and result before exception');
check(skeeks\cms\mobile\jobs\MobilePushReport::providerCode('secret-token') === 'PROVIDER_ERROR', 'arbitrary adapter errors do not enter public report');
$activeSession = $retryUser->currentSession;
$rollbackListener = static function () { throw new RuntimeException('test rollback'); };
$app->userSessions->on(UserSessions::EVENT_REVOKED, $rollbackListener);
try { $app->userSessions->revoke(2, (int)$activeSession->id); }
catch (RuntimeException $e) { check($e->getMessage() === 'test rollback', 'listener failure propagated'); }
$app->userSessions->off(UserSessions::EVENT_REVOKED, $rollbackListener);
$activeSession->refresh();
$activeInstall = CmsMobileInstallation::findOne(['session_id' => $activeSession->id]);
check(!$activeSession->revoked_at && $activeInstall->token !== null, 'core revocation and extension cleanup roll back together');
Yii::setAlias('@skeeks/cms/mobile', dirname(__DIR__).'/src');
$app->setModules((require dirname(__DIR__).'/src/config/web.php')['modules']);
[$endpoint, $action] = $app->createController('cms-mobile/push-v1/register');
check($endpoint instanceof \skeeks\cms\mobile\controllers\MobilePushController && $action === 'register', 'versioned module endpoint resolves');
$details = $app->userSessions->renderDetails(new yii\web\View(), $activeSession);
check(strpos($details, 'push-v1') !== false && strpos($details, 'Проверить push') !== false, 'extension renders its controls in core session card');
check(strpos($details, $activeInstall->token) === false, 'extension markup does not expose token');
$app->mobilePush->apps['second.app'] = ['projectId' => 'test-project'];
check(!$app->mobilePush->canUseBridge(), 'ambiguous app does not enable bridge');
$app->mobilePush->bridgeAppId = 'com.skeeks.mobile';
check($app->mobilePush->canUseBridge(), 'explicit valid app enables bridge');
$deliveryClass = skeeks\cms\mobile\models\CmsPushDelivery::class;
$deliveryClass::updateAll(['updated_at' => time() - 40 * 86400], ['id' => $ids[0]]);
check($app->mobilePush->cleanupDeliveries() === 1, 'retention removes old terminal delivery');
check($report->details($run)['status'] === 'Принято Firebase', 'saved snapshot survives delivery cleanup');
$run->setResult([]);
check($report->details($run)['status'] === 'Сведения об отправке недоступны', 'missing evidence never manufactures success');
check($deliveryClass::findOne($otherOwnerIds[0]) !== null, 'retention preserves queued delivery');
class CredentialsFixture extends FcmTransport {
    public function inspect(array &$app) { return get_class($this->createCredentials(null, $app)); }
}
$credentialFile = tempnam(sys_get_temp_dir(), 'firebase-test-');
file_put_contents($credentialFile, json_encode(['type' => 'service_account', 'project_id' => 'fixture-project',
    'private_key' => 'fixture-not-a-real-key', 'client_email' => 'test@fixture-project.iam.gserviceaccount.com']));
try {
    $app->params['cms-mobile'] = ['firebaseCredentialsFile' => $credentialFile];
    $firebaseApp = [];
    check((new CredentialsFixture())->inspect($firebaseApp) === Google\Auth\Credentials\ServiceAccountCredentials::class,
        'explicit params select service account credentials');
    check($firebaseApp['projectId'] === 'fixture-project', 'project id defaults to the explicit JSON');
    $app->params['cms-mobile']['firebaseCredentialsFile'] = $credentialFile.'-missing';
    check((new FcmTransport())->send([], 'test', 'event', '/profile')['code'] === 'AUTH_FAILED',
        'invalid explicit path fails closed without ADC fallback');
} finally { unlink($credentialFile); unset($app->params['cms-mobile']); }
echo "OK: $checks checks — sessions, remember me, revocation, installation ownership, account switch, routes, FCM outcomes.\n";
