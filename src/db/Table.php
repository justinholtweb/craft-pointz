<?php

namespace justinholtweb\pointz\db;

/**
 * Pointz's database tables.
 */
abstract class Table
{
    public const ACCOUNTS = '{{%pointz_accounts}}';
    public const TRANSACTIONS = '{{%pointz_transactions}}';
    public const LOTS = '{{%pointz_lots}}';
    public const LOT_USES = '{{%pointz_lot_uses}}';
    public const RULES = '{{%pointz_rules}}';
    public const CART_REDEMPTIONS = '{{%pointz_cart_redemptions}}';
}
