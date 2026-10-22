<?php

namespace App\Livewire\Project\Database;

use App\Livewire\Concerns\InteractsWithCloudflareDomainConnect;
use App\Livewire\Concerns\InteractsWithDnsProviders;
use App\Livewire\Concerns\ManagesResourceDomains;
use App\Traits\ListensToTeamChannel;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Domains extends Component
{
    use AuthorizesRequests;
    use InteractsWithCloudflareDomainConnect;
    use InteractsWithDnsProviders;
    use ListensToTeamChannel;
    use ManagesResourceDomains;

    /** The application of the domains page, a database has none. */
    public $application = null;

    /** The database whose domains this page manages. */
    public $database;

    public function render()
    {
        // The domains page of an application and the one of a database are the same page.
        return view('livewire.project.application.domains');
    }
}
