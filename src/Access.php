<?php

namespace ErnestDefoe\Vantage;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

class Access
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    /** Your own dashboard needs the permission; anyone else's needs viewOthers. */
    public static function canView(User $actor, User $user): bool
    {
        if ($actor->isGuest()) {
            return false;
        }

        return $actor->id === $user->id
            ? $actor->hasPermission('vantage.viewDashboard')
            : $actor->hasPermission('vantage.viewOthers');
    }

    /**
     * Names of the groups that get the dashboard, for "Available to Gold
     * members". Admins always have it, so they aren't a way to get it.
     *
     * @return list<string>
     */
    public function groups(): array
    {
        return $this->db->table('group_permission')
            ->join('groups', 'groups.id', '=', 'group_permission.group_id')
            ->where('group_permission.permission', 'vantage.viewDashboard')
            ->where('groups.id', '!=', 1)
            ->where('groups.is_hidden', false)
            ->orderBy('groups.name_plural')
            ->pluck('groups.name_plural')
            ->map(fn ($n) => (string) $n)
            ->all();
    }
}
