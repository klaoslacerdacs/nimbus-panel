{{-- Nimbus: needs PublishesThroughCloudflareTunnel on the component. $tunnelResource is authorized; optional $tunnelTargetId/Label/Options pick the compose service. --}}
@if (auth()->user()->can('update', $tunnelResource) && ($this->cloudflareTunnelConnection() || $this->cloudflareTunnelLoginAvailable()))
    @php $tunnelZones = $this->cloudflareTunnelZones(); @endphp
    <div class="application-settings-section-body mt-4 w-full">
        <form wire:submit="publishThroughCloudflareTunnel" class="flex flex-col gap-3">
            <div>
                <h3 class="text-[13px] font-semibold">Publish through Cloudflare Tunnel</h3>
                <p class="text-xs text-neutral-500 dark:text-fg-dim">Adds a domain on your Cloudflare account that reaches this resource through the server's tunnel, without opening ports 80/443.</p>
            </div>
            @if ($tunnelZones !== [])
                <div class="flex flex-wrap items-end gap-2">
                    @if (filled($tunnelTargetId ?? null))
                        <div class="min-w-40">
                            <x-forms.listbox :id="$tunnelTargetId" :label="$tunnelTargetLabel" portal :options="$tunnelTargetOptions" />
                        </div>
                    @endif
                    <div class="w-24">
                        <x-forms.listbox id="cloudflareTunnelScheme" label="Protocol" portal :options="[
                            ['value' => 'https', 'label' => 'https'],
                            ['value' => 'http', 'label' => 'http'],
                        ]" />
                    </div>
                    <div class="min-w-40 flex-1"><x-forms.input id="cloudflareTunnelLabel" label="Name" required placeholder="my-app" /></div>
                    <div class="min-w-48 flex-1">
                        <x-forms.listbox id="cloudflareTunnelZone" label="Domain" searchable portal placeholder="Choose a domain"
                            :options="collect($tunnelZones)->map(fn ($zone) => ['value' => $zone, 'label' => $zone])->all()" />
                    </div>
                    <x-forms.button type="submit" class="button-highlighted" wire:loading.attr="disabled">Publish</x-forms.button>
                </div>
                <p class="text-xs text-neutral-500 dark:text-fg-dim">
                    @if ($port = $this->cloudflareTunnelContainerPort())
                        Reaches container port {{ $port }} through Traefik.
                    @else
                        No container port detected: set Ports Exposes or a per-domain port so Traefik knows where to route.
                    @endif
                    https: Cloudflare → tunnel → Traefik on 443 (certificate not verified). http: tunnel → Traefik on 80; turn off "Redirect HTTP to HTTPS". The DNS record is a CNAME to the tunnel; no A record is created.
                </p>
            @elseif ($this->cloudflareTunnelConnection())
                <p class="text-sm text-warning">The connected Cloudflare account has no active domains, or Cloudflare could not be reached.</p>
            @else
                <div><a href="{{ route('cloudflare.connect', ['return' => $cloudflareTunnelReturnPath]) }}" class="button button-highlighted text-sm">Login with Cloudflare</a></div>
            @endif
        </form>
    </div>
@endif
