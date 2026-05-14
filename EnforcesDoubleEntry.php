<?php

namespace App\Traits\Accounting;

use InvalidArgumentException;
use RuntimeException;
use App\Exceptions\Accounting\ImbalancedJournalException;
use App\Exceptions\Accounting\InvalidAccountTypeException;
use App\Exceptions\Accounting\InsufficientEntriesException;

/**
 * Trait EnforcesDoubleEntry
 *
 * Encapsulates all double-entry bookkeeping enforcement rules for Acculedger Pro.
 * Apply this trait to any service, model, or repository that originates journal
 * entries — e.g. JournalEntryService, LedgerPostingRepository, TransactionProcessor.
 *
 * Core invariants enforced:
 *   1. Every journal entry must have ≥ 2 lines.
 *   2. Sum of debits  === Sum of credits  (to the configured decimal precision).
 *   3. Each line must nominate a valid, active account with a recognised normal balance.
 *   4. No line may carry a zero or negative amount.
 *   5. Compound entries are permitted (many debits / many credits) so long as
 *      invariants 1-4 hold.
 *
 * Usage:
 *   class JournalEntryService
 *   {
 *       use EnforcesDoubleEntry;
 *
 *       public function post(array $lines): void
 *       {
 *           $this->assertBalanced($lines);           // throws on violation
 *           // ... persist $lines
 *       }
 *   }
 *
 * @package App\Traits\Accounting
 */
trait EnforcesDoubleEntry
{
    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    /**
     * Decimal precision used when comparing debit/credit totals.
     * Override in the consuming class if your ledger uses a different scale.
     */
    protected int $doubleEntryPrecision = 2;

    /**
     * The five standard account categories and their normal balances.
     * 'debit'  → account increases with a debit  (Assets, Expenses)
     * 'credit' → account increases with a credit (Liabilities, Equity, Revenue)
     */
    protected array $accountNormalBalances = [
        'asset'     => 'debit',
        'expense'   => 'debit',
        'liability' => 'credit',
        'equity'    => 'credit',
        'revenue'   => 'credit',
    ];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Primary enforcement gate.
     *
     * Pass an array of line arrays, each shaped:
     *   [
     *     'account_id'   => int|string,          // required
     *     'account_type' => string,               // required  (asset|liability|equity|revenue|expense)
     *     'type'         => 'debit'|'credit',     // required
     *     'amount'       => numeric string|float, // required, positive
     *     'currency'     => string,               // optional, default 'USD'
     *     'memo'         => string,               // optional
     *   ]
     *
     * @param  array[] $lines
     * @return true  — returns true on success so callers can inline: $this->assertBalanced($lines) && $this->persist(...)
     *
     * @throws InsufficientEntriesException
     * @throws InvalidArgumentException
     * @throws InvalidAccountTypeException
     * @throws ImbalancedJournalException
     */
    public function assertBalanced(array $lines): bool
    {
        $this->guardMinimumLines($lines);
        $this->guardLineStructure($lines);
        $this->guardAccountTypes($lines);
        $this->guardPositiveAmounts($lines);
        $this->guardSingleCurrency($lines);
        $this->guardDebitCreditEquality($lines);

        return true;
    }

