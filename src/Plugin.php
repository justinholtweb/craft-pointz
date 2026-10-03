<?php

namespace justinholtweb\pointz;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\commerce\events\MatchOrderEvent;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\commerce\services\Discounts;
use craft\commerce\services\OrderAdjustments;
use craft\commerce\services\OrderHistories;
use craft\commerce\services\Transactions as CommerceTransactions;
use craft\elements\User;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterEmailMessagesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\Dashboard;
use craft\services\SystemMessages;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\pointz\adjusters\Redemption as RedemptionAdjuster;
use justinholtweb\pointz\events\TransactionEvent;
use justinholtweb\pointz\models\Rule;
use justinholtweb\pointz\models\Settings;
use justinholtweb\pointz\models\Transaction;
use justinholtweb\pointz\services\Accounts;
use justinholtweb\pointz\services\Backfill;
use justinholtweb\pointz\services\Coupons;
use justinholtweb\pointz\services\Earning;
use justinholtweb\pointz\services\Grants;
use justinholtweb\pointz\services\Ledger;
use justinholtweb\pointz\services\Lifecycle;
use justinholtweb\pointz\services\Redemption;
use justinholtweb\pointz\services\Rewards;
use justinholtweb\pointz\services\Rules;
use justinholtweb\pointz\twig\PointzVariable;
use justinholtweb\pointz\widgets\LiabilityWidget;
use yii\base\Event;
use yii\db\AfterSaveEvent;
use yii\db\BaseActiveRecord;

