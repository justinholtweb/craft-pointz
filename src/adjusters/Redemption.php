<?php

namespace justinholtweb\pointz\adjusters;

use Craft;
use craft\base\Component;
use craft\commerce\base\AdjusterInterface;
use craft\commerce\elements\Order;
use craft\commerce\models\OrderAdjustment;
use justinholtweb\pointz\Plugin;

/**
 * Turns a cart's redemption intent into adjustments.
 *
 * Registered last, so by the time it runs the shipping, discount and tax adjusters have already
 * put their numbers on the order and the cap can be measured against a real total. It writes the
 * points count into `sourceSnapshot` because the adjustment carries money and the ledger moves
 * points — and the two must not be re-derived from each other at completion, when the rate may
 * have changed since the cart was quoted.
 *
 * Nothing here spends anything. A cart can be recalculated a thousand times.
 */
class Redemption extends Component implements AdjusterInterface
{
    public const TYPE_POINTS = 'pointz-points';
    public const TYPE_CREDIT = 'pointz-credit';

    /**
     * @return string[]
     */
    public static function adjustmentTypes(): array
    {
        return [self::TYPE_POINTS, self::TYPE_CREDIT];
    }

    /**
     * @inheritdoc
     */
    public function adjust(Order $order): array
    {
        if (!$order->id || $order->isCompleted) {
            return [];
        }

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->redemptionEnabled && !$settings->creditRedemptionEnabled) {
            return [];
        }

        $intent = $plugin->getRedemption()->getIntent($order->id);

        if ($intent['points'] <= 0 && $intent['credit'] <= 0) {
            return [];
        }

        $quote = $plugin->getRedemption()->quote($order);
        $adjustments = [];

        if ($quote->points > 0 && $quote->pointsValue > 0) {
            $adjustment = new OrderAdjustment();
            $adjustment->type = self::TYPE_POINTS;
            $adjustment->name = Craft::t('pointz', '{label} redeemed', [
                'label' => ucfirst($settings->pointsLabelPlural),
            ]);
            $adjustment->description = Craft::t('pointz', '{points} {label}', [
                'points' => $plugin->getRedemption()->format($quote->points),
                'label' => $settings->label($quote->points),
            ]);
            $adjustment->amount = -$quote->pointsValue;
            $adjustment->setOrder($order);
            $adjustment->sourceSnapshot = [
                'points' => $quote->points,
                'pointsPerUnit' => $settings->pointsPerUnit,
                'requested' => $quote->requestedPoints,
                'base' => $quote->base,
                'cap' => $quote->cap,
            ];

            $adjustments[] = $adjustment;
        }

        if ($quote->credit > 0) {
            $adjustment = new OrderAdjustment();
            $adjustment->type = self::TYPE_CREDIT;
            $adjustment->name = Craft::t('pointz', '{label} applied', [
                'label' => ucfirst($settings->creditLabel),
            ]);
            $adjustment->description = $plugin->getRedemption()->formatMoney($quote->credit, $order->getStore()->id);
            $adjustment->amount = -$quote->credit;
            $adjustment->setOrder($order);
            $adjustment->sourceSnapshot = [
                'credit' => $quote->credit,
                'requested' => $quote->requestedCredit,
            ];

            $adjustments[] = $adjustment;
        }

        return $adjustments;
    }
}
