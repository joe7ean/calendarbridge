<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0003Date20261102 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        $table = $schema->getTable('calbridge_feeds');
        if ($table->hasColumn('activity_state')) {
            return null;
        }
        // Counts and transition timestamps only; never names, URLs or ICS data.
        $table->addColumn('activity_state', Types::TEXT, ['notnull' => false]);
        return $schema;
    }
}
