<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RefreshDatabase, but the one-time migrate:fresh loads the SchemaSnapshot. These overrides have to
 * live in a trait: Pest applies traits to each test class, and a trait's methods win over ones
 * inherited from Tests\TestCase.
 */
trait RefreshDatabaseFromSnapshot
{
    use RefreshDatabase {
        migrateFreshUsing as baseMigrateFreshUsing;
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
