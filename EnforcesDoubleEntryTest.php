<?php

namespace Tests\Unit\Traits\Accounting;

use PHPUnit\Framework\TestCase;
use App\Traits\Accounting\EnforcesDoubleEntry;
use App\Exceptions\Accounting\ImbalancedJournalException;
use App\Exceptions\Accounting\InvalidAccountTypeException;
use App\Exceptions\Accounting\InsufficientEntriesException;
use InvalidArgumentException;

/**
 * Concrete stub that uses the trait — required because traits can't be
 * instantiated directly.
 */
class LedgerStub
{
    use EnforcesDoubleEntry;
}

/**
 * Class EnforcesDoubleEntryTest
 *
 * Run with:
 *   php artisan test --filter EnforcesDoubleEntryTest
 *   ./vendor/bin/phpunit tests/Unit/Traits/Accounting/EnforcesDoubleEntryTest.php
 */
class EnforcesDoubleEntryTest extends TestCase
{
    private LedgerStub $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = new LedgerStub();
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /** Simple balanced entry: DR Cash / CR Revenue */
    private function balancedEntry(): array
    {
        return [
            ['account_id' => 1001, 'account_type' => 'asset',   'type' => 'debit',  'amount' => '500.00', 'currency' => 'USD'],
            ['account_id' => 4001, 'account_type' => 'revenue',  'type' => 'credit', 'amount' => '500.00', 'currency' => 'USD'],
        ];
    }

    /** Compound entry: one debit, two credits */
    private function compoundEntry(): array
    {
        return [
            ['account_id' => 1001, 'account_type' => 'asset',     'type' => 'debit',  'amount' => '1200.00'],
            ['account_id' => 4001, 'account_type' => 'revenue',   'type' => 'credit', 'amount' =>  '700.00'],
            ['account_id' => 2001, 'account_type' => 'liability', 'type' => 'credit', 'amount' =>  '500.00'],
        ];
    }

    // -------------------------------------------------------------------------
    // assertBalanced — happy paths
    // -------------------------------------------------------------------------

    public function test_balanced_simple_entry_passes(): void
    {
        $this->assertTrue($this->ledger->assertBalanced($this->balancedEntry()));
    }

    public function test_balanced_compound_entry_passes(): void
    {
        $this->assertTrue($this->ledger->assertBalanced($this->compoundEntry()));
    }

    public function test_balanced_with_float_precision_passes(): void
    {
        // Classic floating-point trap: 0.1 + 0.2 ≠ 0.3 in IEEE-754
        $lines = [
            ['account_id' => 1, 'account_type' => 'asset',   'type' => 'debit',  'amount' => 0.1 + 0.2],
            ['account_id' => 2, 'account_type' => 'revenue',  'type' => 'credit', 'amount' => 0.3],
        ];
        $this->assertTrue($this->ledger->assertBalanced($lines));
    }

    // -------------------------------------------------------------------------
    // assertBalanced — guard violations
    // -------------------------------------------------------------------------

    public function test_single_line_throws_insufficient_entries(): void
    {
        $this->expectException(InsufficientEntriesException::class);
        $this->expectExceptionCode(1000);

        $this->ledger->assertBalanced([
            ['account_id' => 1, 'account_type' => 'asset', 'type' => 'debit', 'amount' => 100],
        ]);
    }

    public function test_empty_array_throws_insufficient_entries(): void
    {
        $this->expectException(InsufficientEntriesException::class);
        $this->ledger->assertBalanced([]);
    }

