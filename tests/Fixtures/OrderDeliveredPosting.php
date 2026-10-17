<?php

namespace Alimrun\Ledger\Tests\Fixtures;

use Alimrun\Ledger\Posting\JournalBuilder;
use Alimrun\Ledger\Posting\Posting;
use Alimrun\Ledger\Support\Amount;

/**
 * The delivered prepaid order from the 6Valley plan: items 200.00, seller
 * discount 10.00, coupon 20.00 (platform-funded), tax 17.00 (vendor
 * remits), shipping 15.00 (seller-wise), commission 10% of 170.00.
 */
class OrderDeliveredPosting extends Posting
{
    public function __construct(public int $orderId = 100245) {}

    public function event(): string
    {
        return 'order.delivered';
    }

    public function idempotencyKey(): string
    {
        return "order:{$this->orderId}:delivered";
    }

    public function build(JournalBuilder $journal): void
    {
        $goods = '170.00';
        $coupon = '20.00';
        $commission = '17.00';
        $tax = '17.00';
        $shipping = '15.00';

        // goods + coupon - commission + tax + shipping = 205.00
        $vendorShare = Amount::of($goods)->plus($coupon)->minus($commission)->plus($tax)->plus($shipping);

        $journal
            ->source(['order', $this->orderId])
            ->segment('retail')
            ->occurredAt('2026-10-05 14:00:00')
            ->debit('2050', '202.00', party: ['customer', 812], tag: 'prepayment')
            ->debit('5000', $coupon, tag: 'coupon')
            ->debit('5010', 0, tag: 'free_delivery')
            ->credit('2100', $vendorShare, party: ['seller', 44], tag: 'goods')
            ->credit('4000', $commission, tag: 'commission');
    }
}
