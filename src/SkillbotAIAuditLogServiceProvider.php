<?php

namespace SkillbotAI\AuditLog;

use SkillbotAI\AuditLog\Console\Commands\AuditLogCheck;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SkillbotAIAuditLogServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('skillbotai-auditlog')
            ->hasConfigFile()
            ->hasTranslations()
            ->hasViews()
            ->hasCommands($this->getCommands());
    }

    protected function getCommands(): array
    {
        return [
            AuditLogCheck::class,
        ];
    }
}
