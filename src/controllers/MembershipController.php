<?php

namespace justinholtweb\pointz\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\pointz\Plugin;
use yii\web\Response;

/**
 * Joining and leaving the loyalty programme from the site — a "Join the club" button on the
 * account page:
 *
 * ```twig
 * {% if craft.pointz.isMember() %}
 *   Member since {{ craft.pointz.memberSince()|date }}
 * {% else %}
 *   <form method="post">
 *     {{ csrfInput() }}
 *     {{ actionInput('pointz/membership/join') }}
 *     <button>Join the club</button>
 *   </form>
 * {% endif %}
 * ```
 *
 * Signed-in customers only, on themselves only, by POST with a CSRF token (Craft checks it).
 * There is no user parameter, on purpose.
 */
class MembershipController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function actionJoin(): ?Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        $membership = Plugin::getInstance()->getMembership();
        $user = Craft::$app->getUser()->getIdentity();

        if (!$membership->isEnabled()) {
            return $this->asFailure(Craft::t('pointz', 'The loyalty programme isn’t open to sign-ups.'), $this->_data($user->id, false));
        }

        if (!$membership->join($user)) {
            return $this->asFailure(Craft::t('pointz', 'Couldn’t join the loyalty programme.'), $this->_data($user->id, false));
        }

        return $this->asSuccess(Craft::t('pointz', 'You’ve joined the loyalty programme.'), $this->_data($user->id));
    }

    public function actionLeave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        $membership = Plugin::getInstance()->getMembership();
        $user = Craft::$app->getUser()->getIdentity();

        if (!$membership->isEnabled()) {
            return $this->asFailure(Craft::t('pointz', 'The loyalty programme isn’t open to sign-ups.'), $this->_data($user->id, false));
        }

        if (!$membership->leave($user)) {
            return $this->asFailure(Craft::t('pointz', 'Couldn’t leave the loyalty programme.'), $this->_data($user->id, false));
        }

        return $this->asSuccess(Craft::t('pointz', 'You’ve left the loyalty programme.'), $this->_data($user->id));
    }

    /**
     * The same shape as the cart actions: `success`, `message`, and the state after.
     *
     * @return array{success: bool, isMember: bool, memberSince: string|null}
     */
    private function _data(int $userId, bool $success = true): array
    {
        $membership = Plugin::getInstance()->getMembership();
        $since = $membership->getMemberSince($userId);

        return [
            'success' => $success,
            'isMember' => $membership->isMember($userId),
            'memberSince' => $since?->format(DATE_ATOM),
        ];
    }
}
