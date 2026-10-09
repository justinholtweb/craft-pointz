<?php

namespace justinholtweb\pointz\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;

/**
 * `PointzRedeemResult` — what `pointzRedeem` and `pointzRemoveRedemption` answer: the same
 * `success`, `message` and `quote` the `pointz/cart/*` actions return as JSON.
 */
class RedeemResult
{
    public const NAME = 'PointzRedeemResult';

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::NAME)) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::NAME, new ObjectType([
            'name' => self::NAME,
            'description' => 'The outcome of applying or removing a redemption.',
            'fields' => static fn() => [
                'success' => Type::nonNull(Type::boolean()),
                'message' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'Ready to show the customer.',
                ],
                'quote' => [
                    'type' => Quote::getType(),
                    'description' => 'What the cart’s redemption now comes to.',
                ],
            ],
            'resolveField' => static fn(array $source, array $args, mixed $context, ResolveInfo $info) => $source[$info->fieldName] ?? null,
        ]));
    }
}
