<?php

namespace justinholtweb\pointz\gql\mutations;

use craft\gql\base\Mutation;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;
use justinholtweb\pointz\gql\queries\Pointz as PointzQueries;
use justinholtweb\pointz\gql\resolvers\Customer;
use justinholtweb\pointz\gql\types\RedeemResult;

/**
 * `pointzRedeem` and `pointzRemoveRedemption` — `pointz/cart/redeem` and `pointz/cart/remove` over
 * GraphQL. Lite.
 *
 * Like the actions, these record an intent and spend nothing: the adjuster re-clamps it on every
 * recalculation, and only completing the order moves a balance. They act on the signed-in
 * customer's own cart only, and need the session's CSRF token, because the session is the
 * credential.
 */
class Pointz extends Mutation
{
    public const ACTION = 'redeem';

    public static function getMutations(): array
    {
        if (!GqlHelper::canSchema(PointzQueries::SCOPE, self::ACTION)) {
            return [];
        }

        $cartNumber = [
            'name' => 'cartNumber',
            'type' => Type::string(),
            'description' => 'The cart’s number. Defaults to the session’s cart. Either way it must be the signed-in customer’s own.',
        ];

        return [
            'pointzRedeem' => [
                'type' => Type::nonNull(RedeemResult::getType()),
                'args' => [
                    'cartNumber' => $cartNumber,
                    'points' => [
                        'name' => 'points',
                        'type' => Type::float(),
                        'description' => 'Points to apply. Clamped to the balance and the caps; the result says why.',
                    ],
                    'credit' => [
                        'name' => 'credit',
                        'type' => Type::float(),
                        'description' => 'Store credit to apply (Pro).',
                    ],
                    'allPoints' => [
                        'name' => 'allPoints',
                        'type' => Type::boolean(),
                        'description' => 'Apply as many points as the cart can take — the action’s `points=max`.',
                    ],
                    'allCredit' => [
                        'name' => 'allCredit',
                        'type' => Type::boolean(),
                        'description' => 'Apply as much store credit as the cart can take.',
                    ],
                ],
                'resolve' => Customer::class . '::redeem',
                'description' => 'Applies points, store credit or both to the signed-in customer’s cart.',
            ],
            'pointzRemoveRedemption' => [
                'type' => Type::nonNull(RedeemResult::getType()),
                'args' => ['cartNumber' => $cartNumber],
                'resolve' => Customer::class . '::removeRedemption',
                'description' => 'Takes the redemption back off the signed-in customer’s cart.',
            ],
        ];
    }
}
