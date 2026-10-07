<?php
use yii\helpers\Html;
$profile = $this->context instanceof \skeeks\cms\controllers\AdminProfileController ? 'admin' : 'upa';
$button = static function ($action, $id, $label) use ($profile) {
    return Html::beginForm(['/cms-mobile/push-v1/'.$action], 'post')
        .Html::hiddenInput('id', $id)
        .Html::hiddenInput('profile', $profile)
        .Html::submitButton($label, ['class' => 'sx-button sx-button--secondary'])
        .Html::endForm();
};
?>
<?php foreach ($installations as $installation): ?>
<p>Push: <?= Html::encode(!$installation->enabled ? 'выключены в профиле' :
    ($installation->permission === 'granted' && $installation->token ? 'разрешены на устройстве' : 'нет разрешения или подписки')); ?></p>
<div class="sx-button-group">
<?= $button('toggle', $installation->id, $installation->enabled ? 'Выключить push' : 'Включить push'); ?>
<?php if ($installation->enabled && $installation->permission === 'granted' && $installation->token): ?>
<?= $button('test', $installation->id, 'Проверить push'); ?>
<?php endif; ?>
</div>
<?php endforeach; ?>
<?php if ((int)$session->id === (int)$current->id && Yii::$app->mobilePush->canUseBridge()): ?>
<p><button type="button" hidden class="sx-button sx-button--secondary" data-sx-enable-push>Разрешить уведомления на телефоне</button></p>
<?php endif; ?>
