<?php

use App\Traits\AuditLogSqlBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$selected_database = [];

beforeEach(function () use (&$selected_database) {
    $this->test_db_conn = 'mysql_test';
    //$this->selected_database = config('database.connections.' . $this->test_db_conn);
    if ($selected_database == []) {
        $selected_database = config('database.connections.' . $this->test_db_conn);
    }
    $this->selected_database = $selected_database;
});


test('Create tables', function () {
    Schema::connection($this->test_db_conn)->create('test_default', function (Blueprint $table) {
        $table->unsignedBigInteger('id');
        $table->string('role', 200);
        $table->string('user', 200);
        $table->string('description', 200);
        $table->primary(array('id'));
    });
    expect(true)->toBeTrue();
});

it('creates audit log table', function () {
    $builder = new class
    {
        use AuditLogSqlBuilder;
    };

    $builder->selected_database_key = $this->test_db_conn;

    $table_name = 'test_default';
    $builder->createLogTable($table_name);

    $this->assertTrue(Schema::connection($this->test_db_conn)->hasTable($table_name . '_audit_log'));
})->depends('Create tables');

it('creates audit log triggers', function () {
    $builder = new class
    {
        use AuditLogSqlBuilder;
    };

    $builder->selected_database_key = $this->test_db_conn;
    
    $table_name = 'test_default';
    $columns = ['id', 'role', 'user', 'description'];
    $id = 'id';


    $builder->createLogTriggers($table_name, $columns, $id);
    // Assert that the triggers are created

    $triggers = DB::connection($this->test_db_conn)->table('INFORMATION_SCHEMA.TRIGGERS')
        ->select('TRIGGER_NAME', 'EVENT_MANIPULATION', 'EVENT_OBJECT_TABLE', 'ACTION_STATEMENT')
        ->where('TRIGGER_SCHEMA', $this->selected_database ['database'])
        ->get();
    $table_triggers = $triggers
        ->where('EVENT_OBJECT_TABLE', $table_name);

    expect($table_triggers->count())->toBe(3);

    $insert_trigger = $table_triggers->where('TRIGGER_NAME',  $table_name  . '_insert_audit_trigger');
    expect($insert_trigger->count())->toBe(1);
    //echo  $insert_trigger->first()->ACTION_STATEMENT;   
    expect($insert_trigger->first()->ACTION_STATEMENT)->toBe("BEGIN
            INSERT INTO " . $table_name  . "_audit_log (
                id,
                old_row_data,
                new_row_data,
                dml_type,
                dml_timestamp,
                dml_created_by
            )
            VALUES(
                NEW.id,
                null,
                JSON_OBJECT('id', NEW.id,'role', NEW.role,'user', NEW.user,'description', NEW.description),
                'INSERT',
                CURRENT_TIMESTAMP,
                USER()
            );
        END");


    $update_trigger = $table_triggers->where('TRIGGER_NAME',  $table_name  . '_update_audit_trigger');
    expect($update_trigger->count())->toBe(1);
    //echo  $update_trigger->first()->ACTION_STATEMENT;
    expect($update_trigger->first()->ACTION_STATEMENT)->toBe("BEGIN
            INSERT INTO " . $table_name  . "_audit_log (
                id,
                old_row_data,
                new_row_data,
                dml_type,
                dml_timestamp,
                dml_created_by
            )
            VALUES(
                NEW.id,
                JSON_OBJECT('id', OLD.id,'role', OLD.role,'user', OLD.user,'description', OLD.description),
                JSON_OBJECT('id', NEW.id,'role', NEW.role,'user', NEW.user,'description', NEW.description),
                'UPDATE',
                CURRENT_TIMESTAMP,
                USER()
            );
        END");

    $delete_trigger = $table_triggers->where('TRIGGER_NAME',  $table_name  . '_delete_audit_trigger');
    expect($delete_trigger->count())->toBe(1);
    //echo  $delete_trigger->first()->ACTION_STATEMENT;
    expect($delete_trigger->first()->ACTION_STATEMENT)->toBe("BEGIN
            INSERT INTO " . $table_name  . "_audit_log (
                id,
                old_row_data,
                new_row_data,
                dml_type,
                dml_timestamp,
                dml_created_by
            )
            VALUES(
                OLD.id,
                JSON_OBJECT('id', OLD.id,'role', OLD.role,'user', OLD.user,'description', OLD.description),
                null,
                'DELETE',
                CURRENT_TIMESTAMP,
                USER()
            );
        END");
});


it('removes audit log triggers', function () {
    $builder = new class
    {
        use AuditLogSqlBuilder;
    };

    $builder->selected_database_key = $this->test_db_conn;
    $selected_database = config('database.connections.' . $this->test_db_conn);

    $table_name = 'test_default';

    $builder->removeLogTriggers($table_name);

    $triggers = DB::connection($this->test_db_conn)->table('INFORMATION_SCHEMA.TRIGGERS')
        ->select('TRIGGER_NAME', 'EVENT_MANIPULATION', 'EVENT_OBJECT_TABLE', 'ACTION_STATEMENT')
        ->where('TRIGGER_SCHEMA', $this->selected_database ['database'])
        ->get();
    $table_triggers = $triggers
        ->where('EVENT_OBJECT_TABLE', $table_name);

    expect($table_triggers->count())->toBe(0);
});

test('Remove tables', function () {
    Schema::connection($this->test_db_conn)->dropIfExists('test_default');
    Schema::connection($this->test_db_conn)->dropIfExists('test_default_audit_log');

    expect(true)->toBeTrue();
});
