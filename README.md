# Laravel audit Log package

Audit log trigger creator and checker Laravel package.

## Features 
 * Check all database table.
 * If there is no audit_log table, create (table_name) audit log table
 * If a table has no triggers (insert, update, delete), then created triggers
 * When change database field, it add orr removed field in trigger
 * When change trigger sql in package, it refreshd all trigger.
 * When created new table, it created audit_log table and trigger.
 * When removing a table, it does not remove the audit_log table.
 * It can be configured with the ./config/databse.php file (ignore tables or fields).



## Installation

### 0. Set database permission
Set the log_bin_trust_function_creators permission.
```
mysql> SET GLOBAL log_bin_trust_function_creators = 1;
```
OR

[Configure mysql service](#configure-the-local-development-environment)

### 1. Config composer
```json
{
    "repositories": [
        {
            "type": "vcs",
            "url":  "git@github.com:skillbot-ai/laravel-pkg-auditlog.git"
        }
    ],
    "require": {
        "skillbotai/laravel-pkg-auditlog": "dev-main"
    },
}
```

### 2. Composer install
```bash
composer update
```

### 3. Configure database
Config documantation
```php
<?php
// ./config/database.php

return [
    // ...
    'connections' => [
        // ...
        'mysql' => [
            // ...
            'audit_log' => [
                'tables' => [
                    'migrations' => [
                        'ignore' => true,
                    ],
                    'failed_jobs' => [
                        'ignore' => true,
                    ],
                    'personal_access_tokens' => [
                        'ignore' => true,
                    ],
                ],
            ],
        ],
    ],
];
```
### 5. Configure docker-entrypoint.sh
Insert auditlog:check commend after migrate and other database modifeyer command.
`mysql` is specific database name. (config/database.php connections.mysql)
```bash
echo "Run php artisan migrate"
php artisan migrate --isolated --force

echo "Check auditlog tables"
php artisan auditlog:check mysql
```


## Configure the local development environment

Set the log_bin_trust_function_creators permission.
```INI
# .docker/mysql/my.conf
[mysqld]
general_log = 1
general_log_file = /var/lib/mysql/general.log
log_bin_trust_function_creators = 1
```

```yaml
# docker-compose.yml
services:
  skillset-database:
    # ...
    volumes:
      - skillset-dbdata-vol:/var/lib/mysql
      - ./.docker/mysql/my.cnf:/etc/mysql/my.cnf
    # ...
```

## Database trigger config

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| connections | `Array` | Standard Laravel database connection definitios in ./config/database.php file |
| connections.\* | `Array` | Database configuration. e.g: 'mysql': [] |
| connections.\*.audit_log | `Array` | `Required` Audit log configuration. |
| connections.\*.audit_log.tables | `Array` | `Required` Audit log tables config. If empty, it checks all tables. |
| connections.\*.audit_log.tables.\* | `Array` | Table name. If the given table is not defined, then it sets audit triggers. |
| connections.\*.audit_log.tables.\*.ignore | `Boolean` | If `true`, ignore the table check. Default `true`. |
| connections.\*.audit_log.tables.\*.ignore_fields | `Array` | Ignore specific fields. e.g: `['password', 'uuid']`|
| connections.\*.audit_log.tables.\*.id_field | `String` | Change default id field e.g: If there is no `id` field, but we want to use the `uuid` field instead. If the `id` field does not exist, it will not be recorded. |

## Setup testing enviroment

### 1. Install Pest tester
```bash
composer require pestphp/pest --dev --with-all-dependencies
```

### 2. Configure database
Config documantation
```php
<?php
// ./config/database.php

return [
    // ...
    'connections' => [
        // ...
        'mysql_test' => [
            // ...
            'audit_log' => [
                'tables' => [
                    'migrations' => [
                        'ignore' => true,
                    ],
                    'failed_jobs' => [
                        'ignore' => true,
                    ],
                    'personal_access_tokens' => [
                        'ignore' => true,
                    ],
                ],
            ],
        ],
    ],
];
```

### 3. Run Tst
```bash
php artisan test ./vendor/skillbotai/laravel-pkg-auditlog/tests/   
```
