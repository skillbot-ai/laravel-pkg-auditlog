<?php
use Illuminate\Support\Facades\Log;
use SkillbotAI\AuditLog\Services\AuditLogCreator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$selected_database = [];

uses(Tests\TestCase::class, Tests\CreatesApplication::class);


function runAuditLogCheckerMigration($than)
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

test('Create migration tables', function () {

    Schema::connection($this->test_db_conn)->create('test_default', function (Blueprint $table) {
        $table->unsignedBigInteger('id');
        $table->string('role', 200);
        $table->string('user', 200);
        $table->string('description', 200);
        $table->primary(array('id'));
    });

    Schema::connection($this->test_db_conn)->create('test_default'  . '_audit_log', function (Blueprint $table) {

        $table->unsignedBigInteger('id');
        $table->json('old_row_data')->nullable();
        $table->json('new_row_data')->nullable();
        $table->enum('dml_type', ['INSERT', 'UPDATE', 'DELETE']);
        $table->timestamp('dml_timestamp');
        $table->string('dml_created_by', 200);
        $table->primary(array('id', 'dml_type', 'dml_timestamp'));
    });
    expect(true)->toBeTrue();
});


test('Check migration tables', function () use (&$selected_database) {
    $tables = DB::connection($this->test_db_conn)
        ->table('INFORMATION_SCHEMA.COLUMNS')
        ->select('TABLE_NAME', 'COLUMN_NAME')
        ->where('TABLE_SCHEMA', $this->selected_database['database'])
        ->where('TABLE_NAME', 'not like', 'test_%')
        ->get();
    $table_names = $tables->pluck('TABLE_NAME')->unique()->toArray();

    $ignore_tables =  [
        'test_ignore_true' => [
            'ignore' => true,
        ],
        'test_ignore_false' => [
            'ignore' => false,
        ],
        'test_field_ignore' => [
            'ignore_fields' => ['password', 'uuid'],
        ],
        'test_id_field_change' => [
            'id_field' => 'permission_id',
        ]
    ];
    foreach ($table_names as $table_name) {
        $ignore_tables[$table_name] = [
            'ignore' => true,
        ];
    }
    //config(['database.connections.' . $this->test_db_conn.'.audit_log.tables' => array_merge($this->selected_database['audit_log']['tables'], $ignore_tables)]);
    $selected_database = $this->selected_database;
    $selected_database['audit_log']['tables'] = array_merge($this->selected_database['audit_log']['tables'], $ignore_tables);
    expect(true)->toBeTrue();
})->depends('Create migration tables');


test('Check fail audit_log config', function () {

    $audit_logger = new AuditLogCreator($this->test_db_conn);
    $audit_logger->setSelectedDatabaseConfig([]);

    expect(
        fn () => $audit_logger->checkConfigAuditlog()
    )->toThrow(Exception::class);;
})->depends('Check migration tables');


test('Run checker', function () {
    runAuditLogCheckerMigration($this);
})->depends('Check fail audit_log config');


test('Test migrated tables', function () use (&$selected_database) {

    $keys = DB::connection($this->test_db_conn)
        ->table('INFORMATION_SCHEMA.COLUMNS')
        ->select('TABLE_NAME', 'COLUMN_NAME')
        ->where('TABLE_SCHEMA', $this->selected_database['database'])
        ->where('TABLE_NAME', 'test_default_audit_log')
        ->where('COLUMN_KEY', 'PRI')
        ->count();

    expect($keys)->toBe(0);

    $keys = DB::connection($this->test_db_conn)
        ->table('INFORMATION_SCHEMA.COLUMNS')
        ->select('TABLE_NAME', 'COLUMN_NAME')
        ->where('TABLE_SCHEMA', $this->selected_database['database'])
        ->where('TABLE_NAME', 'test_default_audit_log')
        ->where('COLUMN_KEY', 'MUL')
        ->count();

    expect($keys)->toBe(1);

})->depends('Create migration tables');

test('Remove migration tables', function () {

    $tables = [
        'test_default',
        'test_default_audit_log',
    ];

    foreach ($tables as $table) {
        Schema::connection($this->test_db_conn)->dropIfExists($table);
    }

    expect(true)->toBeTrue();
});
