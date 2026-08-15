<?php
use App\Models\Category;
use App\Support\Tenancy\CurrentCompany;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Categorías')] class extends Component {
    use WithPagination;
    public string $search = '';
    public ?int $editingId = null;
    public string $code = '';
    public string $name = '';
    public string $description = '';
    public bool $isActive = true;
    public function updatedSearch(): void { $this->resetPage(); }
    public function create(): void { Gate::authorize('create', Category::class); $this->resetForm(); Flux::modal('category-form')->show(); }
    public function edit(int $id): void { $item = Category::query()->findOrFail($id); Gate::authorize('update', $item); $this->editingId=$item->id; $this->code=$item->code; $this->name=$item->name; $this->description=$item->description??''; $this->isActive=$item->is_active; Flux::modal('category-form')->show(); }
    public function save(): void {
        $companyId=app(CurrentCompany::class)->id(); $item=$this->editingId?Category::query()->findOrFail($this->editingId):null; Gate::authorize($item?'update':'create',$item??Category::class);
        $data=$this->validate(['code'=>['required','string','max:50',Rule::unique('categories','code')->where('company_id',$companyId)->ignore($item)],'name'=>['required','string','max:255'],'description'=>['nullable','string'],'isActive'=>['boolean']]);
        Category::query()->updateOrCreate(['id'=>$item?->id],['company_id'=>$companyId,'code'=>$data['code'],'name'=>$data['name'],'description'=>filled($data['description'])?$data['description']:null,'is_active'=>$data['isActive']]);
        Flux::modal('category-form')->close(); Flux::toast(variant:'success',text:$item?'Categoría actualizada.':'Categoría creada.'); $this->resetForm();
    }
    public function toggle(int $id): void { $item=Category::query()->findOrFail($id); Gate::authorize($item->is_active?'deactivate':'update',$item); $item->update(['is_active'=>!$item->is_active]); Flux::toast(variant:'success',text:'Estado actualizado.'); }
    #[Computed] public function categories(): LengthAwarePaginator { return Category::query()->when($this->search,fn($q)=>$q->where(fn($n)=>$n->where('name','like','%'.$this->search.'%')->orWhere('code','like','%'.$this->search.'%')))->latest()->paginate(12); }
    private function resetForm(): void { $this->reset(['editingId','code','name','description']); $this->isActive=true; $this->resetValidation(); }
}; ?>
<div class="flex w-full flex-col gap-6">
    <div class="flex flex-col justify-between gap-4 md:flex-row md:items-center"><div><flux:heading size="xl">Categorías</flux:heading><flux:text>Clasificación plana y sencilla del catálogo.</flux:text></div>@can('create',App\Models\Category::class)<flux:button variant="primary" icon="plus" wire:click="create">Nueva categoría</flux:button>@endcan</div>
    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Buscar categoría" class="max-w-md" />
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">@forelse($this->categories as $category)<flux:card wire:key="category-{{ $category->id }}"><div class="flex items-start justify-between gap-4"><div><flux:heading>{{ $category->name }}</flux:heading><flux:text>{{ $category->code }}</flux:text></div><flux:badge :color="$category->is_active?'green':'zinc'">{{ $category->is_active?'Activa':'Inactiva' }}</flux:badge></div><flux:text class="mt-3">{{ $category->description?:'Sin descripción.' }}</flux:text><div class="mt-5 flex justify-end gap-2"><flux:button size="sm" variant="subtle" wire:click="edit({{ $category->id }})">Editar</flux:button><flux:button size="sm" variant="subtle" wire:click="toggle({{ $category->id }})" wire:confirm="¿Cambiar el estado de esta categoría?">{{ $category->is_active?'Desactivar':'Activar' }}</flux:button></div></flux:card>@empty<flux:card class="sm:col-span-2 xl:col-span-3"><div class="py-10 text-center"><flux:heading>No hay categorías</flux:heading><flux:text>Crea la primera para organizar tus productos.</flux:text></div></flux:card>@endforelse</div>
    {{ $this->categories->links() }}
    <flux:modal name="category-form" class="max-w-lg"><form wire:submit="save" class="space-y-5"><flux:heading size="lg">{{ $editingId?'Editar categoría':'Nueva categoría' }}</flux:heading><flux:input wire:model="code" label="Código" required /><flux:input wire:model="name" label="Nombre" required /><flux:textarea wire:model="description" label="Descripción" /><flux:switch wire:model="isActive" label="Activa" /><div class="flex justify-end gap-3"><flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close><flux:button type="submit" variant="primary">Guardar</flux:button></div></form></flux:modal>
</div>
