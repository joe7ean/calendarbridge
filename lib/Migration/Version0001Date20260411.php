<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0001Date20260411 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('calbridge_feeds')) {
            $table = $schema->createTable('calbridge_feeds');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);
            $table->addColumn('feed_url', Types::STRING, [
                'notnull' => true,
                'length'  => 2048,
            ]);
            $table->addColumn('feed_name', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
            ]);
            $table->addColumn('calendar_color', Types::STRING, [
                'notnull' => false,
                'length'  => 9,
                'default' => '#0082c9',
            ]);
            $table->addColumn('calendar_id', Types::INTEGER, [
                'notnull'  => false,
                'unsigned' => true,
                'default'  => null,
            ]);
            $table->addColumn('service_user', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            // JSON array of group IDs, e.g. ["groupA","groupB"]
            $table->addColumn('target_groups', Types::TEXT, [
                'notnull' => true,
                'default' => '[]',
            ]);
            $table->addColumn('sync_interval', Types::INTEGER, [
                'notnull' => true,
                'default' => 3600,
            ]);
            $table->addColumn('last_synced_at', Types::DATETIME, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('last_manual_sync_at', Types::DATETIME, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('last_etag', Types::STRING, [
                'notnull' => false,
                'length'  => 255,
                'default' => null,
            ]);
            // 0 = ok, 1 = error, 2 = disabled
            $table->addColumn('last_status', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('last_error', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('consecutive_errors', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            // Stored as SMALLINT: 1 = true, 0 = false (NC BOOLEAN NOT NULL cannot store false)
            $table->addColumn('strip_attendees', Types::SMALLINT, [
                'notnull' => true,
                'default' => 1,
            ]);
            $table->addColumn('http_auth_user', Types::STRING, [
                'notnull' => false,
                'length'  => 255,
                'default' => null,
            ]);
            // Stored encrypted via ICrypto
            $table->addColumn('http_auth_pass', Types::STRING, [
                'notnull' => false,
                'length'  => 1024,
                'default' => null,
            ]);
            $table->addColumn('verify_ssl', Types::SMALLINT, [
                'notnull' => true,
                'default' => 1,
            ]);
            $table->addColumn('active', Types::SMALLINT, [
                'notnull' => true,
                'default' => 1,
            ]);

            $table->setPrimaryKey(['id']);
            // Prevent exact duplicate feeds per service user
            $table->addUniqueIndex(['service_user', 'feed_url'], 'calbridge_user_url_unique');
            $table->addIndex(['service_user'], 'calbridge_service_user');
            $table->addIndex(['active'], 'calbridge_active');
        }

        return $schema;
    }
}
