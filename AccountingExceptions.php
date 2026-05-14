<?php

namespace App\Exceptions\Accounting;

/**
 * Thrown when a journal entry has fewer than two lines.
 */
class InsufficientEntriesException extends \RuntimeException {}

/**
 * Thrown when an account_type string is not in the normal-balance registry.
 */
class InvalidAccountTypeException extends \InvalidArgumentException {}

/**
 * Thrown when the sum of debits does not equal the sum of credits.
 * This is the primary accounting invariant violation.
 */
class ImbalancedJournalException extends \RuntimeException {}
