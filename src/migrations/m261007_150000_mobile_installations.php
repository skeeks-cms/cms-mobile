<?php
use yii\db\Migration;

class m261007_150000_mobile_installations extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%cms_mobile_installation}}', [
            'id' => $this->primaryKey(),
            'realm' => $this->string(64)->notNull(),
            'app_id' => $this->string(100)->notNull(),
            'installation_id' => $this->string(64)->notNull(),
            'secret_hash' => $this->char(64)->notNull(),
            'session_id' => $this->integer()->notNull(),
            'cms_user_id' => $this->integer()->notNull(),
            'platform' => $this->string(16)->notNull(),
            'app_version' => $this->string(40)->notNull(),
            'token' => $this->text(),
            'token_hash' => $this->char(64),
            'permission' => $this->string(16)->notNull(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'generation' => $this->integer()->notNull()->defaultValue(1),
            'last_seen_at' => $this->integer()->notNull(),
            'CONSTRAINT fk_mobile_session FOREIGN KEY ([[session_id]]) REFERENCES {{%cms_user_session}} ([[id]]) ON DELETE CASCADE',
        ]);
        $this->createIndex('ux_mobile_installation', '{{%cms_mobile_installation}}', ['realm', 'app_id', 'installation_id'], true);
        $this->createIndex('ux_mobile_token', '{{%cms_mobile_installation}}', ['realm', 'app_id', 'token_hash'], true);
        $this->createIndex('ix_mobile_session', '{{%cms_mobile_installation}}', 'session_id');
        $this->createTable('{{%cms_push_delivery}}', [
            'id' => $this->primaryKey(),
            'event_key' => $this->string(128)->notNull(),
            'installation_id' => $this->integer()->notNull(),
            'generation' => $this->integer()->notNull(),
            'cms_user_id' => $this->integer()->notNull(),
            'route' => $this->string(1000)->notNull(),
            'status' => $this->string(24)->notNull(),
            'provider_code' => $this->string(64),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
            'CONSTRAINT fk_push_installation FOREIGN KEY ([[installation_id]]) REFERENCES {{%cms_mobile_installation}} ([[id]]) ON DELETE CASCADE',
        ]);
        $this->createIndex('ux_push_event_install', '{{%cms_push_delivery}}', ['event_key', 'installation_id', 'cms_user_id', 'generation'], true);
        $this->createIndex('ix_push_retention', '{{%cms_push_delivery}}', ['status', 'updated_at']);
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_push_delivery}}');
        $this->dropTable('{{%cms_mobile_installation}}');
    }
}
