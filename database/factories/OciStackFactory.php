<?php

namespace Database\Factories;

use App\Models\OciConnection;
use App\Models\OciStack;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OciStack>
 */
class OciStackFactory extends Factory
{
    protected $model = OciStack::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'oci_connection_id' => OciConnection::factory(),
            'name' => fake()->words(2, true),
            'stack_ocid' => null,
            'compartment_ocid' => 'ocid1.compartment.oc1..example',
            'region' => 'us-phoenix-1',
            'config_source' => 'template_zip',
            'config_url' => null,
            'tf_vars' => null,
            'status' => 'pending',
            'managed_by' => 'terraform',
            'last_plan_summary' => null,
        ];
    }
}
