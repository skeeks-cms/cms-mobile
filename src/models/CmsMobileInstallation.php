<?php
namespace skeeks\cms\mobile\models;

class CmsMobileInstallation extends \yii\db\ActiveRecord
{
    public static function tableName() { return '{{%cms_mobile_installation}}'; }
    public function fields()
    {
        return ['id', 'app_id', 'platform', 'app_version', 'permission', 'enabled', 'last_seen_at'];
    }
}
