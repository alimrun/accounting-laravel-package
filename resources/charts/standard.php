<?php

/*
 * General-purpose chart of accounts for a trading or service business.
 *
 * Keys: code, name, type (asset|liability|equity|revenue|expense),
 * parent (code), party (null | '*' | ['customer', ...]), group (bool),
 * role (rounding|opening_balance_equity|retained_earnings),
 * normal_side (debit|credit, defaults from type), description.
 */
return [
    // Assets
    ['code' => '1000', 'name' => 'Cash on hand', 'type' => 'asset'],
    ['code' => '1010', 'name' => 'Petty cash', 'type' => 'asset'],
    ['code' => '1050', 'name' => 'Payment gateway clearing', 'type' => 'asset'],
    ['code' => '1100', 'name' => 'Bank accounts', 'type' => 'asset', 'group' => true],
    ['code' => '1101', 'name' => 'Main bank account', 'type' => 'asset', 'parent' => '1100'],
    ['code' => '1200', 'name' => 'Accounts receivable', 'type' => 'asset', 'party' => '*'],
    ['code' => '1300', 'name' => 'Inventory', 'type' => 'asset'],
    ['code' => '1400', 'name' => 'Prepaid expenses', 'type' => 'asset'],
    ['code' => '1410', 'name' => 'Employee advances', 'type' => 'asset', 'party' => '*'],
    ['code' => '1500', 'name' => 'Input tax receivable', 'type' => 'asset'],
    ['code' => '1600', 'name' => 'Fixed assets', 'type' => 'asset'],
    ['code' => '1610', 'name' => 'Accumulated depreciation', 'type' => 'asset', 'normal_side' => 'credit'],
    ['code' => '1900', 'name' => 'Suspense', 'type' => 'asset'],

    // Liabilities
    ['code' => '2000', 'name' => 'Accounts payable', 'type' => 'liability', 'party' => '*'],
    ['code' => '2100', 'name' => 'Customer deposits and wallet balances', 'type' => 'liability', 'party' => '*'],
    ['code' => '2200', 'name' => 'Tax payable', 'type' => 'liability'],
    ['code' => '2300', 'name' => 'Accrued expenses', 'type' => 'liability'],
    ['code' => '2400', 'name' => 'Salaries payable', 'type' => 'liability', 'party' => '*'],
    ['code' => '2500', 'name' => 'Loans payable', 'type' => 'liability'],
    ['code' => '2600', 'name' => 'Refunds payable', 'type' => 'liability', 'party' => '*'],
    ['code' => '2700', 'name' => 'Deferred revenue', 'type' => 'liability'],

    // Equity
    ['code' => '3000', 'name' => 'Opening balance equity', 'type' => 'equity', 'role' => 'opening_balance_equity'],
    ['code' => '3100', 'name' => 'Retained earnings', 'type' => 'equity', 'role' => 'retained_earnings'],
    ['code' => '3200', 'name' => "Owner's capital", 'type' => 'equity'],
    ['code' => '3300', 'name' => "Owner's drawings", 'type' => 'equity', 'normal_side' => 'debit'],

    // Revenue
    ['code' => '4000', 'name' => 'Sales revenue', 'type' => 'revenue'],
    ['code' => '4010', 'name' => 'Service revenue', 'type' => 'revenue'],
    ['code' => '4020', 'name' => 'Shipping revenue', 'type' => 'revenue'],
    ['code' => '4030', 'name' => 'Subscription revenue', 'type' => 'revenue'],
    ['code' => '4800', 'name' => 'Sales discounts', 'type' => 'revenue', 'normal_side' => 'debit'],
    ['code' => '4810', 'name' => 'Sales returns', 'type' => 'revenue', 'normal_side' => 'debit'],
    ['code' => '4900', 'name' => 'Other income', 'type' => 'revenue'],

    // Expenses
    ['code' => '5000', 'name' => 'Cost of goods sold', 'type' => 'expense'],
    ['code' => '5100', 'name' => 'Salaries and wages', 'type' => 'expense'],
    ['code' => '5110', 'name' => 'Rent', 'type' => 'expense'],
    ['code' => '5120', 'name' => 'Utilities', 'type' => 'expense'],
    ['code' => '5130', 'name' => 'Hosting and software', 'type' => 'expense'],
    ['code' => '5140', 'name' => 'Customer support', 'type' => 'expense'],
    ['code' => '5150', 'name' => 'Legal and compliance', 'type' => 'expense'],
    ['code' => '5200', 'name' => 'Marketing and promotions', 'type' => 'expense'],
    ['code' => '5210', 'name' => 'Discounts funded by the business', 'type' => 'expense'],
    ['code' => '5300', 'name' => 'Delivery costs', 'type' => 'expense'],
    ['code' => '5400', 'name' => 'Payment gateway fees', 'type' => 'expense'],
    ['code' => '5410', 'name' => 'Bank charges', 'type' => 'expense'],
    ['code' => '5500', 'name' => 'Depreciation', 'type' => 'expense'],
    ['code' => '5900', 'name' => 'Miscellaneous expenses', 'type' => 'expense'],
    ['code' => '5910', 'name' => 'Rounding differences', 'type' => 'expense', 'role' => 'rounding'],
];
