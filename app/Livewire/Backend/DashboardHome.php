<?php

namespace App\Livewire\Backend;

use Livewire\Component;

class DashboardHome extends Component
{
    // Module 10 — données pré-calculées via RefreshDashboardStatsJob + cache Redis
    // Ne JAMAIS recalculer les stats en direct ici

    public function render()
    {
        return view('frontend::dashboardHome');
    }
}
