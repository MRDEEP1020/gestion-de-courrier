<?php

namespace App\Livewire\Backend;

use Livewire\Component;

class ValidationCircuit extends Component
{
    // Module 4 — Circuit de validation (Workflow)
    // Phase 1 : circuit générique unique (voir PRD.md, hors scope : circuits multiples)

    public function render()
    {
        return view('frontend::validationCircuit');
    }
}
