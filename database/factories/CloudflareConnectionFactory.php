<?php

namespace Database\Factories;

use App\Models\CloudflareConnection;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CloudflareConnection>
 */
class CloudflareConnectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'account_id' => 'acc123',
            'account_name' => 'Nimbus Network',
            'access_token' => 'cf-access-token',
            'refresh_token' => 'cf-refresh-token',
            'expires_at' => now()->addHour(),
            'scopes' => 'offline_access',
        ];
    }
}
