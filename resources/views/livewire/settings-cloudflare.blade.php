<div>
    <x-slot:title>
        Cloudflare | Coolify
    </x-slot>

    <x-settings.layout>
        <div class="application-settings-form mx-auto flex w-full max-w-none min-w-0 flex-col gap-6">
            <form wire:submit="save">
                <x-application.settings-section title="Cloudflare OAuth client"
                    description="Lets teams connect their Cloudflare account once and create tunnels and DNS records without pasting tokens.">
                    <x-slot:actions>
                        <x-status-badge :label="$isConfigured ? 'Configured' : 'Not configured'" :type="$isConfigured ? 'success' : 'neutral'" />
                    </x-slot:actions>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.input id="clientId" label="Client ID" required placeholder="From Manage Account → OAuth clients" />
                        <x-forms.input id="clientSecret" type="password" label="Client secret"
                            :placeholder="$isConfigured ? 'Stored — leave blank to keep it' : 'Shown once when the client is created'"
                            helper="Stored encrypted and never shown again." />
                    </div>

                    <div class="mt-4">
                        <x-forms.input id="scopes" label="Scopes (optional)" :placeholder="$defaultScopes"
                            helper="Leave blank to use the default shown. Change only if your client was created with other scopes." />
                    </div>

                    <div class="mt-4">
                        <x-forms.copy-input label="Callback URL (Redirect URL in Cloudflare)" :text="$callbackUrl" />
                    </div>

                    <div class="mt-4 flex justify-end gap-2">
                        @if ($isConfigured)
                            <x-modal-confirmation title="Remove the Cloudflare OAuth client?" isErrorButton buttonTitle="Remove"
                                submitAction="remove" :actions="['Teams can no longer connect Cloudflare until a client is configured again.']"
                                :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Remove client" />
                        @endif
                        <x-forms.button type="submit" class="button-highlighted">Save</x-forms.button>
                    </div>
                </x-application.settings-section>
            </form>

            <x-application.settings-section title="How to create the client in Cloudflare">
                <ol class="list-decimal space-y-1.5 pl-5 text-sm text-neutral-700 dark:text-neutral-300">
                    <li>In the Cloudflare dashboard open <strong>Manage Account → OAuth clients → Create client</strong>.</li>
                    <li><strong>Response type:</strong> <code>code</code></li>
                    <li><strong>Grant types:</strong> <code>authorization_code</code> and <code>refresh_token</code></li>
                    <li><strong>Token endpoint auth method:</strong> <code>client_secret_basic</code></li>
                    <li><strong>Redirect URL:</strong> the callback URL above.</li>
                    <li><strong>Scopes:</strong> Zero Trust Read/Write, DNS Read/Write, Zone Read and Account Settings Read.</li>
                    <li><strong>Visibility:</strong> private — only members of your Cloudflare account can authorize it.</li>
                    <li>Copy the client ID and secret here. The secret is shown only once.</li>
                </ol>
            </x-application.settings-section>
        </div>
    </x-settings.layout>
</div>
