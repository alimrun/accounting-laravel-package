<?php

/*
 * Multi-vendor marketplace chart: the platform earns commission, in-house
 * sales and the shipping it delivers. Money collected for vendors and
 * delivery men is a liability, and platform-funded discounts are expenses.
 *
 * Register these morph aliases in your app: seller, delivery_man, customer.
 */
return [
    // Assets
    ['code' => '1000', 'name' => 'Cash on hand', 'type' => 'asset'],
    ['code' => '1010', 'name' => 'Payment gateway clearing', 'type' => 'asset'],
    ['code' => '1020', 'name' => 'Bank (payout account)', 'type' => 'asset'],
    ['code' => '1100', 'name' => 'Cash held by vendors', 'type' => 'asset', 'party' => ['seller']],
    ['code' => '1110', 'name' => 'Cash held by delivery men', 'type' => 'asset', 'party' => ['delivery_man']],
    ['code' => '1120', 'name' => 'COD held by couriers', 'type' => 'asset'],
    ['code' => '1900', 'name' => 'Payment suspense', 'type' => 'asset', 'party' => ['customer']],

    // Liabilities
    ['code' => '2000', 'name' => 'Customer wallet balances', 'type' => 'liability', 'party' => ['customer']],
    ['code' => '2050', 'name' => 'Customer order prepayments', 'type' => 'liability', 'party' => ['customer']],
    ['code' => '2100', 'name' => 'Payable to vendors', 'type' => 'liability', 'party' => ['seller']],
    ['code' => '2101', 'name' => 'Vendor payouts in process', 'type' => 'liability', 'party' => ['seller']],
    ['code' => '2110', 'name' => 'Payable to delivery men', 'type' => 'liability', 'party' => ['delivery_man']],
    ['code' => '2111', 'name' => 'Delivery-man payouts in process', 'type' => 'liability', 'party' => ['delivery_man']],
    ['code' => '2120', 'name' => 'Payable to auction owners', 'type' => 'liability', 'party' => ['seller', 'customer']],
    ['code' => '2200', 'name' => 'Tax payable', 'type' => 'liability'],
    ['code' => '2500', 'name' => 'Loyalty points liability', 'type' => 'liability', 'party' => ['customer']],
    ['code' => '2600', 'name' => 'Refunds payable', 'type' => 'liability', 'party' => ['customer']],

    // Equity
    ['code' => '3000', 'name' => 'Opening balance equity', 'type' => 'equity', 'role' => 'opening_balance_equity'],
    ['code' => '3100', 'name' => 'Retained earnings', 'type' => 'equity', 'role' => 'retained_earnings'],

    // Revenue
    ['code' => '4000', 'name' => 'Commission revenue', 'type' => 'revenue'],
    ['code' => '4010', 'name' => 'In-house sales', 'type' => 'revenue'],
    ['code' => '4020', 'name' => 'Shipping revenue', 'type' => 'revenue'],
    ['code' => '4030', 'name' => 'Subscription revenue', 'type' => 'revenue'],
    ['code' => '4100', 'name' => 'Auction commission revenue', 'type' => 'revenue'],
    ['code' => '4110', 'name' => 'Auction entry fee revenue', 'type' => 'revenue'],

    // Expenses
    ['code' => '5000', 'name' => 'Platform-funded coupon discounts', 'type' => 'expense'],
    ['code' => '5010', 'name' => 'Free delivery subsidy', 'type' => 'expense'],
    ['code' => '5020', 'name' => 'New-customer referral discount', 'type' => 'expense'],
    ['code' => '5100', 'name' => 'Wallet top-up bonus', 'type' => 'expense'],
    ['code' => '5110', 'name' => 'Referral earnings', 'type' => 'expense'],
    ['code' => '5120', 'name' => 'Loyalty point conversions', 'type' => 'expense'],
    ['code' => '5200', 'name' => 'Delivery-man charges', 'type' => 'expense'],
    ['code' => '5210', 'name' => 'Courier fees', 'type' => 'expense'],
    ['code' => '5300', 'name' => 'Payment gateway fees', 'type' => 'expense'],
    ['code' => '5400', 'name' => 'Operating expenses', 'type' => 'expense', 'group' => true],
    ['code' => '5401', 'name' => 'Salaries', 'type' => 'expense', 'parent' => '5400'],
    ['code' => '5402', 'name' => 'Hosting and infrastructure', 'type' => 'expense', 'parent' => '5400'],
    ['code' => '5403', 'name' => 'Customer support', 'type' => 'expense', 'parent' => '5400'],
    ['code' => '5404', 'name' => 'Marketing outside discounts', 'type' => 'expense', 'parent' => '5400'],
    ['code' => '5405', 'name' => 'Rent and administration', 'type' => 'expense', 'parent' => '5400'],
    ['code' => '5406', 'name' => 'Legal and compliance', 'type' => 'expense', 'parent' => '5400'],
    ['code' => '5900', 'name' => 'Wallet adjustments and goodwill', 'type' => 'expense'],
    ['code' => '5910', 'name' => 'Rounding differences', 'type' => 'expense', 'role' => 'rounding'],
];
