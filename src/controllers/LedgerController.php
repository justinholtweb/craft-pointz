<?php

namespace justinholtweb\pointz\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\web\Controller;
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
        $storeHandle = $this->request->getParam('store');
        $store = $storeHandle ? $stores->getStoreByHandle($storeHandle) : $stores->getPrimaryStore();
        $kind = $this->request->getParam('kind') ?: null;
        $currency = $this->request->getParam('currency') ?: null;
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = 100;

        $criteria = array_filter([
            'storeId' => $store?->id,
            'kind' => $kind,
            'currency' => $currency,
        ], static fn($value) => $value !== null);

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
            'kind' => $kind,
            'currency' => $currency,
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

        $stores = Commerce::getInstance()->getStores();
        $storeHandle = $this->request->getParam('store');
        $store = $storeHandle ? $stores->getStoreByHandle($storeHandle) : $stores->getPrimaryStore();

        $criteria = array_filter([
            'storeId' => $store?->id,
            'kind' => $this->request->getParam('kind') ?: null,
            'currency' => $this->request->getParam('currency') ?: null,
        ], static fn($value) => $value !== null);

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
}