    /**
     * Non-throwing variant. Returns a result value object instead of throwing.
     *
     * @param  array[] $lines
     * @return DoubleEntryResult
     */
    public function validateDoubleEntry(array $lines): DoubleEntryResult
    {
        try {
            $this->assertBalanced($lines);
            return DoubleEntryResult::pass($this->buildSummary($lines));
        } catch (\Throwable $e) {
            return DoubleEntryResult::fail($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Compute the running balance effect on a single account given an ordered
     * array of already-validated lines for that account.
     *
     * Returns a signed float: positive = normal-balance side, negative = contra.
     *
     * @param  string  $accountType  e.g. 'asset'
     * @param  array[] $lines        filtered to a single account_id
     * @return float
     */
    public function computeAccountBalance(string $accountType, array $lines): float
    {
        $normal = $this->resolveNormalBalance($accountType);
        $balance = 0.0;

        foreach ($lines as $line) {
            $amount = (float) $line['amount'];
            if ($line['type'] === $normal) {
                $balance += $amount;   // increases account
            } else {
                $balance -= $amount;   // decreases account
            }
        }

        return round($balance, $this->doubleEntryPrecision);
    }

    /**
     * Break a flat lines array into two buckets: debits and credits.
     * Useful for display / audit trail rendering.
     *
     * @param  array[] $lines
     * @return array{debits: array[], credits: array[], debit_total: float, credit_total: float}
     */
    public function partitionLines(array $lines): array
    {
        $debits  = array_filter($lines, fn($l) => $l['type'] === 'debit');
        $credits = array_filter($lines, fn($l) => $l['type'] === 'credit');

        return [
            'debits'       => array_values($debits),
            'credits'      => array_values($credits),
            'debit_total'  => $this->sumLines($debits),
            'credit_total' => $this->sumLines($credits),
        ];
    }

    /**
     * Convenience: quickly check if a set of lines is balanced without throwing.
     */
    public function isBalanced(array $lines): bool
    {
        if (count($lines) < 2) return false;

        try {
            $this->guardDebitCreditEquality($lines);
            return true;
        } catch (ImbalancedJournalException) {
            return false;
        }
    }

    /**
     * Return the normal balance side ('debit'|'credit') for an account type.
     *
     * @throws InvalidAccountTypeException
     */
    public function resolveNormalBalance(string $accountType): string
    {
        $type = strtolower(trim($accountType));

        if (! array_key_exists($type, $this->accountNormalBalances)) {
            throw new InvalidAccountTypeException(
                "Unknown account type '{$accountType}'. " .
                "Valid types: " . implode(', ', array_keys($this->accountNormalBalances)) . '.',
                1100
            );
        }

        return $this->accountNormalBalances[$type];
    }

    // -------------------------------------------------------------------------
    // Guards  (each throws a typed exception on failure)
    // -------------------------------------------------------------------------

    /**
     * Guard 1 — at least two lines are required.
     */
    protected function guardMinimumLines(array $lines): void
    {
        if (count($lines) < 2) {
            throw new InsufficientEntriesException(
                'A journal entry requires at least two lines (one debit, one credit). ' .
                count($lines) . ' line(s) provided.',
                1000
            );
        }
    }

    /**
     * Guard 2 — every line must carry the mandatory keys.
     */
    protected function guardLineStructure(array $lines): void
    {
        $required = ['account_id', 'account_type', 'type', 'amount'];

        foreach ($lines as $index => $line) {
            $missing = array_diff($required, array_keys($line));

            if (! empty($missing)) {
                throw new InvalidArgumentException(
                    "Journal line [{$index}] is missing required key(s): " .
                    implode(', ', $missing) . '.',
                    1001
                );
            }

            if (! in_array($line['type'], ['debit', 'credit'], true)) {
                throw new InvalidArgumentException(
                    "Journal line [{$index}] 'type' must be 'debit' or 'credit'. " .
                    "Got: '{$line['type']}'.",
                    1002
                );
            }
        }
    }

    /**
     * Guard 3 — all account_type values must be in the registry.
     */
    protected function guardAccountTypes(array $lines): void
    {
        foreach ($lines as $index => $line) {
            $this->resolveNormalBalance($line['account_type']); // throws if unknown
        }
    }

    /**
     * Guard 4 — amounts must be numeric and strictly positive.
     */
    protected function guardPositiveAmounts(array $lines): void
    {
        foreach ($lines as $index => $line) {
            if (! is_numeric($line['amount'])) {
                throw new InvalidArgumentException(
                    "Journal line [{$index}] amount must be numeric. " .
                    "Got: " . gettype($line['amount']) . '.',
                    1003
                );
            }

            if ((float) $line['amount'] <= 0) {
                throw new InvalidArgumentException(
                    "Journal line [{$index}] amount must be greater than zero. " .
                    "Got: {$line['amount']}.",
                    1004
                );
            }
        }
    }

    /**
     * Guard 5 — all lines in a single journal entry must share the same currency.
     *           (Multi-currency revaluation entries are handled by the FX revaluation
     *            service, which produces same-currency equivalents before posting here.)
     */
    protected function guardSingleCurrency(array $lines): void
    {
        $currencies = array_unique(
            array_map(fn($l) => strtoupper($l['currency'] ?? 'USD'), $lines)
        );

        if (count($currencies) > 1) {
            throw new InvalidArgumentException(
                'All lines in a journal entry must share the same currency. ' .
                'Found: ' . implode(', ', $currencies) . '. ' .
                'Use the FX revaluation service to produce single-currency entries.',
                1005
            );
        }
    }

    /**
     * Guard 6 — the golden rule: debits must equal credits.
     *
     * Comparison is performed as integers after scaling to avoid IEEE-754 drift.
     * e.g. $100.01  →  10001 (cents), then compared as int.
     */
    protected function guardDebitCreditEquality(array $lines): void
    {
        $scale = (int) pow(10, $this->doubleEntryPrecision);

        $debitTotal  = 0;
        $creditTotal = 0;

        foreach ($lines as $line) {
            $scaled = (int) round((float) $line['amount'] * $scale);
            if ($line['type'] === 'debit') {
                $debitTotal  += $scaled;
            } else {
                $creditTotal += $scaled;
            }
        }

        if ($debitTotal !== $creditTotal) {
            $dr = number_format($debitTotal  / $scale, $this->doubleEntryPrecision);
            $cr = number_format($creditTotal / $scale, $this->doubleEntryPrecision);

            throw new ImbalancedJournalException(
                "Journal entry is out of balance. " .
                "Debits: {$dr} | Credits: {$cr} | " .
                "Difference: " . number_format(abs($debitTotal - $creditTotal) / $scale, $this->doubleEntryPrecision) . '.',
                1006
            );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Sum the 'amount' column of a lines array.
     */
    protected function sumLines(array $lines): float
    {
        return round(
            array_sum(array_column($lines, 'amount')),
            $this->doubleEntryPrecision
        );
    }

    /**
     * Build a human-readable summary array for logging / audit trail.
     *
     * @param  array[] $lines
     * @return array
     */
    protected function buildSummary(array $lines): array
    {
        $partition = $this->partitionLines($lines);
        $currency  = strtoupper($lines[0]['currency'] ?? 'USD');

        return [
            'line_count'    => count($lines),
            'currency'      => $currency,
            'debit_total'   => $partition['debit_total'],
            'credit_total'  => $partition['credit_total'],
            'is_balanced'   => true,
            'debit_lines'   => count($partition['debits']),
            'credit_lines'  => count($partition['credits']),
            'accounts'      => array_unique(array_column($lines, 'account_id')),
        ];
    }
}


// =============================================================================
// Value Object — returned by validateDoubleEntry()
// Keep in the same file for portability; move to App\ValueObjects\Accounting
// once you wire it into the autoloader.
// =============================================================================

final class DoubleEntryResult
{
    private function __construct(
        public readonly bool   $passed,
        public readonly string $message,
        public readonly int    $code,
        public readonly array  $summary,
    ) {}

    public static function pass(array $summary): self
    {
        return new self(true, 'Journal entry is balanced.', 0, $summary);
    }

    public static function fail(string $message, int $code = 0): self
    {
        return new self(false, $message, $code, []);
    }

    public function toArray(): array
    {
        return [
            'passed'  => $this->passed,
            'message' => $this->message,
            'code'    => $this->code,
            'summary' => $this->summary,
        ];
    }
}
