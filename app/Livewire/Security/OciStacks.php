<?php

namespace App\Livewire\Security;

use App\Models\OciStack;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class OciStacks extends Component
{
    use AuthorizesRequests;

    public $stacks;

    public function mount(): void
    {
        try {
            $this->authorize('viewAny', OciStack::class);
            $this->loadStacks();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function getListeners(): array
    {
        return [
            'stackCreated' => 'loadStacks',
        ];
    }

    public function loadStacks(): void
    {
        $this->stacks = OciStack::ownedByCurrentTeam()->with('ociConnection')->get();
    }

    public function deleteStack(int $id): void
    {
        try {
            $stack = OciStack::ownedByCurrentTeam()->findOrFail($id);
            $this->authorize('delete', $stack);

            $stackName = $stack->name;
            $stack->delete();
            $this->loadStacks();

            auditLog('ui.oci_stack.deleted', [
                'team_id' => currentTeam()->id,
                'oci_stack_name' => $stackName,
            ]);

            $this->dispatch('success', 'OCI stack deleted.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.security.oci-stacks');
    }
}
