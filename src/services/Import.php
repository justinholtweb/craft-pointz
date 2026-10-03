<?php

namespace justinholtweb\pointz\services;

use Craft;
use craft\base\Component;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\models\Discount;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateInterval;
use DateTime;
use DateTimeZone;
use justinholtweb\pointz\db\Table;
use justinholtweb\pointz\errors\PointzException;
use justinholtweb\pointz\models\Coupon;
use justinholtweb\pointz\models\Rule;
use justinholtweb\pointz\models\Transaction;
use justinholtweb\pointz\Plugin;
use yii\db\Expression;

/**
 * Bringing a programme over from another system: opening balances, and coupons the old system
 * already handed out.
 *
 * **Balances** arrive as lots, not as a history. Each row is one credit through the ledger with
 * its own expiry date, so imported value expires, is spent soonest-first and reverses exactly like
 * value Pointz paid itself. Replaying years of another system's movements would only reproduce its
 * balance if both systems agreed on every rule along the way, and the number a customer is carrying
 * today is the one that has to come out right.
 *
 * **Coupons** are taken over, not reissued. The code stays in Commerce, on the discount it is
 * already on, and the customer keeps the code they were sent. Pointz records who owns it and when
 * it expires, which puts it under the same ownership check, reminders, expiry sweep and
 * clean-up as a code Pointz issued — including deleting the per-customer discount once its last
 * code is used or dead.
 *
 * Both are one batch, reversible with `revert()`, and safe to run twice: a row already imported is
 * skipped.
 */
class Import extends Component
{
    public const REFERENCE_PREFIX = 'import:';

    /**
     * Credits each row as one opening lot.
     *
     * A row is `user` (ID or email), `amount`, and optionally `currency`, `expires`, `note` and
     * `reference`. The reference is what makes a re-run safe: give one (the old system's ID for the
     * balance) if the file might be corrected and run again. Without one, a row is known by the
     * file it came from and its line.
     *
     * @param array<int, array<string, string|null>> $rows Keyed by line number, columns in lower case.
     * @param array{storeId?: int, currency?: string, note?: string|null, source?: string|null, dryRun?: bool, authorId?: int|null} $options
     * @return array{batchId: string, imported: int, totals: array<string, float>, skipped: array<int, string>, failed: array<int, string>}
     */
    public function importBalances(array $rows, array $options = []): array
    {
        $plugin = Plugin::getInstance();
        $storeId = $options['storeId'] ?? $this->_primaryStoreId();
        $dryRun = (bool)($options['dryRun'] ?? false);
        $source = $options['source'] ?? null;
        $batchId = StringHelper::UUID();
        $now = new DateTime();
        $seen = [];

        $result = ['batchId' => $batchId, 'imported' => 0, 'totals' => [], 'skipped' => [], 'failed' => []];

        foreach ($rows as $line => $row) {
            $user = $plugin->getRewards()->resolveUser($this->_value($row, ['user', 'email', 'userid']));

            if ($user === null) {
                $result['failed'][$line] = Craft::t('pointz', 'No such customer.');
                continue;
            }

            $amount = $this->_value($row, ['amount', 'balance', 'points']);

            if ($amount === null || !is_numeric($amount) || (float)$amount <= 0) {
                $result['failed'][$line] = Craft::t('pointz', 'The amount must be a positive number.');
                continue;
            }

            $currency = strtolower($this->_value($row, ['currency']) ?? $options['currency'] ?? Rule::CURRENCY_POINTS);

            if (!in_array($currency, [Rule::CURRENCY_POINTS, Rule::CURRENCY_CREDIT], true)) {
                $result['failed'][$line] = Craft::t('pointz', 'The currency must be points or credit.');
                continue;
            }

            if ($currency === Rule::CURRENCY_CREDIT && !$plugin->isPro()) {
                $result['failed'][$line] = Craft::t('pointz', 'Store credit needs Pointz Pro.');
                continue;
            }

            try {
                $dateExpires = $this->_date($this->_value($row, ['expires', 'dateexpires', 'expiry']));
            } catch (PointzException $e) {
                $result['failed'][$line] = $e->getMessage();
                continue;
            }

            if ($dateExpires !== null && $dateExpires <= $now) {
                $result['skipped'][$line] = Craft::t('pointz', 'Already expired.');
                continue;
            }

            $ownReference = $this->_value($row, ['reference', 'ref']);
            $reference = match (true) {
                $ownReference !== null => self::REFERENCE_PREFIX . $ownReference,
                $source !== null => self::REFERENCE_PREFIX . $source . ':' . $line,
                default => null,
            };

            if ($reference !== null && (isset($seen[$reference]) || $this->isImported($reference, $storeId))) {
                $result['skipped'][$line] = Craft::t('pointz', 'Already imported.');
                continue;
            }

            if ($reference !== null) {
                $seen[$reference] = true;
            }

            if (!$dryRun) {
                try {
                    $plugin->getLedger()->credit($user->id, $storeId, $currency, (float)$amount, [
                        'kind' => Transaction::KIND_IMPORT,
                        'authorId' => $options['authorId'] ?? null,
                        'batchId' => $batchId,
                        'reference' => $reference,
                        'note' => $this->_value($row, ['note']) ?? $options['note'] ?? Craft::t('pointz', 'Opening balance'),
                        'dateExpires' => $dateExpires,
                    ]);
                } catch (\Throwable $e) {
                    $result['failed'][$line] = $e->getMessage();
                    continue;
                }
            }

            $result['imported']++;
            $result['totals'][$currency] = ($result['totals'][$currency] ?? 0.0) + (float)$amount;
        }

        return $result;
    }

