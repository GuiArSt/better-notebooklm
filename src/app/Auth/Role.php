<?php

namespace App\Auth;

final class Role
{
    public const OWNER = 'owner';

    public const REVIEWER = 'reviewer';

    public const MEMBER = 'member';

    public const ALL = [self::OWNER, self::REVIEWER, self::MEMBER];
}
