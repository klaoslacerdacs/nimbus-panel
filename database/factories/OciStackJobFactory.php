<?php

namespace Database\Factories;

use App\Models\OciStack;
use App\Models\OciStackJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OciStackJob>
 */
class OciStackJobFactory extends Factory
{
    public function definition(): array
    {
        return [
            'oci_stack_id' => OciStack::factory(),
            'team_id' => fn (array $attributes) => OciStack::find($attributes['oci_stack_id'])->team_id,
            'operation' => 'apply',
            'job_ocid' => 'ocid1.ormjob.oc1.sa-saopaulo-1.'.fake()->lexify('????????????'),
            'status' => 'succeeded',
            'log' => "Terraform apply\nApply complete! Resources: 1 added, 0 changed, 0 destroyed.",
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
        ];
    }
}
