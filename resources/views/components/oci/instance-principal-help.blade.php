<div class="rounded-lg border border-neutral-200 bg-neutral-50 p-4 text-sm dark:border-white/[0.08] dark:bg-white/[0.03]">
    <p>Uses the OCI identity of the VM running Nimbus Panel — no key is stored. The tenancy is read from the instance certificate.</p>
    <p class="mt-2">Requires a dynamic group matching this instance and least-privilege IAM policies, for example:</p>
    <pre class="mt-2 overflow-x-auto text-xs">Allow dynamic-group nimbus-panel to inspect compartments in tenancy
Allow dynamic-group nimbus-panel to manage orm-family in compartment &lt;apps&gt;
Allow dynamic-group nimbus-panel to manage instance-family in compartment &lt;apps&gt;
Allow dynamic-group nimbus-panel to manage virtual-network-family in compartment &lt;apps&gt;
Allow dynamic-group nimbus-panel to manage volume-family in compartment &lt;apps&gt;
Allow dynamic-group nimbus-panel to read metrics in compartment &lt;apps&gt;</pre>
</div>
