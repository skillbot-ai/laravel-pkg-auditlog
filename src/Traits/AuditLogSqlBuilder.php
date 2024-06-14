<?php

namespace SkillbotAI\AuditLog\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;

trait AuditLogSqlBuilder
{
    public $selected_database_key = '';
    public $ignore_colnums = [];

    /**
     * Create audit log table
     */
    public function createLogTable(string $table_name)
    {
        Schema::connection($this->selected_database_key)->create($table_name  . '_audit_log', function (Blueprint $table) {

            $table->unsignedBigInteger('id');
            $table->json('old_row_data')->nullable();
            $table->json('new_row_data')->nullable();
            $table->enum('dml_type', ['INSERT', 'UPDATE', 'DELETE']);
            $table->timestamp('dml_timestamp');
            $table->string('dml_created_by', 200);
            $table->index('id');
        });
        Log::debug('🆕 Created ' . $table_name . '_audit_log table.');
    }

    /**
     * Create audit log triggers
     */
    public function createLogTriggers(string $table_name, array $columns, string|null $id)
    {
        $this->createInsertLogTrigger($table_name, $columns, $id);
        $this->createUpdateLogTrigger($table_name, $columns, $id);
        $this->createDeleteLogTrigger($table_name, $columns, $id);
    }


    /**
     * Create audit log insert trigger
     */
    public function createInsertLogTrigger(string $table_name, array $columns, string|null $id)
    {
        DB::connection($this->selected_database_key)
            ->unprepared($this->getInsertTriggerCreateSql($table_name, $columns, $id));
        Log::debug('🆕 Created ' . $table_name . ' insert trigger.');
    }

    /**
     * Create audit log update trigger
     */
    public function createUpdateLogTrigger(string $table_name, array $columns, string|null $id)
    {
        DB::connection($this->selected_database_key)
            ->unprepared($this->getUpdateTriggerCreateSql($table_name, $columns, $id));
        Log::debug('🆕 Created ' . $table_name . ' update trigger.');
    }

    /**
     * Create audit log delete trigger
     */
    public function createDeleteLogTrigger(string $table_name, array $columns, string|null $id)
    {
        DB::connection($this->selected_database_key)
            ->unprepared($this->getDeleteTriggerCreateSql($table_name, $columns, $id));
        Log::debug('🆕 Created ' . $table_name . ' delete trigger.');
    }

    /**
     * Remove all audit log triggers
     */
    public function removeLogTriggers($table_name): void
    {
        // Schema::connection($this->selected_database_key)->dropIfExists($table  . "_audit_log");
        $this->removeLogInsertTrigger($table_name);
        $this->removeLogUpdateTrigger($table_name);
        $this->removeLogDeleteTrigger($table_name);
        Log::debug('❌ Remove ' . $table_name . ' cleanup triggers.');
    }

    /**
     * Remove audit log insert trigger
     */
    public function removeLogInsertTrigger(string $table_name): void
    {
        DB::connection($this->selected_database_key)
            ->unprepared('DROP TRIGGER IF EXISTS `' . $table_name  . '_insert_audit_trigger`');
        Log::debug('❌ Remove ' . $table_name . ' insert trigger.');
    }

    /**
     * Remove audit log update trigger
     */
    public function removeLogUpdateTrigger(string $table_name): void
    {
        DB::connection($this->selected_database_key)
            ->unprepared('DROP TRIGGER IF EXISTS `' . $table_name  . '_update_audit_trigger`');
        Log::debug('❌ Remove ' . $table_name . ' update trigger.');
    }

    /**
     * Remove audit log delete trigger
     */
    public function removeLogDeleteTrigger(string $table_name): void
    {
        DB::connection($this->selected_database_key)
            ->unprepared('DROP TRIGGER IF EXISTS `' . $table_name  . '_delete_audit_trigger`');
        Log::debug('❌ Remove ' . $table_name . ' delete trigger.');
    }

