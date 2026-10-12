<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One OCI Resource Manager job (plan, apply or destroy) run for a stack, with its Terraform log.
 */
class OciStackJob extends BaseModel
{
    use HasFactory;

    public const array RUNNING = ['accepted', 'in_progress', 'waiting', 'canceling'];

    protected $fillable = [
        'team_id',
        'oci_stack_id',
        'operation',
        'job_ocid',
        'status',
        'log',
        'started_at',
        'finished_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function stack(): BelongsTo
    {
        return $this->belongsTo(OciStack::class, 'oci_stack_id');
    }

    public function isRunning(): bool
    {
        return in_array($this->status, self::RUNNING, true);
    }

    public static function ownedByCurrentTeam(): Builder
    {
        return self::query()->where('team_id', currentTeam()->id);
    }
}
