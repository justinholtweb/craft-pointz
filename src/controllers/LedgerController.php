<?php

namespace justinholtweb\pointz\controllers;

use Craft;
use craft\commerce\models\Store;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\web\Controller;
use DateTime;
use DateTimeZone;
use justinholtweb\pointz\models\Rule;
use justinholtweb\pointz\models\Transaction;
use justinholtweb\pointz\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The ledger: every movement, filterable, and exportable on Pro.
 */
class LedgerController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('pointz-viewBalances');

        if (!Plugin::commerceIsReady()) {
            throw new ForbiddenHttpException('Craft Commerce is not installed.');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $stores = Commerce::getInstance()->getStores();
        [$store, $filters, $criteria] = $this->_filters();
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = 100;

        $query = $plugin->getLedger()->getTransactionsQuery($criteria);
        $total = (int)$query->count();

        $transactions = array_map(
            static fn(array $row) => new Transaction($row),
            (clone $query)->orderBy(['id' => SORT_DESC])->offset(($page - 1) * $perPage)->limit($perPage)->all()
        );

        return $this->renderTemplate('pointz/ledger/_index', [
            'transactions' => $transactions,
            'store' => $store,
            'stores' => $stores->getAllStores(),
            'filters' => $filters,
            'kinds' => Transaction::kinds(),
            'currencies' => Rule::currencies(),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'settings' => $plugin->getSettings(),
            'plugin' => $plugin,
        ]);
    }

    /**
     * The ledger as CSV. Pro — the finance export is the half of an audit trail that leaves the
     * building.
     */
    public function actionExport(): Response
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->isPro()) {
            throw new ForbiddenHttpException('The ledger export needs Pointz Pro.');
        }

        [, , $criteria] = $this->_filters();

        $rows = $plugin->getLedger()->getTransactionsQuery($criteria)
            ->orderBy(['id' => SORT_DESC])
            ->all();

        // Emails are fetched separately rather than joined: the ledger query is shared with the
        // index screen, and a join there would need every column qualified for MySQL's benefit.
        $emails = [];

        if ($rows) {
            $emails = (new Query())
                ->select(['id', 'email'])
                ->from(CraftTable::USERS)
                ->where(['id' => array_unique(array_column($rows, 'userId'))])
                ->pairs();
        }

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['id', 'date', 'customer', 'currency', 'kind', 'amount', 'balanceAfter', 'status', 'orderId', 'note']);

        foreach ($rows as $row) {
            fputcsv($out, [
                $row['id'],
                $row['dateCreated'],
                $emails[$row['userId']] ?? '',
                $row['currency'],
                $row['kind'],
                $row['amount'],
                $row['balanceAfter'],
                $row['status'],
                $row['orderId'] ?? '',
                $row['note'] ?? '',
            ]);
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $this->response->sendContentAsFile($csv, 'pointz-ledger.csv', ['mimeType' => 'text/csv']);
    }

    /**
     * The filters the index and the export share, so the CSV is exactly what's on screen: the
     * store, kind, currency, part of an email, and a range of days. Days are the system's time
     * zone, and both ends are inclusive — *to* the 3rd means up to midnight starting the 4th.
     *
     * @return array{0: Store|null, 1: array<string, string>, 2: array<string, mixed>}
     */
    private function _filters(): array
    {
        $stores = Commerce::getInstance()->getStores();
        $storeHandle = $this->request->getParam('store');
        $store = $storeHandle ? $stores->getStoreByHandle($storeHandle) : $stores->getPrimaryStore();

        $filters = [
            'store' => $store !== null ? $store->handle : '',
            'kind' => (string)$this->request->getParam('kind', ''),
            'currency' => (string)$this->request->getParam('currency', ''),
            'search' => trim((string)$this->request->getParam('search', '')),
            'from' => $this->_day($this->request->getParam('from'))?->format('Y-m-d') ?? '',
            'to' => $this->_day($this->request->getParam('to'))?->format('Y-m-d') ?? '',
        ];

        $from = $this->_day($filters['from']);
        $to = $this->_day($filters['to'])?->modify('+1 day');

        $criteria = array_filter([
            'storeId' => $store?->id,
            'kind' => $filters['kind'] ?: null,
            'currency' => $filters['currency'] ?: null,
            'search' => $filters['search'] ?: null,
            'dateFrom' => $from,
            'dateTo' => $to,
        ], static fn($value) => $value !== null);

        return [$store, $filters, $criteria];
    }

    /**
     * Midnight on a `Y-m-d` day in the system time zone, or null for anything else.
     */
    private function _day(mixed $value): ?DateTime
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $day = DateTime::createFromFormat('!Y-m-d', $value, new DateTimeZone(Craft::$app->getTimeZone()));

        return $day && $day->format('Y-m-d') === $value ? $day : null;
    }
}
