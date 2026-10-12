<?php

namespace App\Models;

use App\Enums\OciAuthenticationMethod;
use App\Traits\ClearsGlobalSearchCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OciConnection extends BaseModel
{
    use ClearsGlobalSearchCache, HasFactory;

    protected $fillable = [
        'team_id',
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

    /**
     * @param  string|null  $region  Target region; defaults to the connection's home region.
     */
    public function toApiConfig(?string $region = null): array
    {
        $region = $region ?: $this->region;

        if ($this->authentication_method === OciAuthenticationMethod::INSTANCE_PRINCIPAL) {
            return ['auth_mode' => 'instance_principal', 'region' => $region];
        }

        return [
            'auth_mode' => 'api_key',
            'tenancy_id' => $this->tenancy_ocid,
            'user_id' => $this->user_ocid,
            'fingerprint' => $this->fingerprint,
            'key_content' => $this->private_key,
            'passphrase' => $this->passphrase,
            'region' => $region,
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
