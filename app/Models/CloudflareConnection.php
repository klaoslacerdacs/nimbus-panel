<?php

namespace App\Models;

use App\Services\CloudflareOAuth;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A team's Cloudflare account authorized through OAuth. Tokens are encrypted and never serialized.
 */
class CloudflareConnection extends BaseModel
{
    use HasFactory;

    protected $fillable = ['team_id', 'connected_by', 'account_id', 'account_name', 'access_token', 'refresh_token', 'expires_at', 'scopes'];

    protected $hidden = ['access_token', 'refresh_token'];

    #[\Override]
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Cloudflare API client authenticated as the connected account, refreshing the token when it is about to expire.
     */
    public function api(): PendingRequest
    {
        if ($this->expires_at !== null && $this->expires_at->isBefore(now()->addMinute())) {
            if (blank($this->refresh_token)) {
                throw new RuntimeException('The Cloudflare login expired. Reconnect Cloudflare in Security → Cloudflare.');
            }
            app(CloudflareOAuth::class)->refresh($this);
        }

        return Http::withToken($this->access_token)->acceptJson()->timeout(20)->baseUrl('https://api.cloudflare.com/client/v4');
    }
}
