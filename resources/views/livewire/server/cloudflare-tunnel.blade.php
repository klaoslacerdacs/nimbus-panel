<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Cloudflare Tunnel | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="cloudflare-tunnel" />

        <div class="application-settings-form flex w-full flex-col gap-6">
            <x-application.settings-section id="server-cloudflare-overview-section" title="Cloudflare Tunnel"
                helper="Proxy SSH traffic through Cloudflare so the server SSH port can remain closed.">
                <x-slot:actions>
                    @if ($isCloudflareTunnelsEnabled && auth()->user()->can('update', $server))
                        <x-forms.button wire:click="updateCloudflareTunnel">Update Cloudflare Tunnel</x-forms.button>
                        <x-process-dialog @cloudflare-tunnel-update-started.window="processDialogOpen = true"
                            closeWithX size="xl">
                            <x-slot:title>Cloudflare Tunnel Update</x-slot:title>
                            <x-slot:content>
                                <livewire:activity-monitor header="Logs" fullHeight />
                            </x-slot:content>
                        </x-process-dialog>
                        <x-modal-confirmation title="Disable Cloudflare Tunnel?"
                            buttonTitle="Disable Cloudflare Tunnel" isErrorButton
                            submitAction="toggleCloudflareTunnels" :actions="$server->ip_previous
                                ? [
                                    'Cloudflare Tunnel will be disabled for this server.',
                                    'The server IP address will be restored to its previous value.',
                                ]
                                : [
                                    'Cloudflare Tunnel will be disabled for this server.',
                                    'You must manually restore the direct server IP address.',
                                    'The server may become inaccessible until the IP is corrected.',
                                ]"
                            confirmationText="DISABLE CLOUDFLARE TUNNEL"
                            confirmationLabel="Type the confirmation text to disable Cloudflare Tunnel."
                            shortConfirmationLabel="Confirmation text" />
                    @else
                        <x-status-badge :status="$isCloudflareTunnelsEnabled ? 'Enabled' : 'Disabled'"
                            :type="$isCloudflareTunnelsEnabled ? 'success' : 'neutral'" />
                    @endif
                </x-slot:actions>

                @if ($isCloudflareTunnelsEnabled)
                    <x-callout type="warning" title="Disabling the tunnel can interrupt server access">
                        The server IP must be restored to its direct address after disabling the tunnel.
                    </x-callout>
                @elseif (!$server->isFunctional())
                    <x-callout type="info" title="Validate the server for automated setup">
                        Automated configuration requires a validated server, a Cloudflare token, and an SSH domain.
                        You can also
                        <button type="button" wire:click="manualCloudflareConfig" class="font-medium underline">
                            mark a manual configuration as complete
                        </button>.
                    </x-callout>
                @else
                    <p class="text-sm leading-6 text-neutral-600 dark:text-fg-dim">
                        Choose automated setup to install and configure cloudflared, or confirm that you already
                        configured the tunnel manually.
                    </p>
                @endif
            </x-application.settings-section>

            @if (!$isCloudflareTunnelsEnabled && $server->isFunctional())
                @can('update', $server)
                    <x-application.settings-section id="server-cloudflare-fix-section" title="Fix SSH through the tunnel automatically"
                        description="Already ran the automated setup but SSH through the tunnel does not work? Coolify publishes the SSH hostname on your tunnel, creates its DNS record and only switches after SSH through it works.">
                        <form wire:submit="fixSshThroughTunnel" class="flex flex-col gap-3">
                            @if ($cloudflareOAuthMissingScopes)
                                <p class="text-sm text-warning">
                                    Login with Cloudflare needs the client scopes.
                                    @if (isInstanceAdmin())
                                        Add them in <a href="{{ route('settings.cloudflare') }}" class="underline">Settings → Cloudflare</a>.
                                    @else
                                        Ask an instance admin to add them in Settings → Cloudflare.
                                    @endif
                                </p>
                            @endif
                            <div class="grid gap-3 md:grid-cols-2">
                                <x-forms.input id="fixSshHostname" label="SSH hostname" required placeholder="ssh-server.example.com"
                                    helper="A new hostname on a domain of your Cloudflare account. It will route to ssh://localhost:22." />
                                @if ($cloudflareConnection)
                                    <x-forms.input id="cloudflareApiToken" type="password" label="Cloudflare API token (optional)"
                                        placeholder="Using {{ $cloudflareConnection->account_name }}"
                                        helper="Your team's connected Cloudflare account is used. Paste a token only if this tunnel is in another account." />
                                @elseif ($cloudflareOAuthConfigured)
                                    <div class="flex flex-col justify-end gap-1">
                                        <a href="{{ route('cloudflare.connect', ['return' => '/server/'.$server->uuid.'/cloudflare-tunnel']) }}"
                                            class="button button-highlighted text-sm">Login with Cloudflare</a>
                                        <span class="text-xs text-neutral-500 dark:text-fg-dim">Authorize once for your team; no API token needed.</span>
                                    </div>
                                @else
                                    <x-forms.input id="cloudflareApiToken" type="password" label="Cloudflare API token" required
                                        helper="Permissions: Account → Cloudflare Tunnel → Edit, and Zone → DNS → Edit. Used once, never stored. Or connect your account in Security → Cloudflare." />
                                @endif
                            </div>
                            @if (session('success'))
                                <p class="text-sm text-success">{{ session('success') }}</p>
                            @elseif (session('error'))
                                <p class="text-sm text-error">{{ session('error') }}</p>
                            @endif
                            @if ($cloudflareConnection || ! $cloudflareOAuthConfigured)
                                <div class="flex justify-end">
                                    <x-forms.button type="submit" class="button-highlighted" wire:loading.attr="disabled">Fix automatically</x-forms.button>
                                </div>
                            @endif
                        </form>
                    </x-application.settings-section>
                @endcan
            @endif

            @if (!$isCloudflareTunnelsEnabled && $server->isFunctional())
                <x-application.settings-section id="server-cloudflare-automated-section" title="Automated setup"
                    helper="Let Coolify configure the Cloudflare SSH tunnel on this server.">
                    <x-slot:actions>
                        <a class="button"
                            href="https://coolify.io/docs/knowledge-base/cloudflare/tunnels/server-ssh"
                            target="_blank">
                            Documentation
                            <x-external-link />
                        </a>
                    </x-slot:actions>

                    @cannot('update', $server)
                        <x-callout type="danger" title="Insufficient permissions">
                            You do not have permission to configure Cloudflare Tunnel for this server.
                        </x-callout>
                    @else
                        <x-process-dialog @automated.window="processDialogOpen = true" closeWithX size="xl">
                            <x-slot:title>Cloudflare Tunnel Configuration</x-slot:title>
                            <x-slot:content>
                                <livewire:activity-monitor header="Logs" fullHeight />
                            </x-slot:content>
                        </x-process-dialog>
                        <form @submit.prevent="$wire.dispatch('automatedCloudflareConfig')">
                            <div class="grid gap-4 lg:grid-cols-2">
                                <x-forms.input id="cloudflare_token" required label="Cloudflare token"
                                    type="password" />
                                <x-forms.input id="ssh_domain" label="SSH domain" required
                                    helper="Enter the hostname configured in Cloudflare without a protocol." />
                            </div>
                            <div class="mt-4 flex justify-end">
                                <x-forms.button type="submit" isHighlighted>Configure tunnel</x-forms.button>
                            </div>
                        </form>
                    @endcannot
                </x-application.settings-section>

                <x-application.settings-section id="server-cloudflare-manual-section" title="Manual setup"
                    helper="Use this only after cloudflared and the Cloudflare tunnel are already configured.">
                    @can('update', $server)
                        <x-modal-confirmation title="Confirm manual Cloudflare Tunnel configuration"
                            buttonTitle="I configured the tunnel manually"
                            submitAction="manualCloudflareConfig" :actions="[
                                'Cloudflare and cloudflared have already been configured.',
                                'An incomplete setup can make the server unreachable.',
                            ]" confirmationText="I manually configured Cloudflare Tunnel"
                            confirmationLabel="Type the confirmation text to continue."
                            shortConfirmationLabel="Confirmation text" />
                    @endcan
                </x-application.settings-section>

                @script
                    <script>
                        $wire.$on('automatedCloudflareConfig', () => {
                            window.dispatchEvent(new CustomEvent('automated'));
                            $wire.$call('automatedCloudflareConfig');
                        });
                    </script>
                @endscript
            @endif
        </div>
    </div>
</div>
