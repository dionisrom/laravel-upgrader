<?php

namespace App\Http\Livewire;

use App\Models\User;
use Livewire\Component;

/**
 * Livewire V2 ContactForm component.
 *
 * Uses V2 validation ($rules array), emit(), and dispatchBrowserEvent() — all
 * migration targets for the Livewire V2→V3 package rule set.
 */
class ContactForm extends Component
{
    public $name = '';

    public $email = '';

    public $message = '';

    public $submitted = false;

    protected $rules = [
        'name'    => 'required|string|min:2|max:100',
        'email'   => 'required|email',
        'message' => 'required|string|min:10',
    ];

    protected $messages = [
        'name.required'    => 'Your name is required.',
        'email.email'      => 'Please enter a valid email address.',
        'message.min'      => 'Message must be at least 10 characters.',
    ];

    public function updated(string $field): void
    {
        $this->validateOnly($field);
    }

    public function submit(): void
    {
        $this->validate();

        // Simulate processing
        $this->submitted = true;

        // V2 event emission — migration target
        $this->emit('formSubmitted', $this->email);
        $this->dispatchBrowserEvent('contact-form-submitted', ['email' => $this->email]);
    }

    public function resetForm(): void
    {
        $this->reset(['name', 'email', 'message', 'submitted']);
    }

    public function render()
    {
        return view('livewire.contact-form');
    }
}
