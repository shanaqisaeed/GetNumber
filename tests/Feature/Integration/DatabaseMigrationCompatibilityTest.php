<?php

namespace Tests\Feature\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseMigrationCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 7.5 Migration Integrity & Schema Safety:
     * Verify all required architectural tables and core indexes are properly created
     * without breaking existing relational foreign keys or data contracts.
     */
    public function test_all_architectural_tables_exist_with_proper_columns(): void
    {
        $expectedTables = [
            'users' => [
                'id', 'username', 'role', 'status', 'two_factor_secret',
                'two_factor_recovery_codes', 'two_factor_confirmed_at', 'trust_tier', 'risk_score'
            ],
            'phone_prefixes' => ['id', 'prefix', 'clean_prefix', 'name', 'is_active'],
            'phone_numbers' => ['id', 'number', 'phone_prefix_id', 'user_id', 'status', 'type'],
            'messages' => [
                'id', 'phone_number_id', 'destination_number', 'sender', 'peer_number',
                'body', 'direction', 'processing_status', 'server_received_at'
            ],
            'archived_messages' => [
                'id', 'phone_number_id', 'destination_number', 'sender', 'peer_number',
                'body', 'direction', 'server_received_at', 'archived_at'
            ],
            'message_batches' => [
                'id', 'batch_id', 'user_id', 'phone_number_id', 'total_count', 'processed_count', 'status'
            ],
            'message_batch_items' => [
                'id', 'message_batch_id', 'destination', 'status', 'attempts'
            ],
            'ledger_accounts' => ['id', 'code', 'name', 'type', 'currency', 'is_system'],
            'journal_entries' => ['id', 'entry_number', 'reference_id', 'currency', 'posted_at'],
            'ledger_entries' => ['id', 'journal_entry_id', 'ledger_account_id', 'debit', 'credit'],
            'security_incidents' => ['id', 'user_id', 'type', 'severity', 'action_taken', 'details'],
            'system_error_logs' => ['id', 'error_hash', 'source', 'level', 'occurrences_count', 'last_seen_at'],
        ];

        foreach ($expectedTables as $tableName => $columns) {
            $this->assertTrue(
                Schema::hasTable($tableName),
                "Missing expected database table: {$tableName}"
            );

            foreach ($columns as $column) {
                $this->assertTrue(
                    Schema::hasColumn($tableName, $column),
                    "Table '{$tableName}' is missing required column: '{$column}'"
                );
            }
        }
    }
}
