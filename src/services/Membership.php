<?php

namespace justinholtweb\pointz\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\User;
use craft\events\DefineUserGroupsEvent;
use craft\events\UserGroupsAssignEvent;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\models\UserGroup;
use DateTime;
use DateTimeZone;
use justinholtweb\pointz\db\Table;
use justinholtweb\pointz\events\MembershipEvent;
use justinholtweb\pointz\Plugin;

/**
 * An opt-in loyalty programme: a customer presses "Join the club" and only members earn.
 *
 * **Membership is a user group**, chosen in the settings, because that is what a rule's user
 * condition already reads — "members earn" is a rule that requires the group, with nothing new to
 * learn. Pointz adds the moment a group can't give: joining. Every way into the group goes through
 * Craft's `assignUserToGroups()`, and Pointz listens to that, so a join from the site, a ticked box
 * at registration and an admin ticking the group in the CP are the same event — recorded once,
 * paid once, announced once.
 *
 * `pointz_members` keeps when each customer joined and left. A customer who leaves and comes back
 * is a member since the day they came back; the welcome reward, like any rule without a reference,
 * pays them once ever.
 */
class Membership extends Component
{
    /**
     * @event MembershipEvent Raised after a customer joins, and after any welcome reward is paid.
     */
    public const EVENT_AFTER_JOIN = 'afterJoin';

    /**
     * @event MembershipEvent Raised after a customer leaves.
     */
    public const EVENT_AFTER_LEAVE = 'afterLeave';

    /** The customer pressed the button. */
    public const SOURCE_SITE = 'site';

    /** The customer ticked the box on the registration form. */
    public const SOURCE_REGISTRATION = 'registration';

    /** The group was assigned outside Pointz: the CP, or another plugin or module. */
    public const SOURCE_ADMIN = 'admin';

    /**
     * The body param on `users/save-user` that asks to join at registration.
     */
    public const REGISTRATION_PARAM = 'pointzJoin';

    /**
     * Where the group change Pointz is making right now came from, for the listener to read.
     */
    private ?string $_source = null;

    /**
     * The user being registered with the box ticked, so the default-group assignment that follows
     * is recorded as a registration rather than an admin's doing.
     */
    private ?int $_registering = null;

    /**
     * The member group, or null when the programme is off — no group chosen, or the chosen one has
     * since been deleted.
     */
    public function getGroup(): ?UserGroup
    {
        $uid = Plugin::getInstance()->getSettings()->membershipGroup;

        return $uid ? Craft::$app->getUserGroups()->getGroupByUid($uid) : null;
    }

    public function isEnabled(): bool
    {
        return $this->getGroup() !== null;
    }

    /**
     * Whether the customer is in the member group. Read from the database rather than the user's
     * memoized groups, so it is right in the same request as a join.
     */
    public function isMember(User|int|null $user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;
        $group = $this->getGroup();

        if (!$userId || $group === null) {
            return false;
        }

        return (new Query())
            ->from(CraftTable::USERGROUPS_USERS)
            ->where(['userId' => $userId, 'groupId' => $group->id])
            ->exists();
    }

    /**
     * When a current member joined, or null for anyone who isn't one. Someone put in the group
     * before Pointz was watching has no date.
     */
    public function getMemberSince(User|int|null $user): ?DateTime
    {
        $userId = $user instanceof User ? $user->id : $user;

        if (!$userId || !$this->isMember($userId)) {
            return null;
        }

        $joined = (new Query())
            ->select(['dateJoined'])
            ->from(Table::MEMBERS)
            ->where(['userId' => $userId, 'dateLeft' => null])
            ->scalar();

        return $joined ? DateTimeHelper::toDateTime($joined) : null;
    }

    /**
     * The customer's row — `source`, `dateJoined`, `dateLeft` — or null if they never joined.
     *
     * @return array{userId: int, source: string, dateJoined: DateTime|null, dateLeft: DateTime|null}|null
     */
    public function getRecord(User|int $user): ?array
    {
        $userId = $user instanceof User ? $user->id : $user;

        $row = (new Query())
            ->select(['userId', 'source', 'dateJoined', 'dateLeft'])
            ->from(Table::MEMBERS)
            ->where(['userId' => $userId])
            ->one();

        if (!$row) {
            return null;
        }

        $row['userId'] = (int)$row['userId'];
        $row['dateJoined'] = DateTimeHelper::toDateTime($row['dateJoined']) ?: null;
        $row['dateLeft'] = $row['dateLeft'] ? (DateTimeHelper::toDateTime($row['dateLeft']) ?: null) : null;

        return $row;
    }

    /**
     * Puts the customer in the member group. The listener does the rest.
     *
     * @return bool Whether they are a member now. False when the programme is off.
     */
    public function join(User $user, string $source = self::SOURCE_SITE): bool
    {
        $group = $this->getGroup();

        if ($group === null || !$user->id) {
            return false;
        }

        if ($this->isMember($user)) {
            return true;
        }

        $this->_assign($user->id, array_merge($this->_groupIds($user->id), [$group->id]), $source);

        return $this->isMember($user);
    }

