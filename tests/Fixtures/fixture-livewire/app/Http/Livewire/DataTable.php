<?php

namespace App\Http\Livewire;

use Livewire\Component;
use Livewire\WithPagination;

/**
 * Livewire V2 DataTable component.
 *
 * Demonstrates WithPagination trait (V2 style), listeners array,
 * and $queryString — all V2→V3 migration targets.
 */
class DataTable extends Component
{
    use WithPagination;

    public $search = '';

    public $perPage = 10;

    protected $queryString = ['search', 'perPage'];

    protected $listeners = [
        'refreshTable'    => '$refresh',
        'clearSearch'     => 'clearSearch',
        'itemDeleted'     => 'handleItemDeleted',
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    public function getVisibleRowCountProperty(): int
    {
        return $this->perPage;
    }

    public function handleItemDeleted(int $id): void
    {
        $this->emit('tableUpdated');
    }

    public function render()
    {
        return view('livewire.data-table');
    }
}
