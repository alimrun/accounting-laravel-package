<?php

use Alimrun\Ledger\Http\Controllers\AccountController;
use Alimrun\Ledger\Http\Controllers\JournalController;
use Alimrun\Ledger\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
Route::get('accounts/{code}', [AccountController::class, 'show'])->name('accounts.show');
Route::patch('accounts/{code}', [AccountController::class, 'update'])->name('accounts.update');
Route::post('accounts/{code}/deactivate', [AccountController::class, 'deactivate'])->name('accounts.deactivate');
Route::post('accounts/{code}/activate', [AccountController::class, 'activate'])->name('accounts.activate');

Route::get('journals', [JournalController::class, 'index'])->name('journals.index');
Route::post('journals', [JournalController::class, 'store'])->name('journals.store');
Route::get('journals/{journal}', [JournalController::class, 'show'])->whereNumber('journal')->name('journals.show');
Route::post('journals/{journal}/reverse', [JournalController::class, 'reverse'])->whereNumber('journal')->name('journals.reverse');

Route::get('reports/trial-balance', [ReportController::class, 'trialBalance'])->name('reports.trial-balance');
Route::get('reports/income-statement', [ReportController::class, 'incomeStatement'])->name('reports.income-statement');
Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet'])->name('reports.balance-sheet');
Route::get('reports/statement', [ReportController::class, 'statement'])->name('reports.statement');
Route::get('reports/party-balances/{code}', [ReportController::class, 'partyBalances'])->name('reports.party-balances');

Route::get('periods', [ReportController::class, 'periods'])->name('periods.index');
Route::post('periods/{period}/close', [ReportController::class, 'closePeriod'])->name('periods.close');
Route::post('periods/{period}/reopen', [ReportController::class, 'reopenPeriod'])->name('periods.reopen');

Route::get('reconciliation-issues', [ReportController::class, 'reconciliationIssues'])->name('reconciliation-issues.index');
