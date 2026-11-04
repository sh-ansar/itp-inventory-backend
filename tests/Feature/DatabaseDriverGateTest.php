<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CI must prove it is testing the requested database engine. This prevents a
 * PHPUnit XML "force" value from silently turning PostgreSQL CI into SQLite.
 */
final class DatabaseDriverGateTest extends TestCase
{
    public function test_database_driver_matches_explicit_ci_expectation(): void
    {
        $expected = getenv('INVENTORY_EXPECT_DATABASE_DRIVER');
        $actual = DB::connection()->getDriverName();

        $this->assertContains($actual, ['sqlite', 'pgsql']);
        if ($expected !== false && $expected !== '') {
            $this->assertSame($expected, $actual, 'Database CI must not silently fall back to SQLite');
        }
    }
}
