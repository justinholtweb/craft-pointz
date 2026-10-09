<?php

namespace justinholtweb\pointz\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * `PointzQuote` — what redeeming comes to on the customer's cart. The fields the
 * `pointz/cart/quote` action returns, so a storefront can switch between the two freely.
 */
class Quote
{
    public const NAME = 'PointzQuote';

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::NAME)) {
            return $type;
        }

        $float = static fn(string $description) => ['type' => Type::nonNull(Type::float()), 'description' => $description];

        return GqlEntityRegistry::createEntity(self::NAME, new ObjectType([
            'name' => self::NAME,
            'description' => 'What redeeming points and store credit comes to on the signed-in customer’s cart.',
            'fields' => static fn() => [
                'points' => $float('Points that will be spent, after every clamp.'),
                'pointsValue' => $float('What those points take off the order.'),
                'credit' => $float('Store credit that will be spent.'),
                'maxPoints' => $float('The most points this cart could take right now.'),
                'maxCredit' => $float('The most store credit this cart could take right now.'),
                'pointsBalance' => $float('The customer’s spendable points.'),
                'creditBalance' => $float('The customer’s spendable store credit.'),
                'base' => $float('The figure redemption is measured against.'),
                'cap' => $float('The most money points may take off this order.'),
                'totalDiscount' => $float('Points value plus credit.'),
                'wasClamped' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'Whether the customer got less than they asked for.',
                ],
                'notices' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                    'description' => 'Why, in the customer’s language.',
                ],
            ],
            'resolveField' => [Account::class, 'resolveField'],
        ]));
    }
}
