<?php

use SkillbotAI\AuditLog\Services\AuditLogCreator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$selected_database = [];

function runAuditLogChecker($than)
{
    $audit_logger = new AuditLogCreator($than->test_db_conn);
    $audit_logger->setSelectedDatabaseConfig($than->selected_database);

    expect(
        $audit_logger->checkDatabase()
    )->toBeTrue();
}


beforeEach(function () use (&$selected_database) {
    $this->test_db_conn = 'mysql_test';
    //$this->selected_database = config('database.connections.' . $this->test_db_conn);
    if ($selected_database == []) {
        $selected_database = config('database.connections.' . $this->test_db_conn);
    }
    $this->selected_database = $selected_database;
});

test('Create tables', function () {
    Schema::connection($this->test_db_conn)->create('test_ignore_true', function (Blueprint $table) {
        $table->unsignedBigInteger('id');
        $table->primary(array('id'));
    });
    Schema::connection($this->test_db_conn)->create('test_ignore_false', function (Blueprint $table) {
        $table->unsignedBigInteger('id');
        $table->primary(array('id'));
    });
    Schema::connection($this->test_db_conn)->create('test_field_ignore', function (Blueprint $table) {
        $table->unsignedBigInteger('id');
        $table->string('password', 200);
        $table->string('uuid', 200);
        $table->string('description', 200);
        $table->primary(array('id'));
    });
    Schema::connection($this->test_db_conn)->create('test_id_field_change', function (Blueprint $table) {
        $table->unsignedBigInteger('permission_id');
        $table->primary(array('permission_id'));
    });
    Schema::connection($this->test_db_conn)->create('test_default', function (Blueprint $table) {
        $table->unsignedBigInteger('id');
        $table->string('role', 200);
        $table->string('user', 200);
        $table->string('description', 200);
        $table->primary(array('id'));
    });
    expect(true)->toBeTrue();
});


test('Check tables', function () use (&$selected_database) {
    $tables = DB::connection($this->test_db_conn)
        ->table('INFORMATION_SCHEMA.COLUMNS')
        ->select('TABLE_NAME', 'COLUMN_NAME')
        ->where('TABLE_SCHEMA', $this->selected_database['database'])
        ->where('TABLE_NAME', 'not like', 'test_%')
        ->get();
    $table_names = $tables->pluck('TABLE_NAME')->unique()->toArray();

    $ignore_tables = [];
    foreach ($table_names as $table_name) {
        $ignore_tables[$table_name] = [
            'ignore' => true,
        ];
    }
    //config(['database.connections.' . $this->test_db_conn.'.audit_log.tables' => array_merge($this->selected_database['audit_log']['tables'], $ignore_tables)]);
    $selected_database = $this->selected_database;
    $selected_database['audit_log']['tables'] = array_merge($this->selected_database['audit_log']['tables'], $ignore_tables);
    expect(true)->toBeTrue();
})->depends('Create tables');


test('Check fail audit_log config', function () {

    $audit_logger = new AuditLogCreator($this->test_db_conn);
    $audit_logger->setSelectedDatabaseConfig([]);

    expect(
        fn () => $audit_logger->checkConfigAuditlog()
    )->toThrow(Exception::class);;
})->depends('Check tables');

test('Run checker', function () {
    runAuditLogChecker($this);
})->depends('Check fail audit_log config');

test('Ignore table true', function () {

    $audit_logger = new AuditLogCreator($this->test_db_conn);
    $triggers = $audit_logger->getTriggers();

    $table_name = 'test_ignore_true';
    $table_trigger = $triggers
        ->where('EVENT_OBJECT_TABLE', $table_name);


    expect($table_trigger->count())->toBe(0);
    $this->assertFalse(Schema::connection($this->test_db_conn)->hasTable($table_name . '_audit_log'));
});

test('Ignore table false', function () {

    $audit_logger = new AuditLogCreator($this->test_db_conn);
    $triggers = $audit_logger->getTriggers();

    $table_name = 'test_ignore_false';
    $table_trigger = $triggers
        ->where('EVENT_OBJECT_TABLE', $table_name);


    expect($table_trigger->count())->toBe(3);
    $this->assertTrue(Schema::connection($this->test_db_conn)->hasTable($table_name . '_audit_log'));
})->depends('Check fail audit_log config');

