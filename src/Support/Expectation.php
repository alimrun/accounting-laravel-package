<?php

namespace Alimrun\Ledger\Support;

use Alimrun\Ledger\Enums\NormalSide;
use Brick\Math\BigNumber;
use Illuminate\Database\Eloquent\Model;

/**
 * "This party should have this balance on these accounts."
 *
 *     new Expectation('2000', $user, $user->wallet_balance, subject: 'users.wallet_balance')
 *
 *     // vendor withdrawable = credit on 2100 minus debit on 1100
 *     new Expectation(['2100', '1100'], $seller, $wallet->withdrawable, side: NormalSide::Credit)
 */
final class Expectation
{
    public function __construct(
        public readonly string|array $accounts,
        public readonly Model|Ref|array|null $party,
        public readonly BigNumber|int|float|string|null $expected,
        public readonly ?string $subject = null,
        public readonly ?NormalSide $side = null,
        public readonly array $meta = [],
    ) {}
}
