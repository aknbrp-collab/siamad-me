<?php

namespace App\Livewire;

use App\Livewire\Concerns\ManagesAccount;
use Livewire\Component;

/**
 * Base for the mahasantri, ustadz and wali santri forms: each edits a person
 * with a login account.
 */
abstract class PersonForm extends Component
{
    use ManagesAccount;

    /** Set after creating, so closing the credentials modal moves to the edit page. */
    public bool $justCreated = false;

    /**
     * The edit route of the person just created.
     */
    abstract protected function editRoute(): ?string;

    public function forgetCredentials(): void
    {
        $this->reset('credentialUsername', 'credentialName', 'credentialPassword');

        if ($this->justCreated && ($route = $this->editRoute()) !== null) {
            $this->redirect($route, navigate: true);
        }
    }
}
