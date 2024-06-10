<?php

namespace SkillbotAI\AuditLog\Console\Commands;

use Illuminate\Console\Command;
use SkillbotAI\AuditLog\Services\AuditLogCreator as ServicesAuditLogCreator;

class AuditLogCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'auditlog:check {database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check and create audit log';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        
        $auditLogCreator = new ServicesAuditLogCreator($this->argument('database'));
        $auditLogCreator->checkDatabase();

        return 0;
    }
}