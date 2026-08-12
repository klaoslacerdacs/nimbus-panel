<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OciStack extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'oci_connection_id',
        'name',
        'stack_ocid',
        'compartment_ocid',
        'region',
        'config_source',
        'config_url',
        'tf_vars',
        'status',
        'managed_by',
        'last_plan_summary',
    ];

    protected $attributes = [
        'status' => 'pending',
        'managed_by' => 'terraform',
    ];

    protected function casts(): array
    {
        return [
            'tf_vars' => 'array',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function ociConnection(): BelongsTo
    {
        return $this->belongsTo(OciConnection::class);
    }

    public static function ownedByTeam(int $teamId): Builder
    {
        return self::query()->where('team_id', $teamId);
    }

    public static function ownedByCurrentTeam(array $select = ['*']): Builder
    {
        return self::ownedByTeam(currentTeam()->id)->select($select);
    }
}
