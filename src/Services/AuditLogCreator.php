<?php

namespace SkillbotAI\AuditLog\Services;

use Illuminate\Support\Facades\Log;
use SkillbotAI\AuditLog\Traits\AuditLogSqlBuilder;
use Exception;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditLogCreator
{
    use AuditLogSqlBuilder;

    private $selected_database = [];

    /**
     * Create a new AuditLogCreator instance.
     * 
     * Validate selected database key and get selected database config
     */
    function __construct(string $selected_database_key)
    {

        // Get database connection configs
        $valid_databases = array_filter(config('database.connections'), fn ($item) => $item['driver'] == 'mysql');

        // Select database in config
        foreach ($valid_databases as $key => $value) {
            if ($key == $selected_database_key) {
                $this->selected_database_key = $key;
                $this->selected_database = $value;
                break;
            }
        }

        $this->checkConfigAuditlog();
    }

    /**
     * Check and create database triggers
     *
     * @return bool True if triggers are created successfully
     */

    public function checkDatabase(): bool
    {

        // Get all tables and table colnums
        $tables = DB::connection($this->selected_database_key)
            ->table('INFORMATION_SCHEMA.COLUMNS')
            ->select('TABLE_NAME', 'COLUMN_NAME', 'COLUMN_KEY')
            ->where('TABLE_SCHEMA', $this->selected_database['database'])
            ->get();
        $table_names = $tables->pluck('TABLE_NAME')->unique()->toArray();

        // Get all triggers
        $triggers = $this->getTriggers();


        // Check and create triggers
        foreach ($table_names as $table_name) {
            // Check if table is *_audit_log table
            if (strpos($table_name, '_audit_log') == true) {
                // Check if table has primary key
                // TODO old log table schema updater remove

                $index_keys = $tables->where('TABLE_NAME', $table_name)
                    ->where('COLUMN_KEY', 'MUL')
                    ->count();
                if ($index_keys != 1) {

                    $primary_keys = $tables->where('TABLE_NAME', $table_name)
                        ->where('COLUMN_KEY', 'PRI')
                        ->count();
                    if ($primary_keys == 3) {
                        Schema::connection($this->selected_database_key)
                            ->table($table_name, function (Blueprint $table) use ($table_name) {

                                // Távolítsd el a primary key-t
                                $table->dropPrimary();

                                // Add hozzá az indexet
                                $table->index('id');
                                Log::debug('⚠️ Update ' . $table_name . ' index key.');
                            });
                    }
                }

                continue;
            }

            // Get triggers for table
            $table_triggers = $triggers
                ->where('EVENT_OBJECT_TABLE', $table_name);

            // Check if table is ignored
            if (($this->selected_database['audit_log']['tables'][$table_name]['ignore'] ?? false) === true) {
                if ($table_triggers->count() !== 0) {
                    $this->removeLogTriggers($table_name);
                }
                //Log::debug('⛔ Ignored ' . $table_name . ' because ignored in config.');
                continue;
            }

            // If table does not have audit_log table, clean up triggers and create audit_log table
            if (!in_array($table_name . "_audit_log", $table_names)) {
                //Log::debug('⚠️  Table ' . $table_name . ' does not have audit_log table.');
                $this->removeLogTriggers($table_name);
                $this->createLogTable($table_name);
            }

            // Get selecetd table columns
            $columns = $tables->where('TABLE_NAME', $table_name)->pluck('COLUMN_NAME')->toArray();

            // Check id filed
            $id = in_array('id', $columns)  ? 'id' : null;

            // Revrite id variable if table has id field in config
            if (($this->selected_database['audit_log']['tables'][$table_name]['id_field'] ?? null) !== null) {
                $id = $this->selected_database['audit_log']['tables'][$table_name]['id_field'];
            }

            $this->ignore_colnums = $this->selected_database['audit_log']['tables'][$table_name]['ignore_fields'] ?? [];

            if ($table_triggers->count() == 0) {
                $this->createLogTriggers($table_name, $columns, $id);
            } else {
                // Get insert trigger for table
                $insert_trigger = $table_triggers->where('TRIGGER_NAME',  $table_name  . '_insert_audit_trigger')->first();
                if ($insert_trigger !== null) {
                    // Trigger is exist
                    // Check if trigger and generated sql are same
                    if (
                        $insert_trigger->ACTION_STATEMENT != $this->getInsertTriggerSql($table_name, $columns, $id)
                    ) {
                        $this->removeLogInsertTrigger($table_name);
                        $this->createInsertLogTrigger($table_name, $columns, $id);
                    }
                } else {
                    // Trigger is not exist
                    $this->createInsertLogTrigger($table_name, $columns, $id);
                }

                $update_trigger = $table_triggers->where('TRIGGER_NAME',  $table_name  . '_update_audit_trigger')->first();
                if ($update_trigger !== null) {
                    if (
                        $update_trigger->ACTION_STATEMENT != $this->getUpdateTriggerSql($table_name, $columns, $id)
                    ) {
                        $this->removeLogUpdateTrigger($table_name);
                        $this->createUpdateLogTrigger($table_name, $columns, $id);
                    }
                } else {
                    $this->createUpdateLogTrigger($table_name, $columns, $id);
                }

                $delete_trigger = $table_triggers->where('TRIGGER_NAME',  $table_name  . '_delete_audit_trigger')->first();
                if ($delete_trigger !== null) {
                    if (
                        $delete_trigger->ACTION_STATEMENT != $this->getDeleteTriggerSql($table_name, $columns, $id)
                    ) {
                        $this->removeLogDeleteTrigger($table_name);
                        $this->createDeleteLogTrigger($table_name, $columns, $id);
                    }
                } else {
                    $this->createDeleteLogTrigger($table_name, $columns, $id);
                }
            }
        }
        return true;
    }

    /**
     * Set selected database config
     * 
     * @param array $selected_database Selected database config e.g. config('database.connections.mysql')
     */
    public function setSelectedDatabaseConfig(array $selected_database)
    {
        $this->selected_database = $selected_database;
    }

    /**
     * Check if audit log config is exist in database config
     * 
     * @throws Exception If audit log config is not exist in database config
     */
    public function checkConfigAuditlog()
    {
        if (array_key_exists('audit_log', $this->selected_database) === false) {
            Log::debug('⚠️ Audit log config is not exist in database config.');
            throw new Exception('Audit log config is not exist in database config.');
        }
    }

    /**
     * Get all triggers
     */
    public function getTriggers()
    {
        return DB::connection($this->selected_database_key)->table('INFORMATION_SCHEMA.TRIGGERS')
            ->select('TRIGGER_NAME', 'EVENT_MANIPULATION', 'EVENT_OBJECT_TABLE', 'ACTION_STATEMENT')
            ->where('TRIGGER_SCHEMA', $this->selected_database['database'])
            ->get();
    }
}