    /**
     * Takes over codes that already exist in Commerce.
     *
     * A row is `code` and `user` (ID or email), and optionally `expires`, `issued` and `remind`.
     * With no expiry the discount's own end date is used, which is where a per-customer discount
     * usually keeps it. With no reminder date, `remindDays` before the expiry, if given.
     *
     * A discount is all or nothing. Once Pointz manages a discount, only codes it knows the owner
     * of will work on it — so a discount with codes this file does not account for is refused
     * whole, rather than quietly switching off the codes nobody listed.
     *
     * @param array<int, array<string, string|null>> $rows Keyed by line number, columns in lower case.
     * @param array{remindDays?: int|null, dryRun?: bool} $options
     * @return array{batchId: string, imported: int, discounts: array<int, string>, madeSingleUse: int, skipped: array<int, string>, failed: array<int, string>}
     */
    public function importCoupons(array $rows, array $options = []): array
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->isPro()) {
            throw new PointzException('Coupons need Pointz Pro.');
        }

        if (!Plugin::commerceIsReady()) {
            throw new PointzException('Commerce is not ready.');
        }

        $dryRun = (bool)($options['dryRun'] ?? false);
        $remindDays = $options['remindDays'] ?? null;
        $batchId = StringHelper::UUID();
        $now = new DateTime();
        $result = ['batchId' => $batchId, 'imported' => 0, 'discounts' => [], 'madeSingleUse' => 0, 'skipped' => [], 'failed' => []];

        /** @var array<int, Discount|null> $discounts */
        $discounts = [];
        $managed = $plugin->getCoupons()->getManagedDiscountIds();
        $planned = [];

        foreach ($rows as $line => $row) {
            $code = $this->_value($row, ['code', 'coupon', 'couponcode']);

            if ($code === null) {
                $result['failed'][$line] = Craft::t('pointz', 'No code.');
                continue;
            }

            $commerceCoupon = (new Query())
                ->select(['id', 'discountId', 'code', 'uses', 'maxUses'])
                ->from(CommerceTable::COUPONS)
                ->where(new Expression('LOWER([[code]]) = :code', [':code' => strtolower($code)]))
                ->one();

            if ($commerceCoupon === null) {
                $result['failed'][$line] = Craft::t('pointz', 'Commerce has no code “{code}”.', ['code' => $code]);
                continue;
            }

            if ($plugin->getCoupons()->getCouponByCode($code) !== null || isset($planned[(int)$commerceCoupon['id']])) {
                $result['skipped'][$line] = Craft::t('pointz', 'Already managed by Pointz.');
                continue;
            }

            $maxUses = $commerceCoupon['maxUses'] !== null ? (int)$commerceCoupon['maxUses'] : 0;

            if ($maxUses > 1) {
                $result['failed'][$line] = Craft::t('pointz', 'The code can be used {count} times; Pointz coupons are single use.', ['count' => $maxUses]);
                continue;
            }

            if ((int)$commerceCoupon['uses'] > 0) {
                $result['skipped'][$line] = Craft::t('pointz', 'Already used.');
                continue;
            }

            $discountId = (int)$commerceCoupon['discountId'];

            if (!array_key_exists($discountId, $discounts)) {
                $discounts[$discountId] = Commerce::getInstance()->getDiscounts()->getDiscountById($discountId);
            }

            $discount = $discounts[$discountId];

            if ($discount === null) {
                $result['failed'][$line] = Craft::t('pointz', 'The code’s discount is missing.');
                continue;
            }

            if (in_array($discountId, $managed, true)) {
                $result['failed'][$line] = Craft::t('pointz', 'The code is on a discount Pointz already manages.');
                continue;
            }

            if (!$discount->requireCouponCode) {
                $result['failed'][$line] = Craft::t('pointz', 'Discount “{name}” applies without a code, so it cannot belong to one customer.', ['name' => $discount->name]);
                continue;
            }

            [$type, $amount] = $this->_valueOf($discount);

            if ($amount <= 0) {
                $result['failed'][$line] = Craft::t('pointz', 'Discount “{name}” takes neither a percentage nor an amount off.', ['name' => $discount->name]);
                continue;
            }

            $user = $plugin->getRewards()->resolveUser($this->_value($row, ['user', 'email', 'userid']));

            if ($user === null) {
                $result['failed'][$line] = Craft::t('pointz', 'No such customer.');
                continue;
            }

            try {
                $dateExpires = $this->_date($this->_value($row, ['expires', 'dateexpires', 'expiry']))
                    ?? ($discount->dateTo ? clone $discount->dateTo : null);
                $dateIssued = $this->_date($this->_value($row, ['issued', 'dateissued', 'datecreated']));
                $dateRemind = $this->_date($this->_value($row, ['remind', 'dateremind']));
            } catch (PointzException $e) {
                $result['failed'][$line] = $e->getMessage();
                continue;
            }

            if ($dateExpires !== null && $dateExpires <= $now) {
                $result['skipped'][$line] = Craft::t('pointz', 'Already expired.');
                continue;
            }

            if ($dateRemind === null && $dateExpires !== null && $remindDays) {
                $dateRemind = (clone $dateExpires)->sub(new DateInterval("P{$remindDays}D"));
            }

            $planned[(int)$commerceCoupon['id']] = [
                'line' => $line,
                'couponId' => (int)$commerceCoupon['id'],
                'code' => strtoupper($commerceCoupon['code']),
                'maxUses' => $maxUses,
                'discount' => $discount,
                'type' => $type,
                'amount' => $amount,
                'userId' => $user->id,
                'dateExpires' => $dateExpires,
                'dateIssued' => $dateIssued,
                'dateRemind' => $dateRemind,
            ];
        }

        // All or nothing per discount: every code on it must be in this file, or already Pointz's.
        $byDiscount = [];

        foreach ($planned as $entry) {
            $byDiscount[$entry['discount']->id][] = $entry;
        }

        foreach ($byDiscount as $discountId => $entries) {
            $listed = array_map(static fn(array $entry) => $entry['couponId'], $entries);
            $others = (new Query())
                ->from(CommerceTable::COUPONS)
                ->where(['discountId' => $discountId])
                ->andWhere(['not', ['id' => $listed]])
                ->count();

            if ($others > 0) {
                foreach ($entries as $entry) {
                    $result['failed'][$entry['line']] = Craft::t('pointz', 'Discount “{name}” has {count} other code(s) not in this file. Taking it over would switch them off.', [
                        'name' => $entry['discount']->name,
                        'count' => $others,
                    ]);
                }

                unset($byDiscount[$discountId]);
            }
        }

        foreach ($byDiscount as $discountId => $entries) {
            foreach ($entries as $entry) {
                if (!$dryRun) {
                    try {
                        $result['madeSingleUse'] += $this->_adopt($entry, $batchId);
                    } catch (\Throwable $e) {
                        $result['failed'][$entry['line']] = $e->getMessage();
                        continue;
                    }
                } elseif ($entry['maxUses'] === 0) {
                    $result['madeSingleUse']++;
                }

                $result['imported']++;
                $result['discounts'][$discountId] = $entry['discount']->name;
            }
        }

        ksort($result['failed']);

        return $result;
    }

    /**
     * Undoes an import. Balances: whatever is left of each opening lot is taken back, as
     * `Grants::reverseBatch()` does. Coupons: codes nobody has used yet are handed back to
     * Commerce untouched, as if Pointz had never seen them. A code that has been used or has expired
     * since stays in Pointz's history, and a discount the sweep has already deleted is gone.
     *
     * @return array{reversed: int, short: int, released: int, kept: int}
     */
    public function revert(string $batchId, ?int $authorId = null): array
    {
        $reversal = Plugin::getInstance()->getGrants()->reverseBatch($batchId, $authorId);
        $reference = self::REFERENCE_PREFIX . $batchId;

        $released = Db::delete(Table::COUPONS, [
            'reference' => $reference,
            'ruleId' => null,
            'status' => Coupon::STATUS_ACTIVE,
        ]);

        Plugin::getInstance()->getCoupons()->clearMemo();

        $kept = (int)(new Query())
            ->from(Table::COUPONS)
            ->where(['reference' => $reference, 'ruleId' => null])
            ->count();

        return $reversal + ['released' => $released, 'kept' => $kept];
    }

    /**
     * Whether a row with this reference has been imported into this store and not reverted since.
     * A reverted batch has negative movements of its own, and its rows may be imported again.
     */
    public function isImported(string $reference, int $storeId): bool
    {
        return (new Query())
            ->from(['t' => Table::TRANSACTIONS])
            ->where([
                't.reference' => $reference,
                't.kind' => Transaction::KIND_IMPORT,
                't.storeId' => $storeId,
            ])
            ->andWhere(['not exists', (new Query())
                ->from(['r' => Table::TRANSACTIONS])
                ->where('[[r.batchId]] = [[t.batchId]]')
                ->andWhere(['<', 'r.amount', 0]),
            ])
            ->exists();
    }

    /**
     * Reads a CSV file into rows keyed by line number, with lower-case column names.
     *
     * @return array<int, array<string, string|null>>
     */
    public function readCsv(string $path): array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new PointzException("Could not open $path.");
        }

        try {
            $header = fgetcsv($handle, escape: '');

            if (!$header) {
                return [];
            }

            // A spreadsheet's byte-order mark would otherwise become part of the first column's name.
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
            $header = array_map(static fn($name) => strtolower(str_replace([' ', '_', '-'], '', trim((string)$name))), $header);

            $rows = [];
            $line = 1;

            while (($cells = fgetcsv($handle, escape: '')) !== false) {
                $line++;

                if ($cells === [null] || implode('', array_map('strval', $cells)) === '') {
                    continue;
                }

                $rows[$line] = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), null));
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Records one Commerce code as Pointz's.
     *
     * @return int 1 if the code had no use limit and now has one.
     */
    private function _adopt(array $entry, string $batchId): int
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));
        $madeSingleUse = 0;

        $dbTransaction = $db->beginTransaction();

        try {
            // A code with no limit would otherwise stay usable by two carts at once until the
            // first completes; a Pointz code is single use and Commerce should enforce that too.
            if ($entry['maxUses'] === 0) {
                Db::update(CommerceTable::COUPONS, ['maxUses' => 1], ['id' => $entry['couponId']]);
                $madeSingleUse = 1;
            }

            $db->createCommand()->insert(Table::COUPONS, [
                'storeId' => $entry['discount']->storeId,
                'userId' => $entry['userId'],
                'ruleId' => null,
                'discountId' => $entry['discount']->id,
                'couponId' => $entry['couponId'],
                'code' => $entry['code'],
                'type' => $entry['type'],
                'amount' => $entry['amount'],
                'status' => Coupon::STATUS_ACTIVE,
                'reference' => self::REFERENCE_PREFIX . $batchId,
                'dateExpires' => $entry['dateExpires'] ? Db::prepareDateForDb($entry['dateExpires']) : null,
                'dateRemind' => $entry['dateRemind'] ? Db::prepareDateForDb($entry['dateRemind']) : null,
                'dateCreated' => $entry['dateIssued'] ? Db::prepareDateForDb($entry['dateIssued']) : $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();

            $dbTransaction->commit();
        } catch (\Throwable $e) {
            $dbTransaction->rollBack();
            throw $e;
        }

        Plugin::getInstance()->getCoupons()->clearMemo();

        return $madeSingleUse;
    }

    /**
     * What a discount takes off, in Pointz's terms: a percentage, or an amount.
     *
     * @return array{0: string, 1: float}
     */
    private function _valueOf(Discount $discount): array
    {
        if ((float)$discount->percentDiscount != 0.0) {
            return [Rule::COUPON_PERCENT, round(abs((float)$discount->percentDiscount) * 100, 4)];
        }

        return [Rule::COUPON_FIXED, abs((float)$discount->baseDiscount) ?: abs((float)$discount->perItemDiscount)];
    }

    /**
     * A date in the system time zone. A date without a time is the start of that day.
     */
    private function _date(?string $value): ?DateTime
    {
        if ($value === null) {
            return null;
        }

        $date = DateTimeHelper::toDateTime($value, true);

        if ($date === false) {
            throw new PointzException(Craft::t('pointz', '“{value}” is not a date.', ['value' => $value]));
        }

        return $date;
    }

    /**
     * The first of several column names that has a value.
     *
     * @param array<string, string|null> $row
     * @param string[] $names
     */
    private function _value(array $row, array $names): ?string
    {
        foreach ($names as $name) {
            $value = isset($row[$name]) ? trim((string)$row[$name]) : '';

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function _primaryStoreId(): int
    {
        return Commerce::getInstance()->getStores()->getPrimaryStore()->id;
    }
}
