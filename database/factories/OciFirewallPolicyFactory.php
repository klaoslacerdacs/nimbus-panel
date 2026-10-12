<?php

namespace Database\Factories;

use App\Models\OciFirewallPolicy;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OciFirewallPolicy>
 */
class OciFirewallPolicyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => 'Public web',
            'description' => null,
            'rules' => [
                ['protocol' => 'TCP', 'port_min' => 80, 'port_max' => 80, 'source' => '0.0.0.0/0', 'description' => 'HTTP'],
                ['protocol' => 'TCP', 'port_min' => 443, 'port_max' => 443, 'source' => '0.0.0.0/0', 'description' => 'HTTPS'],
            ],
        ];
    }
}
