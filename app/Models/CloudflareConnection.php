<?php

namespace App\Models;

use App\Services\CloudflareOAuth;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
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
     * Active zone names of the connected account, cached briefly so page loads do not hit the API.
     *
     * @return list<string>
     */
    public function zoneNames(): array
    {
        return Cache::remember("cloudflare-zones:{$this->id}", 600, fn (): array => collect(
            $this->api()->get('/zones', ['account.id' => $this->account_id, 'status' => 'active', 'per_page' => 50])->throw()->json('result') ?? []
        )->pluck('name')->sort()->values()->all());
    }

    /**
     * The login expired and the client cannot refresh it: the user has to log in to Cloudflare again.
     */
    public function needsReconnect(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isBefore(now()->addMinute()) && blank($this->refresh_token);
    }

    /**
     * Cloudflare API client authenticated as the connected account, refreshing the token when it is about to expire.
     */
    public function api(): PendingRequest
    {
        if ($this->needsReconnect()) {
            throw new RuntimeException('The Cloudflare login expired. Reconnect Cloudflare in Security → Cloudflare.');
        }
        if ($this->expires_at !== null && $this->expires_at->isBefore(now()->addMinute())) {
            app(CloudflareOAuth::class)->refresh($this);
        }

        return Http::withToken($this->access_token)->acceptJson()->timeout(20)->baseUrl('https://api.cloudflare.com/client/v4');
    }
}
