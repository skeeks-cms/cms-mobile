<?php
namespace skeeks\cms\mobile\services\push;

/** FCM HTTP v1. Credentials are loaded by Google's official ADC library. */
class FcmTransport extends \yii\base\BaseObject
{
    /** Explicit project params take priority; a broken explicit file never falls back. */
    protected function createCredentials($handler, array &$app)
    {
        $scope = 'https://www.googleapis.com/auth/firebase.messaging';
        $file = \Yii::$app->params['cms-mobile']['firebaseCredentialsFile'] ?? null;
        if ($file !== null && $file !== '') {
            if (!is_string($file) || !is_readable($file)) { throw new \RuntimeException('Firebase credentials unavailable.'); }
            $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            if (($data['type'] ?? '') !== 'service_account' || empty($data['private_key']) || empty($data['client_email']) || empty($data['project_id'])) {
                throw new \RuntimeException('Invalid Firebase credentials.');
            }
            $app['projectId'] = $app['projectId'] ?? $data['project_id'];
            return new \Google\Auth\Credentials\ServiceAccountCredentials($scope, $data);
        }
        return \Google\Auth\ApplicationDefaultCredentials::getCredentials($scope, $handler);
    }

    public function send(array $app, string $token, string $eventKey, string $route): array
    {
        if (!class_exists(\Google\Auth\ApplicationDefaultCredentials::class)) {
            return ['status' => 'failed', 'code' => 'GOOGLE_AUTH_NOT_INSTALLED'];
        }
        try {
            $http = new \GuzzleHttp\Client(['timeout' => 20, 'connect_timeout' => 5]);
            $handler = \Google\Auth\HttpHandler\HttpHandlerFactory::build($http);
            $credentials = $this->createCredentials($handler, $app);
            if (!preg_match('/^[a-z][a-z0-9-]{4,61}[a-z0-9]$/D', $app['projectId'] ?? '')) {
                return ['status' => 'failed', 'code' => 'PROJECT_NOT_CONFIGURED'];
            }
            $auth = $credentials->fetchAuthToken($handler);
            if (empty($auth['access_token'])) { return ['status' => 'failed', 'code' => 'AUTH_FAILED']; }
        } catch (\Throwable $e) {
            // Do not attach original exception: request details may contain credentials.
            if ($e instanceof \GuzzleHttp\Exception\ConnectException) {
                return ['status' => 'retry', 'code' => 'AUTH_CONNECTION_FAILED', 'delay' => 60];
            }
            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                $status = $e->getResponse()->getStatusCode();
                if ($status === 429 || $status >= 500) {
                    return ['status' => 'retry', 'code' => 'AUTH_TEMPORARILY_UNAVAILABLE', 'delay' => 60];
                }
            }
            return ['status' => 'failed', 'code' => 'AUTH_FAILED'];
        }
        try {
            $response = $http->post('https://fcm.googleapis.com/v1/projects/'.$app['projectId'].'/messages:send', [
                'http_errors' => false,
                'headers' => ['Authorization' => 'Bearer '.$auth['access_token']],
                'json' => ['message' => [
                    'token' => $token,
                    // Never expose customer data on a lock screen or to a previously registered account.
                    'notification' => ['title' => $app['notificationTitle'] ?? 'Уведомление', 'body' => 'У вас новое уведомление'],
                    'data' => ['event_id' => $eventKey, 'route' => $route],
                    'android' => ['ttl' => '300s', 'notification' => [
                        'channel_id' => $app['channelId'] ?? 'skeeks_general',
                        'tag' => hash('sha256', $eventKey),
                    ]],
                ]],
            ]);
        } catch (\Throwable $e) {
            if ($e instanceof \GuzzleHttp\Exception\ConnectException
                && in_array($e->getHandlerContext()['errno'] ?? null, [6, 7], true)) {
                // DNS resolution / connect failure: no HTTP request reached FCM.
                return ['status' => 'retry', 'code' => 'CONNECTION_FAILED', 'delay' => 60];
            }
            // Timeout after sending has an unknown outcome; never blindly resend it.
            return ['status' => 'unknown', 'code' => 'NETWORK_OUTCOME_UNKNOWN'];
        }
        $body = json_decode((string)$response->getBody(), true);
        return self::classify($response->getStatusCode(), is_array($body) ? $body : [],
            $response->getHeaderLine('Retry-After'));
    }

    public static function classify(int $http, array $body, string $retryAfter = ''): array
    {
        if ($http >= 200 && $http < 300 && !empty($body['name'])) {
            return ['status' => 'accepted', 'code' => 'ACCEPTED'];
        }
        foreach ($body['error']['details'] ?? [] as $detail) {
            if (($detail['@type'] ?? '') === 'type.googleapis.com/google.firebase.fcm.v1.FcmError'
                && ($detail['errorCode'] ?? '') === 'UNREGISTERED') {
                return ['status' => 'invalid', 'code' => 'UNREGISTERED'];
            }
        }
        if (in_array($http, [429, 500, 503], true)) {
            $delay = ctype_digit($retryAfter) ? (int)$retryAfter : max(0, (int)strtotime($retryAfter) - time());
            return ['status' => 'retry', 'code' => 'HTTP_'.$http, 'delay' => max(60, min(86400, $delay))];
        }
        return ['status' => 'failed', 'code' => 'HTTP_'.$http];
    }
}
