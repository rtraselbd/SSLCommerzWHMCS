<?php

namespace WHMCS\Module\Gateway\Sslcommerz;

use Exception;
use WHMCS\Database\Capsule;

/**
 * Local ledger of SSLCommerz payment attempts.
 *
 * SSLCommerz issues two identifiers for every payment: the merchant generated
 * "tran_id" and the bank issued "bank_tran_id". WHMCS can only record one of
 * them, while the refund API accepts nothing but the "bank_tran_id". This
 * ledger keeps both (plus the validation id) so either one can be resolved
 * from the other at any time.
 */
class Storage
{
    const TABLE = 'mod_sslcommerz_transactions';

    /**
     * Query builder for the ledger table.
     */
    public static function query()
    {
        static::ensureTable();

        return Capsule::table(static::TABLE);
    }

    /**
     * Record a payment attempt before the customer leaves for the gateway.
     */
    public static function begin(array $attributes)
    {
        $now = date('Y-m-d H:i:s');

        try {
            static::query()->insert($attributes + [
                'status'     => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (Exception $e) {
            // The ledger is a convenience, never block a payment over it.
        }
    }

    /**
     * Store what the gateway reported back for a merchant transaction id.
     */
    public static function save($tranId, array $attributes)
    {
        $tranId = (string) $tranId;
        $now    = date('Y-m-d H:i:s');

        if ($tranId === '') {
            return;
        }

        try {
            $existing = static::query()->where('tran_id', $tranId)->first();

            if ($existing) {
                static::query()
                    ->where('tran_id', $tranId)
                    ->update($attributes + ['updated_at' => $now]);

                return;
            }

            // Payments started before this ledger existed have no row yet.
            static::query()->insert($attributes + [
                'tran_id'    => $tranId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (Exception $e) {
            // See begin().
        }
    }

    /**
     * Find a payment by either of the two identifiers SSLCommerz issues.
     */
    public static function find($transId)
    {
        $transId = (string) $transId;

        if ($transId === '') {
            return null;
        }

        try {
            return static::query()
                ->where('tran_id', '=', $transId)
                ->orWhere('bank_tran_id', '=', $transId)
                ->first();
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Create the ledger table on first use.
     */
    protected static function ensureTable()
    {
        static $checked = false;

        if ($checked) {
            return;
        }

        $checked = true;

        if (Capsule::schema()->hasTable(static::TABLE)) {
            return;
        }

        try {
            Capsule::schema()->create(static::TABLE, function ($table) {
                $table->increments('id');
                $table->integer('invoice_id')->nullable()->index();
                $table->string('tran_id', 60)->unique();
                $table->string('val_id', 100)->nullable();
                $table->string('bank_tran_id', 100)->nullable()->index();
                $table->string('card_type', 100)->nullable();
                $table->decimal('amount', 16, 2)->nullable();
                $table->string('currency', 8)->nullable();
                $table->string('status', 32)->nullable();
                $table->string('refund_ref_id', 100)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        } catch (Exception $e) {
            // A concurrent request may have created it first.
        }
    }
}
