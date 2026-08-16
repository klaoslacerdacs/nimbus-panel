<?php

namespace App\Livewire\Security;

use App\Jobs\OciStackDestroyJob;
use App\Models\OciStack;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class OciStacks extends Component
{
    use AuthorizesRequests;

    public $stacks;

    public ?int $stackPendingDestroy = null;

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
        $this->stacks = OciStack::ownedByCurrentTeam()->with(['ociConnection', 'server'])->get();
    }

    public function deleteStack(int $id): void
    {
        try {
            $stack = OciStack::ownedByCurrentTeam()->findOrFail($id);
            $this->authorize('delete', $stack);

            if ($stack->stack_ocid) {
                $this->confirmDestroy($id);

                return;
            }

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

    public function confirmDestroy(int $id): void
    {
        try {
            $stack = OciStack::ownedByCurrentTeam()->findOrFail($id);
            $this->authorize('delete', $stack);
            $this->stackPendingDestroy = $id;
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function cancelDestroy(): void
    {
        $this->stackPendingDestroy = null;
    }

    public function destroyStack(): void
    {
        try {
            $stack = OciStack::ownedByCurrentTeam()->findOrFail($this->stackPendingDestroy);
            $this->authorize('delete', $stack);

            $this->stackPendingDestroy = null;

            if ($stack->stack_ocid) {
                $stack->update(['status' => 'destroying']);
                OciStackDestroyJob::dispatch($stack);
                $this->loadStacks();
                $this->dispatch('success', 'OCI destroy job queued.');
            } else {
                $stackName = $stack->name;
                $stack->delete();
                $this->loadStacks();
                auditLog('ui.oci_stack.deleted', [
                    'team_id' => currentTeam()->id,
                    'oci_stack_name' => $stackName,
                ]);
                $this->dispatch('success', 'OCI stack deleted.');
            }
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.security.oci-stacks');
    }
}