test('Ignore field', function () {

    $table_name = 'test_field_ignore';
    DB::connection($this->test_db_conn)
        ->table($table_name)
        ->insert([
            'id' => 1999,
            'password' => 'password123',
            'uuid' => 'abc123',
            'description' => 'Lorem ipsum dolor sit amet',
        ]);

    $firstRow = DB::connection($this->test_db_conn)
        ->table($table_name . '_audit_log')
        ->find(1999);

    $josn_data = json_decode($firstRow->new_row_data, true);
    //echo $josn_data['password'];
    expect($josn_data['password'])->toBeNull();
    expect($josn_data['uuid'])->toBeNull();
})->depends('Check fail audit_log config');


test('Change id field', function () {

    $table_name = 'test_id_field_change';
    DB::connection($this->test_db_conn)
        ->table($table_name)
        ->insert([
            'permission_id' => 2999,
        ]);

    $firstRow = DB::connection($this->test_db_conn)
        ->table($table_name . '_audit_log')
        ->where('id', 2999);
    expect($firstRow->count())->toBe(1);
})->depends('Check fail audit_log config');

test('Check INSERT trigger', function () {

    $table_name = 'test_default';
    DB::connection($this->test_db_conn)
        ->table($table_name)
        ->insert([
            'id' => 1211,
            'role' => 'asdaadd',
            'user' => 'as daadasdd',
            'description' => 'asdadafew aadd',
        ]);

    $row = DB::connection($this->test_db_conn)
        ->table($table_name . '_audit_log')
        ->where('id', 1211)
        ->where('dml_type', 'INSERT');

    expect($row->count())->toBe(1);
    $firstRow = $row->first();
    expect($firstRow->old_row_data)->toBeEmpty();
    expect($firstRow->dml_created_by)->toMatch('/^\S+\@\S+$/i');

    $josn_data = json_decode($firstRow->new_row_data, true);
    //echo $josn_data['password'];
    expect($josn_data['id'])->toBe(1211);
    expect($josn_data['role'])->toBe('asdaadd');
    expect($josn_data['user'])->toBe('as daadasdd');
    expect($josn_data['description'])->toBe('asdadafew aadd');
})->depends('Check fail audit_log config');

test('Check UPDATE trigger', function () {

    $table_name = 'test_default';
    $log_row = DB::connection($this->test_db_conn)
        ->table($table_name)
        ->where('id', 1211)
        ->update(
            [
                'role' => 'asdaadd',
                'user' => 'ffffdg',
                'description' => 'asdadafew aadd',
            ]
        );

    $row = DB::connection($this->test_db_conn)
        ->table($table_name . '_audit_log')
        ->where('id', 1211)
        ->where('dml_type', 'UPDATE');

    expect($row->count())->toBe(1);
    $firstRow = $row->first();

    expect($firstRow->dml_created_by)->toMatch('/^\S+\@\S+$/i');

    $josn_data = json_decode($firstRow->old_row_data, true);
    //echo $josn_data['password'];
    expect($josn_data['id'])->toBe(1211);
    expect($josn_data['role'])->toBe('asdaadd');
    expect($josn_data['user'])->toBe('as daadasdd');
    expect($josn_data['description'])->toBe('asdadafew aadd');

    $josn_data = json_decode($firstRow->new_row_data, true);
    //echo $josn_data['password'];
    expect($josn_data['id'])->toBe(1211);
    expect($josn_data['role'])->toBe('asdaadd');
    expect($josn_data['user'])->toBe('ffffdg');
    expect($josn_data['description'])->toBe('asdadafew aadd');
})->depends('Check fail audit_log config');

test('Check DELETE trigger', function () {

    $table_name = 'test_default';
    DB::connection($this->test_db_conn)
        ->table($table_name)
        ->where('id', 1211)
        ->delete();

    $row = DB::connection($this->test_db_conn)
        ->table($table_name . '_audit_log')
        ->where('id', 1211)
        ->where('dml_type', 'DELETE');

    expect($row->count())->toBe(1);
    $firstRow = $row->first();
    expect($firstRow->new_row_data)->toBeEmpty();
    expect($firstRow->dml_created_by)->toMatch('/^\S+\@\S+$/i');

    $josn_data = json_decode($firstRow->old_row_data, true);
    //echo $josn_data['password'];
    expect($josn_data['id'])->toBe(1211);
    expect($josn_data['role'])->toBe('asdaadd');
    expect($josn_data['user'])->toBe('ffffdg');
    expect($josn_data['description'])->toBe('asdadafew aadd');
})->depends('Check fail audit_log config');