    /**
     * Get insert trigger sql, if trigger is not exist
     */
    private function getInsertTriggerCreateSql(string $table_name, array $columns, string|null $id): string
    {
        return "
        CREATE TRIGGER " . $table_name  . "_insert_audit_trigger
        AFTER INSERT ON " . $table_name  . " FOR EACH ROW
        " . $this->getInsertTriggerSql($table_name, $columns, $id);
    }

    /**
     * Get insert trigger sql
     *  
     * Equal to the trigger record in the database.
     */
    private function getInsertTriggerSql(string $table_name, array $columns, string|null $id): string
    {
        return "BEGIN
            INSERT INTO " . $table_name  . "_audit_log (
                id,
                old_row_data,
                new_row_data,
                dml_type,
                dml_timestamp,
                dml_created_by
            )
            VALUES(
                " . (($id === null) ? 0 : 'NEW.' . $id) . ",
                null,
                JSON_OBJECT(" . $this->createTriggerColumnsSql($columns, 'NEW') . "),
                'INSERT',
                CURRENT_TIMESTAMP,
                USER()
            );
        END";
    }

    /**
     * Get update trigger sql, if trigger is not exist
     */
    private function getUpdateTriggerCreateSql(string $table_name, array $columns, string|null $id): string
    {
        return "
        CREATE TRIGGER " . $table_name  . "_update_audit_trigger
        AFTER UPDATE ON " . $table_name  . " FOR EACH ROW
        " . $this->getUpdateTriggerSql($table_name, $columns, $id);
    }

    /**
     * Get update trigger sql
     *  
     * Equal to the trigger record in the database.
     */
    private function getUpdateTriggerSql(string $table_name, array $columns, string|null $id): string
    {
        return "BEGIN
            INSERT INTO " . $table_name  . "_audit_log (
                id,
                old_row_data,
                new_row_data,
                dml_type,
                dml_timestamp,
                dml_created_by
            )
            VALUES(
                " . (($id === null) ? 0 : 'NEW.' . $id) . ",
                JSON_OBJECT(" . $this->createTriggerColumnsSql($columns, 'OLD') . "),
                JSON_OBJECT(" . $this->createTriggerColumnsSql($columns, 'NEW') . "),
                'UPDATE',
                CURRENT_TIMESTAMP,
                USER()
            );
        END";
    }

    /**
     * Get delete trigger sql, if trigger is not exist
     */
    private function getDeleteTriggerCreateSql(string $table_name, array $columns, string|null $id): string
    {
        return "
        CREATE TRIGGER " . $table_name  . "_delete_audit_trigger
        AFTER DELETE ON " . $table_name  . " FOR EACH ROW 
        " . $this->getDeleteTriggerSql($table_name, $columns, $id);
    }

    /**
     * Get delete trigger sql
     *  
     * Equal to the trigger record in the database.
     */
    private function getDeleteTriggerSql(string $table_name, array $columns, string|null $id): string
    {
        return "BEGIN
            INSERT INTO " . $table_name  . "_audit_log (
                id,
                old_row_data,
                new_row_data,
                dml_type,
                dml_timestamp,
                dml_created_by
            )
            VALUES(
                " . (($id === null) ? 0 : 'OLD.' . $id) . ",
                JSON_OBJECT(" . $this->createTriggerColumnsSql($columns, 'OLD') . "),
                null,
                'DELETE',
                CURRENT_TIMESTAMP,
                USER()
            );
        END";
    }

    /**
     * Create trigger columns sql
     */
    private function createTriggerColumnsSql(array $columns, string $type): string
    {
        sort($columns);
        $result = array_map(function ($item) use ($type) {
            if (in_array($item, $this->ignore_colnums)) {
                return "'{$item}', null";
            } else {
                return "'{$item}', {$type}.{$item}";
            }
        }, $columns);
        return implode(",", $result);
    }
}
