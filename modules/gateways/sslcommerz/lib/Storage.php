<?php

namespace WHMCS\Module\Gateway\Sslcommerz;

use Throwable;
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
        } catch (Throwable $e) {
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
        } catch (Throwable $e) {
            // See begin().
        }
    }

    /**
     * Claim the right to record a payment against an invoice.
     *
     * The IPN notification and the customer's own return can arrive at the same
     * moment, so the claim is a single conditional statement, which the database
     * settles atomically. Only the caller it returns true for may post the
     * payment; the loser leaves it to the winner.
     */
    public static function claim($tranId)
    {
        $tranId = (string) $tranId;
        $now    = date('Y-m-d H:i:s');

        if ($tranId === '') {
            return true;
        }

        try {
            $claimed = static::query()
                ->where('tran_id', '=', $tranId)
                ->whereNull('recorded_at')
                ->update(['recorded_at' => $now, 'updated_at' => $now]);

            if ($claimed) {
                return true;
            }

            if (static::query()->where('tran_id', '=', $tranId)->exists()) {
                return false; // Already claimed by whoever got here first.
            }

            // Payments started before this ledger existed have no row to claim,
            // so the insert itself becomes the claim: the unique tran_id index
            // rejects everyone but the first.
            static::query()->insert([
                'tran_id'     => $tranId,
                'recorded_at' => $now,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

            return true;
        } catch (Throwable $e) {
            // A racing insert trips the unique index and lands here, so the
            // claim is re-read: another request holding it is the only reason to
            // stand down. A ledger that is merely unavailable must not swallow
            // payments, and the duplicate checks still guard the invoice.
            return ! static::isClaimed($tranId);
        }
    }

    /**
     * Whether a claim is already held. An unreadable ledger answers false, so
     * the caller records the payment rather than dropping it.
     */
    protected static function isClaimed($tranId)
    {
        try {
            return static::query()
                ->where('tran_id', '=', $tranId)
                ->whereNotNull('recorded_at')
                ->exists();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Give up a claim, so a later attempt can still record the payment.
     */
    public static function release($tranId)
    {
        $tranId = (string) $tranId;

        if ($tranId === '') {
            return;
        }

        try {
            static::query()
                ->where('tran_id', '=', $tranId)
                ->update(['recorded_at' => null, 'updated_at' => date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
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
        } catch (Throwable $e) {
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
            static::ensureColumns();

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
                $table->timestamp('recorded_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        } catch (Throwable $e) {
            // A concurrent request may have created it first.
        }
    }

    /**
     * Bring a table created by an earlier version of the module up to date.
     */
    protected static function ensureColumns()
    {
        try {
            if (Capsule::schema()->hasColumn(static::TABLE, 'recorded_at')) {
                return;
            }

            Capsule::schema()->table(static::TABLE, function ($table) {
                $table->timestamp('recorded_at')->nullable();
            });
        } catch (Throwable $e) {
            // See ensureTable().
        }
    }
}