/**
 * New table
 */
test('Create new table', function () {
    Schema::connection($this->test_db_conn)
        ->create('test_new_table', function (Blueprint $table) {
            $table->unsignedBigInteger('id');
            $table->primary(array('id'));
        });
    expect(true)->toBeTrue();
})->depends('Check fail audit_log config');

test('Run checker (new table)', function () {
runAuditLogChecker($this);
})->depends('Create new table');

test('Has new table audit_log table and trigger', function () {

    $audit_logger = new AuditLogCreator($this->test_db_conn);
    $triggers = $audit_logger->getTriggers();

    $table_name = 'test_new_table';
    $table_trigger = $triggers
        ->where('EVENT_OBJECT_TABLE', $table_name);


    expect($table_trigger->count())->toBe(3);
    expect(
        Schema::connection($this->test_db_conn)
            ->hasTable($table_name . '_audit_log')
    )->toBeTrue();
})->depends('Run checker (new table)');

test('Remove new table', function () {
    Schema::connection($this->test_db_conn)->dropIfExists('test_new_table');

    runAuditLogChecker($this);
})->depends('Create new table');

test('Removed new table audit_log table', function () {
    $table_name = 'test_new_table';
    expect(
        Schema::connection($this->test_db_conn)
            ->hasTable($table_name . '_audit_log')
    )->toBeTrue();
})->depends('Remove new table');


/**
 * New column
 */

test('Create new column', function () {
    Schema::connection($this->test_db_conn)
        ->table('test_default', function (Blueprint $table) {
            $table->string('new', 200);
        });
    expect(true)->toBeTrue();
})->depends('Check fail audit_log config');

test('Run checker (new column)', function () {
    runAuditLogChecker($this);
})->depends('Create new column');

test('Check new column audit_log trigger', function () {

    $audit_logger = new AuditLogCreator($this->test_db_conn);
    $triggers = $audit_logger->getTriggers();

    $table_name = 'test_default';
    $table_trigger = $triggers
        ->where('EVENT_OBJECT_TABLE', $table_name);

    DB::connection($this->test_db_conn)
        ->table($table_name)
        ->insert([
            'id' => 1222,
            'role'  => 'asdaadd',
            'user'  => 'asdaadd',
            'description' => 'asdadafew aadd',
            'new' => 'sssdddss'
        ]);

    $row = DB::connection($this->test_db_conn)
        ->table($table_name . '_audit_log')
        ->where('id', 1222)
        ->where('dml_type', 'INSERT');

    expect($row->count())->toBe(1);
    $firstRow = $row->first();
    $josn_data = json_decode($firstRow->new_row_data, true);

    expect($josn_data['new'])->toBe('sssdddss');
})->depends('Run checker (new column)');

test('Remove new column', function () {

    Schema::connection($this->test_db_conn)
        ->table('test_default', function (Blueprint $table) {
            $table->dropColumn('new');
        });

        runAuditLogChecker($this);
})->depends('Create new column');

test('Check removed new column audit_log trigger', function () {
    $table_name = 'test_default';

    DB::connection($this->test_db_conn)
        ->table($table_name)
        ->insert([
            'id' => 1223,
            'role'  => 'asdaadd',
            'user'  => 'asdaadd',
            'description' => 'asdadafew aadd'
        ]);
    $row = DB::connection($this->test_db_conn)
        ->table($table_name . '_audit_log')
        ->where('id', 1223)
        ->where('dml_type', 'INSERT');

    expect($row->count())->toBe(1);
    $firstRow = $row->first();
    $josn_data = json_decode($firstRow->old_row_data, true);
    expect($josn_data)->not->toHaveKey('new');
})->depends('Run checker (new column)');


test('Remove tables', function () {

    $tables = [
        'test_ignore_true',
        'test_ignore_true_audit_log',
        'test_ignore_false',
        'test_ignore_false_audit_log',
        'test_field_ignore',
        'test_field_ignore_audit_log',
        'test_id_field_change',
        'test_id_field_change_audit_log',
        'test_default',
        'test_default_audit_log',
        'test_new_table',
        'test_new_table_audit_log'
    ];

    foreach ($tables as $table) {
        Schema::connection($this->test_db_conn)->dropIfExists($table);
    }

    expect(true)->toBeTrue();
});
