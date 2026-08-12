<?php

namespace App\Policies;

use App\Models\OciConnection;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class OciConnectionPolicy
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
    public function view(User $user, OciConnection $ociConnection): bool
    {
        return $user->teams->contains('id', $ociConnection->team_id);
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
    public function update(User $user, OciConnection $ociConnection): Response
    {
        if ($user->isAdminOfTeam($ociConnection->team_id)) {
            return Response::allow();
        }

        return Response::deny('You are not an admin of this team.');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, OciConnection $ociConnection): Response
    {
        if ($user->isAdminOfTeam($ociConnection->team_id)) {
            return Response::allow();
        }

        return Response::deny('You are not an admin of this team.');
    }
}
