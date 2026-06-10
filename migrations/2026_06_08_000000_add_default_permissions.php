<?php

declare(strict_types=1);

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'forumaker-statser.viewOnlineUsers' => Group::GUEST_ID,
    'forumaker-statser.viewStats.discussionsCount' => Group::GUEST_ID,
    'forumaker-statser.viewStats.postsCount' => Group::GUEST_ID,
    'forumaker-statser.viewStats.usersCount' => Group::GUEST_ID,
    'forumaker-statser.viewStats.latestMember' => Group::GUEST_ID,
    'forumaker-statser.viewCurrentPage' => Group::MODERATOR_ID,
]);
