<div>
    <x-slot:title>Cloudflare | Coolify</x-slot>

    <x-security.settings-layout>
        <x-application.settings-section title="Cloudflare"
            description="Connect your team's Cloudflare account once to create tunnels and DNS records without pasting API tokens.">
            <x-slot:actions>
                <x-status-badge :label="$connection ? 'Connected' : 'Not connected'" :type="$connection ? 'success' : 'neutral'" />
            </x-slot:actions>

            @if ($flashError)
                <p class="mb-3 text-sm text-error">{{ $flashError }}</p>
            @elseif ($flashSuccess)
                <p class="mb-3 text-sm text-success">{{ $flashSuccess }}</p>
            @endif

            @if ($connection)
                <div class="flex flex-col gap-1 text-sm">
                    <span><span class="text-neutral-500 dark:text-fg-dim">Account:</span> <strong>{{ $connection->account_name }}</strong> <span class="font-mono text-xs text-neutral-500 dark:text-fg-dim">{{ $connection->account_id }}</span></span>
                    <span class="text-neutral-500 dark:text-fg-dim">Connected {{ $connection->created_at->diffForHumans() }}</span>
                </div>
                @if ($canManage)
                    <div class="mt-4 flex justify-end gap-2">
                        <a href="{{ route('cloudflare.connect') }}" class="button button-secondary text-sm">Reconnect</a>
                        <x-modal-confirmation title="Disconnect Cloudflare?" isErrorButton buttonTitle="Disconnect"
                            submitAction="disconnect" :actions="['The OAuth grant is revoked. Existing tunnels keep running.']"
                            :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Disconnect" />
                    </div>
                @endif
            @elseif (! $isConfigured)
                <p class="text-sm text-neutral-600 dark:text-neutral-300">
                    The installation has no Cloudflare OAuth client yet. An instance admin can add it in
                    <a href="{{ route('settings.cloudflare') }}" class="underline">Settings → Cloudflare</a>.
                </p>
            @elseif ($canManage)
                <a href="{{ route('cloudflare.connect') }}" class="button button-highlighted text-sm">Connect Cloudflare</a>
            @else
                <p class="text-sm text-neutral-600 dark:text-neutral-300">Ask a team admin to connect Cloudflare.</p>
            @endif
        </x-application.settings-section>
    </x-security.settings-layout>
</div>
