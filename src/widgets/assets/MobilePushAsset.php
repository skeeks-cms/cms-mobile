<?php
namespace skeeks\cms\mobile\widgets\assets;

class MobilePushAsset extends \yii\web\AssetBundle
{
    public $sourcePath = '@skeeks/cms/mobile/widgets/assets/src/mobile-push';
    public $js = ['mobile-push.js'];
    public $depends = [\yii\web\YiiAsset::class];
}
