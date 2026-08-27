<?php

namespace justinholtweb\pointz\errors;

use yii\base\Exception;

/**
 * Thrown when a balance cannot be moved: the account is short, the lock could not be taken, or a
 * reversal has nothing left to put back.
 */
class PointzException extends Exception
{
    public function getName(): string
    {
        return 'Pointz error';
    }
}
