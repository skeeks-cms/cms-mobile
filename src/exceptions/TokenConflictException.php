<?php
namespace skeeks\cms\mobile\exceptions;

class TokenConflictException extends \yii\web\ConflictHttpException
{
    public function __construct() { parent::__construct('Приложению необходимо обновить push-токен.'); }
}