/**
 * Pointz — loyalty points and store credit for Craft Commerce.
 *
 * @property-read Accounts $accounts
 * @property-read Ledger $ledger
 * @property-read Rules $rules
 * @property-read Earning $earning
 * @property-read Redemption $redemption
 * @property-read Lifecycle $lifecycle
 * @property-read Grants $grants
 * @property-read Backfill $backfill
 * @property-read Coupons $coupons
 * @property-read Rewards $rewards
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'pointz';

    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public string $schemaVersion = '5.1.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'accounts' => ['class' => Accounts::class],
                'ledger' => ['class' => Ledger::class],
                'rules' => ['class' => Rules::class],
                'earning' => ['class' => Earning::class],
                'redemption' => ['class' => Redemption::class],
                'lifecycle' => ['class' => Lifecycle::class],
                'grants' => ['class' => Grants::class],
                'backfill' => ['class' => Backfill::class],
                'coupons' => ['class' => Coupons::class],
                'rewards' => ['class' => Rewards::class],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->_registerTwigVariable();
        $this->_registerPermissions();
        $this->_registerRoutes();
        $this->_registerWidgets();
        $this->_registerSystemMessages();

        // Pointz can be installed while Commerce is disabled or mid-upgrade, and everything below
        // reaches for classes that would not be there.
        if (!self::commerceIsReady()) {
            return;
        }

        $this->_registerAdjuster();
        $this->_registerOrderEvents();
        $this->_registerRefundReversal();
        $this->_registerSignupBonus();
        $this->_registerRewardTriggers();
        $this->_registerOrderPanel();
    }

    /**
     * Whether Commerce is present and enabled.
     */
    public static function commerceIsReady(): bool
    {
        return class_exists(\craft\commerce\Plugin::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    /**
     * Whether this install is licensed for the Pro feature set: several rules, line-item rules and
     * conditions, campaign windows, caps, store credit, inactivity expiry, backfill and the
     * liability widget.
     */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    public function getAccounts(): Accounts
    {
        return $this->get('accounts');
    }

    public function getLedger(): Ledger
    {
        return $this->get('ledger');
    }

    public function getRules(): Rules
    {
        return $this->get('rules');
    }

    public function getEarning(): Earning
    {
        return $this->get('earning');
    }

    public function getRedemption(): Redemption
    {
        return $this->get('redemption');
    }

    public function getLifecycle(): Lifecycle
    {
        return $this->get('lifecycle');
    }

    public function getGrants(): Grants
    {
        return $this->get('grants');
    }

    public function getBackfill(): Backfill
    {
        return $this->get('backfill');
    }

    public function getCoupons(): Coupons
    {
        return $this->get('coupons');
    }

    public function getRewards(): Rewards
    {
        return $this->get('rewards');
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('pointz/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('pointz', 'Pointz');

        $user = Craft::$app->getUser();
        $subNav = [];

        if ($user->checkPermission('pointz-viewBalances')) {
            $subNav['balances'] = [
                'label' => Craft::t('pointz', 'Balances'),
                'url' => 'pointz/balances',
            ];
            $subNav['ledger'] = [
                'label' => Craft::t('pointz', 'Ledger'),
                'url' => 'pointz/ledger',
            ];
        }

        if ($user->checkPermission('pointz-manageRules')) {
            $subNav['rules'] = [
                'label' => Craft::t('pointz', 'Earning rules'),
                'url' => 'pointz/rules',
            ];
        }

        if ($user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $subNav['settings'] = [
                'label' => Craft::t('pointz', 'Settings'),
                'url' => 'settings/plugins/pointz',
            ];
        }

        if (!$subNav) {
            return null;
        }

        $item['subnav'] = $subNav;

        return $item;
    }

    private function _registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('pointz', PointzVariable::class);
            }
        );
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('pointz', 'Pointz'),
                    'permissions' => [
                        'pointz-viewBalances' => [
                            'label' => Craft::t('pointz', 'View balances and the ledger'),
                            'nested' => [
                                'pointz-adjustBalances' => [
                                    'label' => Craft::t('pointz', 'Grant and deduct value'),
                                ],
                            ],
                        ],
                        'pointz-manageRules' => [
                            'label' => Craft::t('pointz', 'Manage earning rules'),
                        ],
                    ],
                ];
            }
        );
    }

    private function _registerRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['pointz'] = 'pointz/balances/index';
                $event->rules['pointz/balances'] = 'pointz/balances/index';
                $event->rules['pointz/balances/<userId:\d+>'] = 'pointz/balances/detail';
                $event->rules['pointz/ledger'] = 'pointz/ledger/index';
                $event->rules['pointz/rules'] = 'pointz/rules/index';
                $event->rules['pointz/rules/<storeHandle:{handle}>'] = 'pointz/rules/index';
                $event->rules['pointz/rules/<storeHandle:{handle}>/new'] = 'pointz/rules/edit';
                $event->rules['pointz/rules/<storeHandle:{handle}>/<ruleId:\d+>'] = 'pointz/rules/edit';
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                // Nothing is routed by default: the account page belongs to the site's own
                // templates, and Pointz answers with actions rather than pages.
            }
        );
    }

    private function _registerWidgets(): void
    {
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            static function(RegisterComponentTypesEvent $event) {
                if (Plugin::getInstance()->isPro()) {
                    $event->types[] = LiabilityWidget::class;
                }
            }
        );
    }

    /**
     * Registered last, and deliberately so: the adjuster has to see the shipping, discount and tax
     * numbers before it can cap a redemption against a real order total.
     */
    private function _registerAdjuster(): void
    {
        Event::on(
            OrderAdjustments::class,
            OrderAdjustments::EVENT_REGISTER_ORDER_ADJUSTERS,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = RedemptionAdjuster::class;
            }
        );
    }

    /**
     * Completion is two separate moments, in this order:
     *
     * 1. `BEFORE_COMPLETE_ORDER` — spend what the cart's adjustments say the customer is spending.
     *    Before, so that a store which has chosen to fail on a shortfall can still stop the order.
     * 2. `AFTER_COMPLETE_ORDER` — award what the rules say the order earned. After, because the
     *    rules read the finished order, and because earning must never be able to fail a checkout.
     */
    private function _registerOrderEvents(): void
    {
        Event::on(
            Order::class,
            Order::EVENT_BEFORE_COMPLETE_ORDER,
            static function(Event $event) {
                /** @var Order $order */
                $order = $event->sender;

                Plugin::getInstance()->getRedemption()->commitOrder($order);
            }
        );

        Event::on(
            Order::class,
            Order::EVENT_AFTER_COMPLETE_ORDER,
            static function(Event $event) {
                /** @var Order $order */
                $order = $event->sender;
                $plugin = Plugin::getInstance();
                $settings = $plugin->getSettings();

                try {
                    if ($settings->awardOn === Settings::AWARD_ON_COMPLETE) {
                        $plugin->getEarning()->awardOrder($order);
                    }
                } catch (\Throwable $e) {
                    // A loyalty scheme is never worth a failed checkout.
                    Craft::error('Pointz could not award order ' . $order->id . ': ' . $e->getMessage(), 'pointz');
                }

                try {
                    $plugin->getCoupons()->markUsed($order);
                } catch (\Throwable $e) {
                    Craft::error('Pointz could not mark the coupon on order ' . $order->id . ' used: ' . $e->getMessage(), 'pointz');
                }
            }
        );

        Event::on(
            Order::class,
            Order::EVENT_AFTER_ORDER_PAID,
            static function(Event $event) {
                /** @var Order $order */
                $order = $event->sender;
                $plugin = Plugin::getInstance();

                try {
                    if ($plugin->getSettings()->awardOn === Settings::AWARD_ON_PAID) {
                        $plugin->getEarning()->awardOrder($order);
                    }

                    $plugin->getLifecycle()->promoteOrderLots($order);
                } catch (\Throwable $e) {
                    Craft::error('Pointz could not award order ' . $order->id . ': ' . $e->getMessage(), 'pointz');
                }
            }
        );

        Event::on(
            OrderHistories::class,
            OrderHistories::EVENT_ORDER_STATUS_CHANGE,
            static function($event) {
                $plugin = Plugin::getInstance();
                $settings = $plugin->getSettings();
                $order = $event->order ?? null;

                if (!$order instanceof Order || $settings->awardOn !== Settings::AWARD_ON_STATUS) {
                    return;
                }

                $status = $order->getOrderStatus();

                if ($status === null || $status->handle !== $settings->awardOnStatus) {
                    return;
                }

                try {
                    $plugin->getEarning()->awardOrder($order);
                    $plugin->getLifecycle()->promoteOrderLots($order);
                } catch (\Throwable $e) {
                    Craft::error('Pointz could not award order ' . $order->id . ': ' . $e->getMessage(), 'pointz');
                }
            }
        );
    }

    /**
     * A successful refund transaction is the signal. Commerce has no "order refunded" event, and
     * an order status called "Refunded" is a convention a shop may or may not follow — the money
     * moving is the only fact.
     */
    private function _registerRefundReversal(): void
    {
        Event::on(
            CommerceTransactions::class,
            CommerceTransactions::EVENT_AFTER_SAVE_TRANSACTION,
            static function($event) {
                $transaction = $event->transaction ?? null;

                if ($transaction === null
                    || $transaction->type !== TransactionRecord::TYPE_REFUND
                    || $transaction->status !== TransactionRecord::STATUS_SUCCESS) {
                    return;
                }

                $order = $transaction->getOrder();

                if (!$order instanceof Order) {
                    return;
                }

                try {
                    Plugin::getInstance()->getLifecycle()->reverseForRefund(
                        $order,
                        Plugin::totalRefunded($order)
                    );
                } catch (\Throwable $e) {
                    Craft::error('Pointz could not reverse order ' . $order->id . ': ' . $e->getMessage(), 'pointz');
                }
            }
        );
    }

    /**
     * Everything successfully refunded against an order so far — the cumulative figure, so a
     * second partial refund reverses the difference rather than starting again.
     */
    public static function totalRefunded(Order $order): float
    {
        $total = 0.0;

        foreach ($order->getTransactions() as $transaction) {
            if ($transaction->type === TransactionRecord::TYPE_REFUND
                && $transaction->status === TransactionRecord::STATUS_SUCCESS) {
                $total += (float)$transaction->amount;
            }
        }

        return round($total, 5);
    }

    private function _registerSignupBonus(): void
    {
        Event::on(
            User::class,
            User::EVENT_AFTER_PROPAGATE,
            static function(ModelEvent $event) {
                /** @var User $user */
                $user = $event->sender;

                if (!$event->isNew || $user->getIsDraft() || $user->getIsRevision()) {
                    return;
                }

                try {
                    Plugin::getInstance()->getEarning()->awardSignup($user);
                } catch (\Throwable $e) {
                    Craft::error('Pointz could not award the signup bonus: ' . $e->getMessage(), 'pointz');
                }
            }
        );
    }

    /**
     * The two coupon emails, editable under Utilities → System Messages like Craft's own.
     */
    private function _registerSystemMessages(): void
    {
        Event::on(
            SystemMessages::class,
            SystemMessages::EVENT_REGISTER_MESSAGES,
            static function(RegisterEmailMessagesEvent $event) {
                foreach (Coupons::systemMessages() as $message) {
                    $event->messages[] = $message;
                }
            }
        );
    }

    /**
     * The triggers that are not an order. Each handler is wrapped: a review approval, a form
     * submission or a newsletter signup must never fail because a reward could not be paid.
     *
     * Stars, Formie and Dispatch are hooked by class name, so none of them has to be installed —
     * an event on a class that never loads simply never fires.
     */
    private function _registerRewardTriggers(): void
    {
        // A balance that has just grown may have crossed a threshold. After the commit, because a
        // threshold spends — and the ledger cannot be re-entered from inside its own lock.
        Event::on(
            Ledger::class,
            Ledger::EVENT_AFTER_COMMIT,
            static function(TransactionEvent $event) {
                $transaction = $event->transaction;

                if ($transaction->currency !== Rule::CURRENCY_POINTS || $transaction->status !== Transaction::STATUS_POSTED) {
                    return;
                }

                try {
                    Plugin::getInstance()->getRewards()->evaluateThresholds($transaction->userId, $transaction->storeId);
                } catch (\Throwable $e) {
                    Craft::error('Pointz could not evaluate thresholds for user ' . $transaction->userId . ': ' . $e->getMessage(), 'pointz');
                }
            }
        );

        // A Pointz coupon code only discounts its owner's order, and only until it expires.
        Event::on(
            Discounts::class,
            Discounts::EVENT_DISCOUNT_MATCHES_ORDER,
            static function(MatchOrderEvent $event) {
                Plugin::getInstance()->getCoupons()->enforceOwnership($event);
            }
        );

        Event::on(
            'justinholtweb\stars\elements\Review',
            Element::EVENT_AFTER_SAVE,
            static function(ModelEvent $event) {
                if (!Craft::$app->getPlugins()->isPluginEnabled('stars')) {
                    return;
                }

                try {
                    Plugin::getInstance()->getRewards()->awardReview($event->sender);
                } catch (\Throwable $e) {
                    Craft::error('Pointz could not reward review ' . ($event->sender->id ?? '?') . ': ' . $e->getMessage(), 'pointz');
                }
            }
        );

        Event::on(
            'verbb\formie\services\Submissions',
            'afterSubmission',
            static function($event) {
                $submission = $event->submission ?? null;
                $form = $event->form ?? $submission?->getForm();

                if (!$submission || !$form || !($event->success ?? false) || $submission->isIncomplete || $submission->isSpam) {
                    return;
                }

                try {
                    $user = $submission->getUser();

                    if ($user === null) {
                        foreach ($submission->getFieldValuesForField('verbb\formie\fields\Email') as $email) {
                            if (is_string($email) && $email !== '') {
                                $user = Plugin::getInstance()->getRewards()->resolveUser($email);
                                break;
                            }
                        }
                    }

                    if ($user !== null) {
                        Plugin::getInstance()->getRewards()->awardEvent($user, 'formie:' . $form->handle, null, null, [
                            'submission' => $submission,
                        ]);
                    }
                } catch (\Throwable $e) {
                    Craft::error('Pointz could not reward Formie submission ' . $submission->id . ': ' . $e->getMessage(), 'pointz');
                }
            }
        );

        // Dispatch has no "subscribed" event, so the subscription record's insert is the signal.
        // Only a visitor subscribing counts: a CSV import or a control-panel edit going through
        // the same method must not pay a welcome reward to an entire list.
        Event::on(
            'justinholtweb\dispatch\records\SubscriptionRecord',
            BaseActiveRecord::EVENT_AFTER_INSERT,
            static function(AfterSaveEvent $event) {
                $request = Craft::$app->getRequest();

                if ($request->getIsConsoleRequest() || !$request->getIsSiteRequest()
                    || (($request->getActionSegments()[0] ?? null) === 'queue')) {
                    return;
                }

                $record = $event->sender;
                $subscriberClass = 'justinholtweb\\dispatch\\elements\\Subscriber';
                $listClass = 'justinholtweb\\dispatch\\elements\\MailingList';

                try {
                    $subscriber = $subscriberClass::find()->id($record->subscriberId)->status(null)->one();
                    $list = $listClass::find()->id($record->mailingListId)->status(null)->one();

                    if (!$subscriber || !$list) {
                        return;
                    }

                    $user = $subscriber->userId ?: $subscriber->email;

                    Plugin::getInstance()->getRewards()->awardEvent($user, 'dispatch:' . $list->handle, null, null, [
                        'subscriber' => $subscriber,
                        'list' => $list,
                    ]);
                } catch (\Throwable $e) {
                    Craft::error('Pointz could not reward a Dispatch subscription: ' . $e->getMessage(), 'pointz');
                }
            }
        );
    }

    private function _registerOrderPanel(): void
    {
        Craft::$app->getView()->hook('cp.commerce.order.edit.details', function(array &$context) {
            $order = $context['order'] ?? null;

            if (!$order instanceof Order || !$order->id) {
                return null;
            }

            if (!Craft::$app->getUser()->checkPermission('pointz-viewBalances')) {
                return null;
            }

            $plugin = $this;
            $ledger = $plugin->getLedger();
            $transactions = $ledger->getTransactionsForOrder($order->id);

            if (!$transactions) {
                return null;
            }

            return Craft::$app->getView()->renderTemplate('pointz/_order-panel', [
                'order' => $order,
                'transactions' => $transactions,
                'settings' => $plugin->getSettings(),
                'plugin' => $plugin,
                'balanceUrl' => $order->getCustomerId()
                    ? UrlHelper::cpUrl('pointz/balances/' . $order->getCustomerId())
                    : null,
            ]);
        });
    }
}
