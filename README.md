
<h1>AccuLedger: Double-Entry Integrity Traits for Laravel</h1>

<quote>"Ledgers meet Logic. Every data model has a debit; every API call has a corresponding credit."</quote>

<h2> Professional Context </h2>
This project is part of a larger strategic roadmap for <strong>NOC 21232 (Software Developer)</strong> and <strong>NOC 21203 (Financial Systems Analyst)</strong> alignment within the Canadian tech market.

<strong>Author:</strong> Roger De Four, Systems Accountant  
<strong>Company:</strong> [RAD Software Systems](https://radsoftwaresystems.com)  
<strong>Credentials:</strong> ACCA/CAT Qualified | ABE BIS Adv. Dip.


<h2> Overview </h2>
AccuLedger is a set of high-integrity PHP Traits designed for the <strong>VILT stack</strong> (Vue, Inertia, Laravel, Tailwind). It bridges the gap between software engineering and <strong>IFRS/GAAP compliance</strong> by enforcing financial parity ($Debits = Credits$) at the Eloquent model layer before any transaction is committed to the database.

This repository serves as a technical demonstration of the **Systems Accountant** methodology—an architectural approach where accounting rigor is baked into the source code.

<h2> Key Features </h2>
<ul>
     <li> <strong>Hard-Stop Parity Validation:</strong> Automatically prevents the saving of Journal Entries where total debits do not equal total credits. </li
   <li> <strong>Immutable Audit Logging:</strong> Built-in hooks for append-only audit trails, ensuring every mutation is logged with field-level "old/new" values.</li>
  <li><strong>Maker-Checker Hooks: </strong> Pre-configured logic to support multi-role authorization (Accountant submission vs. Approver posting).</li>
  <li> <strong>VILT-Ready:</strong> Designed to pass real-time validation errors back to Vue 3/Inertia.js frontends seamlessly. </li>
  
</ul>


<h2>Why This Exists</h2>  
In complex fintech systems, operational efficiency is not enough; **structural and financial correctness** is mandatory. These traits ensure that a developer—regardless of their accounting background—cannot inadvertently "break the books" through a code-level oversight.

<h2>Installation</h2>
Currently available as a trait-based implementation for existing Laravel models.

1. Copy the `EnforcesDoubleEntry` and `GeneratesAuditTrail` traits into your `app/Traits` directory.
2. Use the traits in your `JournalEntry` and `JournalEntryLine` models.

```php
use App\Traits\EnforcesDoubleEntry;

class JournalEntry extends Model {
    use EnforcesDoubleEntry;
    // The model will now throw a ValidationException if Debits != Credits on save.
}
```


