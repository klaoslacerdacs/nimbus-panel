<?php

namespace App\Policies;

use App\Models\OciFirewallPolicy;
use App\Models\User;

/**
 * Firewall policies change network exposure of every attached VM: members read, admins change.
 */
class OciFirewallPolicyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, OciFirewallPolicy $policy): bool
    {
        return $user->teams->contains('id', $policy->team_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdminOfTeam((int) data_get(session('currentTeam'), 'id'));
    }

    public function update(User $user, OciFirewallPolicy $policy): bool
    {
        return $user->isAdminOfTeam($policy->team_id);
    }

    public function delete(User $user, OciFirewallPolicy $policy): bool
    {
        return $user->isAdminOfTeam($policy->team_id);
    }
}
