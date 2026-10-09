<?php

namespace justinholtweb\pointz\gql\types;

use craft\gql\GqlEntityRegistry;
use craft\gql\types\DateTime;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * `PointzAccount` — the signed-in customer's balances in the current store.
 *
 * The GraphQL twin of {@see \justinholtweb\pointz\models\Account}, without the ids: a customer has
 * no use for their account row's id, and nothing here should invite a query by one.
 */
class Account
{
    public const NAME = 'PointzAccount';

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::NAME)) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::NAME, new ObjectType([
            'name' => self::NAME,
            'description' => 'The signed-in customer’s Pointz balances in the current store.',
            'fields' => static fn() => [
                'pointsBalance' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'Spendable points.',
                ],
                'pendingPoints' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'Points earned but still on hold.',
                ],
                'creditBalance' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'Spendable store credit (Pro).',
                ],
                'pendingCredit' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'Store credit earned but still on hold.',
                ],
                'lifetimePoints' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'Every point ever earned.',
                ],
                'lifetimeCredit' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'Every unit of store credit ever earned.',
                ],
                'dateLastActivity' => [
                    'type' => DateTime::getType(),
                    'description' => 'When value last moved on the account.',
                ],
            ],
            'resolveField' => [Account::class, 'resolveField'],
        ]));
    }

    /**
     * Every Pointz type reads its field straight off the model: a property, or a Yii getter.
     */
    public static function resolveField(object $source, array $args, mixed $context, \GraphQL\Type\Definition\ResolveInfo $info): mixed
    {
        return $source->{$info->fieldName};
    }
}
