<?php

namespace Alimrun\Ledger\Posting;

/**
 * A posting rule: turns one business event into one journal.
 *
 *     class OrderDeliveredPosting extends Posting
 *     {
 *         public function __construct(private Order $order) {}
 *
 *         public function event(): string { return 'order.delivered'; }
 *
 *         public function idempotencyKey(): string { return "order:{$this->order->id}:delivered"; }
 *
 *         public function build(JournalBuilder $journal): void
 *         {
 *             $journal->source($this->order)
 *                 ->debit('2050', $this->order->total, party: $this->order->customer)
 *                 ->credit('2100', ..., party: $this->order->seller);
 *         }
 *     }
 *
 *     Ledger::post(new OrderDeliveredPosting($order));
 */
abstract class Posting
{
    abstract public function event(): string;

    abstract public function idempotencyKey(): string;

    abstract public function build(JournalBuilder $journal): void;
}