    /**
     * Takes the customer out of the member group. Their balance stays; whether they keep earning
     * is up to the rules.
     *
     * @return bool Whether they are no longer a member.
     */
    public function leave(User $user, string $source = self::SOURCE_SITE): bool
    {
        $group = $this->getGroup();

        if ($group === null || !$user->id) {
            return false;
        }

        if (!$this->isMember($user)) {
            return true;
        }

        $this->_assign($user->id, array_values(array_diff($this->_groupIds($user->id), [$group->id])), $source);

        return !$this->isMember($user);
    }

    /**
     * The `afterAssignUserToGroups` listener: the one place a join or a leave is recorded.
     */
    public function handleGroupsAssigned(UserGroupsAssignEvent $event): void
    {
        $group = $this->getGroup();

        if ($group === null) {
            return;
        }

        $source = $this->_source;

        if ($source === null && $this->_registering === $event->userId) {
            $source = self::SOURCE_REGISTRATION;
            $this->_registering = null;
        }

        $source ??= self::SOURCE_ADMIN;

        if (in_array($group->id, array_map('intval', $event->newGroupIds), true)) {
            $this->_recordJoin($event->userId, $source);
        } elseif (in_array($group->id, array_map('intval', $event->removedGroupIds), true)) {
            $this->_recordLeave($event->userId, $source);
        }
    }

    /**
     * The `defineDefaultUserGroups` listener: a registration that posts `pointzJoin` gets the
     * member group along with Craft's default. Going in as a default group, rather than being
     * added afterwards, matters — Craft assigns the default groups as a whole list at the end of
     * registration, and would take away a group added before it.
     */
    public function handleDefaultGroups(DefineUserGroupsEvent $event): void
    {
        $group = $this->getGroup();
        $request = Craft::$app->getRequest();

        if ($group === null || $request->getIsConsoleRequest() || !$request->getIsSiteRequest()) {
            return;
        }

        if (!$this->_wantsToJoin($request->getBodyParam(self::REGISTRATION_PARAM))) {
            return;
        }

        foreach ($event->userGroups as $existing) {
            if ($existing->id === $group->id) {
                return;
            }
        }

        $event->userGroups[] = $group;

        if ($event->user->id) {
            $this->_registering = $event->user->id;
        }
    }

    private function _wantsToJoin(mixed $value): bool
    {
        return is_string($value) && in_array(strtolower(trim($value)), ['1', 'on', 'yes', 'true'], true);
    }

    /**
     * @param int[] $groupIds
     */
    private function _assign(int $userId, array $groupIds, string $source): void
    {
        $this->_source = $source;

        try {
            Craft::$app->getUsers()->assignUserToGroups($userId, array_values(array_unique(array_map('intval', $groupIds))));
        } finally {
            $this->_source = null;
        }
    }

    /**
     * @return int[]
     */
    private function _groupIds(int $userId): array
    {
        return array_map('intval', (new Query())
            ->select(['groupId'])
            ->from(CraftTable::USERGROUPS_USERS)
            ->where(['userId' => $userId])
            ->column());
    }

    private function _recordJoin(int $userId, string $source): void
    {
        $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));
        $values = ['source' => $source, 'dateJoined' => $now, 'dateLeft' => null, 'dateUpdated' => $now];

        if ((new Query())->from(Table::MEMBERS)->where(['userId' => $userId])->exists()) {
            Db::update(Table::MEMBERS, $values, ['userId' => $userId]);
        } else {
            Db::insert(Table::MEMBERS, $values + [
                'userId' => $userId,
                'dateCreated' => $now,
                'uid' => StringHelper::UUID(),
            ]);
        }

        // A fresh copy: the one being saved may carry the groups it had before this change, and a
        // welcome rule's user condition is likely to ask for this very group.
        $user = Craft::$app->getUsers()->getUserById($userId);

        if ($user === null) {
            return;
        }

        // A reward that fails must not undo the join, or the group change that caused it.
        try {
            Plugin::getInstance()->getRewards()->awardJoin($user, $source);
        } catch (\Throwable $e) {
            Craft::error("Pointz could not pay the welcome reward to user $userId: " . $e->getMessage(), 'pointz');
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_JOIN)) {
            $this->trigger(self::EVENT_AFTER_JOIN, new MembershipEvent(['user' => $user, 'source' => $source]));
        }
    }

    private function _recordLeave(int $userId, string $source): void
    {
        $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));

        Db::update(Table::MEMBERS, ['dateLeft' => $now, 'dateUpdated' => $now], ['userId' => $userId, 'dateLeft' => null]);

        $user = Craft::$app->getUsers()->getUserById($userId);

        if ($user !== null && $this->hasEventHandlers(self::EVENT_AFTER_LEAVE)) {
            $this->trigger(self::EVENT_AFTER_LEAVE, new MembershipEvent(['user' => $user, 'source' => $source]));
        }
    }
}
