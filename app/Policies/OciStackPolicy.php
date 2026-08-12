<?php

namespace App\Policies;

use App\Models\OciStack;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class OciStackPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, OciStack $ociStack): bool
    {
        return $user->teams->contains('id', $ociStack->team_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, OciStack $ociStack): Response
    {
        if ($user->isAdminOfTeam($ociStack->team_id)) {
            return Response::allow();
        }

        return Response::deny('You are not an admin of this team.');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, OciStack $ociStack): Response
    {
        if ($user->isAdminOfTeam($ociStack->team_id)) {
            return Response::allow();
        }

        return Response::deny('You are not an admin of this team.');
    }
}
