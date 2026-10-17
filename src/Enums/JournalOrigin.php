<?php

namespace Alimrun\Ledger\Enums;

enum JournalOrigin: string
{
    /** Posted by application code as the business event happened. */
    case Live = 'live';

    /** Entered by a person, for example an accountant's adjustment. */
    case Manual = 'manual';

    /** Cut-over balances posted against opening balance equity. */
    case Opening = 'opening';

    /** History replayed for reporting. */
    case Backfill = 'backfill';

    /** Period close into retained earnings; left out of the income statement. */
    case Closing = 'closing';
}
