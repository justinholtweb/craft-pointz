<?php

namespace justinholtweb\pointz\events;

use craft\elements\User;
use yii\base\Event;

/**
 * Raised after a customer joins or leaves the loyalty programme — from the site, at
 * registration, or because somebody put them in (or took them out of) the member group in the CP.
 *
 * The place to subscribe a new member to a newsletter list or send a welcome email of the site's
 * own. A welcome reward doesn't need it: a rule on the "Joining the loyalty programme" trigger pays
 * that, and has already run by the time this is raised.
 */
class MembershipEvent extends Event
{
    public User $user;

    /**
     * How it happened: `Membership::SOURCE_SITE`, `SOURCE_REGISTRATION` or `SOURCE_ADMIN` (the
     * group was assigned outside Pointz — the CP, or another plugin or module).
     */
    public string $source;
}
