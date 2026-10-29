<?php

namespace Tests;

use App\Console\Commands\InstanceMigrate;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RefreshDatabase, but the one-time migrate:fresh loads the SchemaSnapshot, and the control and accounts
 * schemas (InstanceSchemas) are set up too and rolled back after each test like the main one. These
 * overrides have to live in a trait: Pest applies traits to each test class, and a trait's methods win
 * over ones inherited from Tests\TestCase.
 */
trait RefreshDatabaseFromSnapshot
{
    use RefreshDatabase {
        migrateFreshUsing as baseMigrateFreshUsing;
        refreshDatabase as baseRefreshDatabase;
    }

    public function refreshDatabase()
    {
        InstanceSchemas::prepare();
        $this->baseRefreshDatabase();
    }

    protected function connectionsToTransact()
    {
        return [null, ...array_keys(InstanceMigrate::INSTANCE_SCHEMAS)];
    }

    protected function migrateFreshUsing()
    {
        return $this->baseMigrateFreshUsing() + SchemaSnapshot::freshOptions();
    }

    protected function afterRefreshingDatabase()
    {
        SchemaSnapshot::saveIfMissing();
    }
}
