<?php

namespace justinholtweb\pointz\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\pointz\errors\PointzException;
use justinholtweb\pointz\models\Award;
use justinholtweb\pointz\Plugin;

/**
 * Awarding orders that completed before Pointz arrived — or before somebody switched a rule on.
 *
 * The dry run and the real run walk the same orders through the same evaluation, so the plan is
 * the outcome rather than an estimate of it. Every real run carries a batch ID, and `revert()`
 * takes back whatever is left of what the batch gave.
 */
class Backfill extends Component
{
    /**
     * Works out what a set of orders would earn, without writing anything.
     *
     * @param array{from?: DateTime|null, to?: DateTime|null, storeId?: int|null, limit?: int} $criteria
     * @return array{orders: int, skipped: int, points: float, credit: float, rows: array<int, array{orderId: int, reference: string, points: float, credit: float}>}
     */
    public function plan(array $criteria = []): array
    {
        $summary = ['orders' => 0, 'skipped' => 0, 'points' => 0.0, 'credit' => 0.0, 'rows' => []];
        $earning = Plugin::getInstance()->getEarning();

        foreach ($this->_orders($criteria) as $order) {
            if ($earning->hasEarned($order)) {
                $summary['skipped']++;
                continue;
            }

            $award = $earning->evaluateOrder($order, true);

            if ($award->getIsEmpty()) {
                $summary['skipped']++;
                continue;
            }

            $summary['orders']++;
            $summary['points'] += $award->getPoints();
            $summary['credit'] += $award->getCredit();
            $summary['rows'][] = [
                'orderId' => $order->id,
                'reference' => $order->reference ?: $order->getShortNumber(),
                'points' => $award->getPoints(),
                'credit' => $award->getCredit(),
            ];
        }

        return $summary;
    }

    /**
     * Awards the orders the plan found.
     *
     * @param array{from?: DateTime|null, to?: DateTime|null, storeId?: int|null, limit?: int} $criteria
     * @return array{batchId: string, orders: int, skipped: int, failed: int, points: float, credit: float}
     */
    public function run(array $criteria = []): array
    {
        if (!Plugin::getInstance()->isPro()) {
            throw new PointzException('Backfill needs Pointz Pro.');
        }

        $batchId = StringHelper::UUID();
        $earning = Plugin::getInstance()->getEarning();
        $result = ['batchId' => $batchId, 'orders' => 0, 'skipped' => 0, 'failed' => 0, 'points' => 0.0, 'credit' => 0.0];

        foreach ($this->_orders($criteria) as $order) {
            if ($earning->hasEarned($order)) {
                $result['skipped']++;
                continue;
            }

            try {
                $written = $earning->awardOrder($order, $batchId);
            } catch (\Throwable $e) {
                $result['failed']++;
                Craft::error('Pointz backfill failed on order ' . $order->id . ': ' . $e->getMessage(), 'pointz');
                continue;
            }

            if (!$written) {
                $result['skipped']++;
                continue;
            }

            $result['orders']++;

            foreach ($written as $transaction) {
                if ($transaction->isCredit()) {
                    $result['credit'] += $transaction->amount;
                } else {
                    $result['points'] += $transaction->amount;
                }
            }
        }

        return $result;
    }

    /**
     * Takes back whatever is left of a batch.
     *
     * Value a customer has already spent cannot come back — that is what the shortfall count
     * reports, rather than pushing a balance negative to make the arithmetic tidy.
     *
     * @return array{reversed: int, short: int}
     */
    public function revert(string $batchId): array
    {
        return Plugin::getInstance()->getGrants()->reverseBatch($batchId);
    }

    /**
     * @return iterable<Order>
     */
    private function _orders(array $criteria): iterable
    {
        $query = Order::find()
            ->isCompleted(true)
            ->status(null)
            ->orderBy(['commerce_orders.dateOrdered' => SORT_ASC]);

        if (!empty($criteria['storeId'])) {
            $query->storeId($criteria['storeId']);
        }

        // Dates go in as full ISO strings: a bare `Y-m-d H:i:s` is read as *system* time and
        // converted to UTC a second time, so the window silently matches nothing.
        if (!empty($criteria['from'])) {
            $query->dateOrdered('>= ' . $criteria['from']->format(DATE_ATOM));
        }

        if (!empty($criteria['to'])) {
            $query->dateOrdered('<= ' . $criteria['to']->format(DATE_ATOM));
        }

        if (!empty($criteria['limit'])) {
            $query->limit($criteria['limit']);
        }

        return $query->each(100);
    }
}
