<?php

namespace justinholtweb\pointz\gql\types;

use craft\gql\GqlEntityRegistry;
use craft\gql\types\DateTime;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * `PointzTransaction` — one line of the customer's own history.
 *
 * Who made an adjustment, the batch it belonged to and its internal reference stay out: they are
 * the shop's bookkeeping, not the customer's.
 */
class Transaction
{
    public const NAME = 'PointzTransaction';

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::NAME)) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::NAME, new ObjectType([
            'name' => self::NAME,
            'description' => 'One movement in the signed-in customer’s Pointz ledger.',
            'fields' => static fn() => [
                'id' => Type::nonNull(Type::int()),
                'currency' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => '`points` or `credit`.',
                ],
                'kind' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => '`earn`, `redeem`, `expire`, `adjust`, `reverse`, `refund`, `revoke`, `reward` or `import`.',
                ],
                'kindLabel' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'The kind in words — “Earned”, “Redeemed”, “Expired”…',
                ],
                'amount' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'Signed: positive arrives, negative leaves.',
                ],
                'balanceAfter' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'The balance in this currency once the movement was posted.',
                ],
                'status' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => '`pending`, `posted` or `reversed`.',
                ],
                'orderId' => [
                    'type' => Type::int(),
                    'description' => 'The customer’s order the movement belongs to, if any.',
                ],
                'note' => [
                    'type' => Type::string(),
                    'description' => 'The note written with the movement — the same text the Twig ledger shows.',
                ],
                'dateCreated' => DateTime::getType(),
            ],
            'resolveField' => [Account::class, 'resolveField'],
        ]));
    }
}
