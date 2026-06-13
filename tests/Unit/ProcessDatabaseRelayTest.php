<?php

namespace Tests\Unit;

use App\Jobs\ProcessDatabaseRelay;
use Tests\TestCase;

class ProcessDatabaseRelayTest extends TestCase
{
    public function test_it_filters_payload_keys_to_actual_table_columns(): void
    {
        $job = new class('Periods', 'ID', []) extends ProcessDatabaseRelay
        {
            public function exposeFilterRecord(array $record, array $validColumns): array
            {
                return $this->filterRecordToSchemaColumns($record, $validColumns);
            }
        };

        $record = [
            'Branch_ID' => 10,
            'Name' => 'Test Name',
            'UnexpectedField' => 'should be ignored',
        ];

        $filtered = $job->exposeFilterRecord($record, ['ID', 'Name', 'Branch_ID']);

        $this->assertSame([
            'Branch_ID' => 10,
            'Name' => 'Test Name',
        ], $filtered);
    }
}
