<?php

namespace App\Models;

use App\Enums\OciAuthenticationMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OciConnection extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'name',
        'authentication_method',
        'region',
        'compartment_ocid',
        'tenancy_ocid',
        'user_ocid',
        'fingerprint',
        'private_key',
        'passphrase',
    ];

    protected $hidden = [
        'private_key',
        'passphrase',
    ];

    protected $attributes = [
        'authentication_method' => OciAuthenticationMethod::API_KEY->value,
    ];

    protected function casts(): array
    {
        return [
            'authentication_method' => OciAuthenticationMethod::class,
            'private_key' => 'encrypted',
            'passphrase' => 'encrypted',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
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
