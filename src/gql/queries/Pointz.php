<?php

namespace justinholtweb\pointz\gql\queries;

use craft\gql\base\Query;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;
use justinholtweb\pointz\gql\resolvers\Customer;
use justinholtweb\pointz\gql\types\Account;
use justinholtweb\pointz\gql\types\Award;
use justinholtweb\pointz\gql\types\Lot;
use justinholtweb\pointz\gql\types\Quote;
use justinholtweb\pointz\gql\types\Transaction;

/**
 * The read half of `craft.pointz`, for headless storefronts. Lite.
 *
 * Every field answers for the **signed-in customer** in the current store, and none takes a user:
 * see {@see Customer} for why, and for how carts are matched.
 */
class Pointz extends Query
{
    /**
     * The schema component. Granting it adds the fields; it does not widen whose data they read,
     * which is only ever the session's own user.
     */
    public const SCOPE = 'pointz.customer';

    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !GqlHelper::canSchema(self::SCOPE, 'read')) {
            return [];
        }

        $cartNumber = [
            'name' => 'cartNumber',
            'type' => Type::string(),
            'description' => 'The cart’s number. Defaults to the session’s cart. Either way it must be the signed-in customer’s own.',
        ];

        return [
            'pointzBalance' => [
                'type' => Type::float(),
                'resolve' => Customer::class . '::balance',
                'description' => 'The signed-in customer’s spendable points. Null for a guest.',
            ],
            'pointzCreditBalance' => [
                'type' => Type::float(),
                'resolve' => Customer::class . '::creditBalance',
                'description' => 'The signed-in customer’s store credit. Null for a guest.',
            ],
            'pointzPendingBalance' => [
                'type' => Type::float(),
                'resolve' => Customer::class . '::pendingBalance',
                'description' => 'Points the signed-in customer has earned that are still on hold. Null for a guest.',
            ],
            'pointzAccount' => [
                'type' => Account::getType(),
                'resolve' => Customer::class . '::account',
                'description' => 'All of the signed-in customer’s balances. Null for a guest.',
            ],
            'pointzLedger' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(Transaction::getType()))),
                'args' => [
                    'limit' => [
                        'name' => 'limit',
                        'type' => Type::int(),
                        'description' => 'How many rows, 1–' . Customer::MAX_LEDGER . '. Defaults to 25.',
                    ],
                    'offset' => [
                        'name' => 'offset',
                        'type' => Type::int(),
                    ],
                    'currency' => [
                        'name' => 'currency',
                        'type' => Type::string(),
                        'description' => '`points` or `credit`. Both when left out.',
                    ],
                ],
                'resolve' => Customer::class . '::ledger',
                'description' => 'The signed-in customer’s history, newest first. Empty for a guest.',
            ],
            'pointzExpiring' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(Lot::getType()))),
                'args' => [
                    'days' => [
                        'name' => 'days',
                        'type' => Type::int(),
                        'description' => 'The window. Defaults to the expiry-warning setting, or 30 days.',
                    ],
                ],
                'resolve' => Customer::class . '::expiring',
                'description' => 'The signed-in customer’s value that expires inside the window, soonest first. Empty when expiry is off.',
            ],
            'pointzEarnFor' => [
                'type' => Award::getType(),
                'args' => [
                    'purchasableId' => [
                        'name' => 'purchasableId',
                        'type' => Type::nonNull(Type::int()),
                    ],
                    'qty' => [
                        'name' => 'qty',
                        'type' => Type::float(),
                        'description' => 'Defaults to 1.',
                    ],
                ],
                'resolve' => Customer::class . '::earnFor',
                'description' => 'What a purchasable would earn — the “earn 240 points” badge. Null for anything that is not live, or that the schema cannot read.',
            ],
            'pointzQuote' => [
                'type' => Quote::getType(),
                'args' => [
                    'cartNumber' => $cartNumber,
                    'points' => [
                        'name' => 'points',
                        'type' => Type::float(),
                        'description' => 'Quote a hypothetical instead of the cart’s own redemption. Nothing is applied.',
                    ],
                    'credit' => [
                        'name' => 'credit',
                        'type' => Type::float(),
                    ],
                ],
                'resolve' => Customer::class . '::quote',
                'description' => 'What redeeming comes to on the signed-in customer’s cart. Null for a guest or someone else’s cart.',
            ],
            'pointzWillEarn' => [
                'type' => Award::getType(),
                'args' => ['cartNumber' => $cartNumber],
                'resolve' => Customer::class . '::willEarn',
                'description' => 'What the signed-in customer’s cart would earn if it were completed now. Null for a guest or someone else’s cart.',
            ],
        ];
    }
}
