<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('sales')->orderBy('id')->chunkById(200, function ($sales): void {
            foreach ($sales as $sale) {
                DB::table('sale_payments')
                    ->where('company_id', $sale->company_id)
                    ->where('sale_id', $sale->id)
                    ->whereNull('occurred_at')
                    ->update([
                        'occurred_at' => $sale->occurred_at,
                        'received_by_membership_id' => $sale->confirmed_by_membership_id,
                    ]);

                $paidValue = DB::table('sale_payments')
                    ->where('company_id', $sale->company_id)
                    ->where('sale_id', $sale->id)
                    ->sum('amount_base');

                if (! is_numeric($sale->total_base)) {
                    throw new RuntimeException('Historical sale payment totals must be numeric.');
                }

                $paid = bcadd((string) $paidValue, '0', 4);
                $total = bcadd((string) $sale->total_base, '0', 4);
                $balance = bccomp($paid, $total, 4) >= 0 ? '0.0000' : bcsub($total, $paid, 4);
                $paymentStatus = bccomp($paid, '0', 4) === 0
                    ? 'pending'
                    : (bccomp($balance, '0', 4) === 0 ? 'paid' : 'partial');

                DB::table('sales')->where('id', $sale->id)->update([
                    'paid_total_base' => $paid,
                    'balance_due_base' => $balance,
                    'payment_status' => $paymentStatus,
                    'order_status' => $sale->status === 'voided' ? 'cancelled' : 'delivered',
                ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The populated columns are removed by their schema migrations during rollback.
    }
};
