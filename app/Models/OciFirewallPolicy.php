<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named set of firewall rules shared by several VMs; each attached stack applies it through Terraform.
 */
class OciFirewallPolicy extends BaseModel
{
    use HasFactory;

    protected $fillable = ['team_id', 'name', 'description', 'rules'];

    #[\Override]
    protected function casts(): array
    {
        return ['rules' => 'array'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function stacks(): BelongsToMany
    {
        return $this->belongsToMany(OciStack::class);
    }

    public static function ownedByCurrentTeam(): Builder
    {
        return self::query()->where('team_id', currentTeam()->id);
    }
}
