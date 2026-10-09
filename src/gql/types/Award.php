<?php

namespace justinholtweb\pointz\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * `PointzAward` and `PointzAwardLine` — what a product or a cart would earn, rule by rule.
 */
class Award
{
    public const NAME = 'PointzAward';
    public const LINE_NAME = 'PointzAwardLine';

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::NAME)) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::NAME, new ObjectType([
            'name' => self::NAME,
            'description' => 'What a product or a cart would earn, and which rules it comes from.',
            'fields' => static fn() => [
                'points' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'Points it would earn.',
                ],
                'credit' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'Store credit it would earn.',
                ],
                'lines' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(self::getLineType()))),
                    'description' => 'One line per contributing rule.',
                ],
                'notices' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                    'description' => 'Rules left out of the figure, and why.',
                ],
            ],
            'resolveField' => [Account::class, 'resolveField'],
        ]));
    }

    public static function getLineType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::LINE_NAME)) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::LINE_NAME, new ObjectType([
            'name' => self::LINE_NAME,
            'description' => 'One earning rule’s contribution to an award.',
            'fields' => static fn() => [
                'ruleName' => Type::nonNull(Type::string()),
                'currency' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => '`points` or `credit`.',
                ],
                'amount' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'What the rule awards, after its multiplier, rounding and caps.',
                ],
                'basis' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'The money figure the rate was applied to. Zero for a fixed award.',
                ],
                'note' => [
                    'type' => Type::string(),
                    'description' => 'Set when a cap trimmed the award.',
                ],
            ],
            'resolveField' => [Account::class, 'resolveField'],
        ]));
    }
}
