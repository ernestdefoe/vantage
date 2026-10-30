<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    // Views under posts: everyone, guests included.
    'vantage.viewPostStats' => Group::GUEST_ID,
    // Any member's dashboard.
    'vantage.viewOthers' => Group::MODERATOR_ID,
    // The dashboard itself is granted to nobody: admins choose (or sell) it.
]);
