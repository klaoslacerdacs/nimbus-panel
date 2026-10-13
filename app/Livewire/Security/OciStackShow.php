<?php

namespace App\Livewire\Security;

use App\Models\OciStack;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class OciStackShow extends Component
{
    use AuthorizesRequests;

    public OciStack $stack;

    public function mount(OciStack $stack): void
    {
        $this->authorize('view', $stack);
        $this->stack = $stack->loadMissing(['ociConnection', 'server']);
    }

    public function render()
    {
        return view('livewire.security.oci-stack-show');
    }
}
