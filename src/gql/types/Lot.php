<?php

namespace justinholtweb\pointz\gql\types;

use craft\gql\GqlEntityRegistry;
use craft\gql\types\DateTime;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * `PointzLot` — a parcel of the customer's value with its own expiry date, for "120 points expire
 * on 4 March".
 */
class Lot
{
    public const NAME = 'PointzLot';

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::NAME)) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::NAME, new ObjectType([
            'name' => self::NAME,
            'description' => 'Value the signed-in customer is about to lose, with the date it goes.',
            'fields' => static fn() => [
                'currency' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => '`points` or `credit`.',
                ],
                'amount' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'What the lot was earned as.',
                ],
                'remaining' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'What is left of it, and so what will expire.',
                ],
                'dateExpires' => [
                    'type' => DateTime::getType(),
                    'description' => 'When the remainder expires.',
                ],
            ],
            'resolveField' => [Account::class, 'resolveField'],
        ]));
    }
}
