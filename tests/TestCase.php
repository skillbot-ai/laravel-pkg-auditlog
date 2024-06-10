<?php

namespace SkillbotAI\AuditLog\Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use SkillbotAI\AuditLog\Tests;

abstract class TestCase extends BaseTestCase
{
    use \Tests\CreatesApplication;
}
