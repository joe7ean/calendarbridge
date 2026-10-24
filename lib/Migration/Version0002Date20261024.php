<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.3.0: quarantine of suspicious feed results (ADR 0012), Last-Modified
 * support and error backoff.
 */
class Version0002Date20261024 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $table = $schema->getTable('calbridge_feeds');
        $changed = false;

        $columns = [
            // HTTP Last-Modified of the last successful fetch, sent as If-Modified-Since
            'last_modified'     => [Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]],
            // Earliest time of the next background attempt after errors (backoff, Retry-After)
            'next_attempt_at'   => [Types::DATETIME, ['notnull' => false, 'default' => null]],
            // Quarantine state, see ADR 0012
            'quarantine_since'  => [Types::DATETIME, ['notnull' => false, 'default' => null]],
            'quarantine_count'  => [Types::SMALLINT, ['notnull' => true, 'default' => 0]],
            'quarantine_hash'   => [Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]],
            'quarantine_reason' => [Types::STRING, ['notnull' => false, 'length' => 32, 'default' => null]],
            'quarantine_held'   => [Types::INTEGER, ['notnull' => true, 'default' => 0]],
            'rejected_hash'     => [Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]],
        ];

        foreach ($columns as $name => [$type, $opts]) {
            if (!$table->hasColumn($name)) {
                $table->addColumn($name, $type, $opts);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
