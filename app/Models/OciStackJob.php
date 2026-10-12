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

    /**
     * A record still "running" well past the job deadline was abandoned by a killed worker (SIGKILL skips failed()).
     */
    public function isRunning(): bool
    {
        return in_array($this->status, self::RUNNING, true)
            && ($this->started_at === null || $this->started_at->isAfter(now()->subMinutes(35)));
    }

    /**
     * Close the records a killed provisioning/destroy job left open.
     */
    public static function failOpenJobs(OciStack $stack, string $reason): void
    {
        $stack->jobs()->whereIn('status', self::RUNNING)->update(['status' => 'failed', 'finished_at' => now(), 'log' => $reason]);
    }

    public static function ownedByCurrentTeam(): Builder
    {
        return self::query()->where('team_id', currentTeam()->id);
    }
}
