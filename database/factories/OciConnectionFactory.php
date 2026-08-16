<?php

namespace Database\Factories;

use App\Enums\OciAuthenticationMethod;
use App\Models\OciConnection;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OciConnection>
 */
class OciConnectionFactory extends Factory
{
    protected $model = OciConnection::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->words(2, true),
            'authentication_method' => OciAuthenticationMethod::API_KEY,
            'region' => 'us-phoenix-1',
            'compartment_ocid' => null,
            'tenancy_ocid' => 'ocid1.tenancy.oc1..example',
            'user_ocid' => 'ocid1.user.oc1..example',
            'fingerprint' => 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99',
            'private_key' => 'test-private-key',
            'passphrase' => null,
        ];
    }
}