    public function test_missing_required_key_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1001);

        $lines = $this->balancedEntry();
        unset($lines[0]['amount']);            // remove required key
        $this->ledger->assertBalanced($lines);
    }

    public function test_invalid_type_value_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1002);

        $lines              = $this->balancedEntry();
        $lines[0]['type']   = 'increase';      // neither 'debit' nor 'credit'
        $this->ledger->assertBalanced($lines);
    }

    public function test_unknown_account_type_throws_invalid_account_type(): void
    {
        $this->expectException(InvalidAccountTypeException::class);
        $this->expectExceptionCode(1100);

        $lines                      = $this->balancedEntry();
        $lines[0]['account_type']   = 'banana';
        $this->ledger->assertBalanced($lines);
    }

    public function test_zero_amount_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1004);

        $lines              = $this->balancedEntry();
        $lines[0]['amount'] = 0;
        $this->ledger->assertBalanced($lines);
    }

    public function test_negative_amount_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1004);

        $lines              = $this->balancedEntry();
        $lines[0]['amount'] = -100;
        $this->ledger->assertBalanced($lines);
    }

    public function test_non_numeric_amount_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1003);

        $lines              = $this->balancedEntry();
        $lines[0]['amount'] = 'one hundred';
        $this->ledger->assertBalanced($lines);
    }

    public function test_mixed_currencies_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1005);

        $lines              = $this->balancedEntry();
        $lines[1]['currency'] = 'GBP';          // different from 'USD'
        $this->ledger->assertBalanced($lines);
    }

    public function test_imbalanced_entry_throws_imbalanced_journal(): void
    {
        $this->expectException(ImbalancedJournalException::class);
        $this->expectExceptionCode(1006);

        $lines              = $this->balancedEntry();
        $lines[0]['amount'] = '600.00';         // DR 600, CR 500 → out of balance
        $this->ledger->assertBalanced($lines);
    }

    // -------------------------------------------------------------------------
    // validateDoubleEntry — value-object variant
    // -------------------------------------------------------------------------

    public function test_validate_returns_pass_result_on_balanced_entry(): void
    {
        $result = $this->ledger->validateDoubleEntry($this->balancedEntry());
        $this->assertTrue($result->passed);
        $this->assertEquals(0, $result->code);
        $this->assertNotEmpty($result->summary);
    }

    public function test_validate_returns_fail_result_on_imbalanced_entry(): void
    {
        $lines              = $this->balancedEntry();
        $lines[0]['amount'] = '999.00';

        $result = $this->ledger->validateDoubleEntry($lines);
        $this->assertFalse($result->passed);
        $this->assertEquals(1006, $result->code);
    }

    // -------------------------------------------------------------------------
    // computeAccountBalance
    // -------------------------------------------------------------------------

    public function test_asset_account_increases_on_debit(): void
    {
        $lines = [
            ['account_id' => 1, 'account_type' => 'asset', 'type' => 'debit',  'amount' => 1000],
            ['account_id' => 1, 'account_type' => 'asset', 'type' => 'credit', 'amount' =>  300],
        ];
        $balance = $this->ledger->computeAccountBalance('asset', $lines);
        $this->assertEquals(700.00, $balance);
    }

    public function test_liability_account_increases_on_credit(): void
    {
        $lines = [
            ['account_id' => 2, 'account_type' => 'liability', 'type' => 'credit', 'amount' => 500],
            ['account_id' => 2, 'account_type' => 'liability', 'type' => 'debit',  'amount' => 200],
        ];
        $balance = $this->ledger->computeAccountBalance('liability', $lines);
        $this->assertEquals(300.00, $balance);
    }

    // -------------------------------------------------------------------------
    // partitionLines
    // -------------------------------------------------------------------------

    public function test_partition_correctly_separates_debits_and_credits(): void
    {
        $partition = $this->ledger->partitionLines($this->compoundEntry());

        $this->assertCount(1, $partition['debits']);
        $this->assertCount(2, $partition['credits']);
        $this->assertEquals(1200.00, $partition['debit_total']);
        $this->assertEquals(1200.00, $partition['credit_total']);
    }

    // -------------------------------------------------------------------------
    // isBalanced convenience
    // -------------------------------------------------------------------------

    public function test_is_balanced_returns_true_for_balanced_entry(): void
    {
        $this->assertTrue($this->ledger->isBalanced($this->balancedEntry()));
    }

    public function test_is_balanced_returns_false_for_imbalanced_entry(): void
    {
        $lines              = $this->balancedEntry();
        $lines[0]['amount'] = '999.00';
        $this->assertFalse($this->ledger->isBalanced($lines));
    }

    public function test_is_balanced_returns_false_for_single_line(): void
    {
        $this->assertFalse($this->ledger->isBalanced([
            ['account_id' => 1, 'account_type' => 'asset', 'type' => 'debit', 'amount' => 100],
        ]));
    }

    // -------------------------------------------------------------------------
    // resolveNormalBalance
    // -------------------------------------------------------------------------

    /** @dataProvider normalBalanceProvider */
    public function test_normal_balance_resolution(string $type, string $expected): void
    {
        $this->assertEquals($expected, $this->ledger->resolveNormalBalance($type));
    }

    public static function normalBalanceProvider(): array
    {
        return [
            'asset is debit'     => ['asset',     'debit'],
            'expense is debit'   => ['expense',   'debit'],
            'liability is credit'=> ['liability', 'credit'],
            'equity is credit'   => ['equity',    'credit'],
            'revenue is credit'  => ['revenue',   'credit'],
        ];
    }
}
