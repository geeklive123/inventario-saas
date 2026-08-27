<?php

namespace App\Console\Commands;

use App\Actions\Catalog\CreateProductRecipe;
use App\Enums\CompanyStatus;
use App\Enums\InventoryBehavior;
use App\Enums\MembershipStatus;
use App\Enums\ProductItemType;
use App\Models\Category;
use App\Models\Company;
use App\Models\Membership;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('app:load-approved-bouquets')]
#[Description('Carga de forma idempotente los ramos aprobados para la empresa 1')]
class LoadApprovedBouquets extends Command
{
    private const COMPANY_ID = 1;

    /** @var array<string, array{name: string, price: string, components: array<string, string>}> */
    private const BOUQUETS = [
        'RAM-0002' => [
            'name' => 'Rayo de Sol', 'price' => '70',
            'components' => ['INS-0044' => '2', 'INS-0029' => '5', 'INS-0019' => '4', 'INS-0015' => '1', 'INS-0081' => '1', 'INS-0079' => '1', 'INS-0078' => '1', 'INS-0082' => '1'],
        ],
        'RAM-0003' => [
            'name' => 'Encanto Amarillo', 'price' => '65',
            'components' => ['INS-0056' => '1', 'INS-0042' => '1', 'INS-0029' => '3', 'INS-0046' => '1', 'INS-0016' => '4', 'INS-0017' => '5', 'INS-0080' => '1', 'INS-0079' => '1', 'INS-0082' => '1', 'INS-0078' => '1', 'INS-0081' => '1', 'INS-0015' => '1'],
        ],
        'RAM-0004' => [
            'name' => 'Trío Radiante', 'price' => '100',
            'components' => ['INS-0044' => '3', 'INS-0029' => '4', 'INS-0046' => '2', 'INS-0017' => '2', 'INS-0080' => '1', 'INS-0079' => '1', 'INS-0082' => '1', 'INS-0078' => '1', 'INS-0081' => '1', 'INS-0015' => '1'],
        ],
        'RAM-0005' => [
            'name' => 'Brillo Salvaje', 'price' => '165',
            'components' => ['INS-0044' => '4', 'INS-0029' => '7', 'INS-0056' => '12', 'INS-0028' => '3', 'INS-0017' => '2', 'INS-0080' => '1', 'INS-0079' => '1', 'INS-0082' => '1', 'INS-0078' => '1', 'INS-0081' => '1', 'INS-0015' => '1'],
        ],
        'RAM-0006' => [
            'name' => 'Dulce Ilusión', 'price' => '165',
            'components' => ['INS-0056' => '15', 'INS-0029' => '6', 'INS-0016' => '1', 'INS-0017' => '5', 'INS-0080' => '1', 'INS-0079' => '1', 'INS-0082' => '1', 'INS-0078' => '1', 'INS-0081' => '1', 'INS-0015' => '1'],
        ],
        'RAM-0007' => [
            'name' => 'Detalle de Luz', 'price' => '135',
            'components' => ['INS-0056' => '15', 'INS-0029' => '6', 'INS-0006' => '1', 'INS-0028' => '5', 'INS-0083' => '1', 'INS-0080' => '1', 'INS-0079' => '1', 'INS-0082' => '1', 'INS-0078' => '1', 'INS-0081' => '1', 'INS-0015' => '1'],
        ],
        'RAM-0008' => [
            'name' => 'Pétalos del Día', 'price' => '150',
            'components' => ['INS-0056' => '20', 'INS-0007' => '1', 'INS-0080' => '1', 'INS-0079' => '1', 'INS-0082' => '1', 'INS-0078' => '1', 'INS-0081' => '1', 'INS-0015' => '1'],
        ],
        'RAM-0009' => [
            'name' => 'Pasión Amarilla', 'price' => '100',
            'components' => ['INS-0037' => '20', 'INS-0029' => '6', 'INS-0017' => '3', 'INS-0080' => '1', 'INS-0079' => '1', 'INS-0082' => '1', 'INS-0078' => '1', 'INS-0081' => '1', 'INS-0015' => '1'],
        ],
        'RAM-0010' => [
            'name' => 'Rayos de Amor', 'price' => '155',
            'components' => ['INS-0056' => '8', 'INS-0044' => '2', 'INS-0029' => '4', 'INS-0046' => '2', 'INS-0016' => '1', 'INS-0019' => '5', 'INS-0080' => '1', 'INS-0079' => '1', 'INS-0082' => '1', 'INS-0081' => '1', 'INS-0015' => '1'],
        ],
        'RAM-0011' => [
            'name' => 'Brillo Suave', 'price' => '120',
            'components' => ['INS-0056' => '8', 'INS-0029' => '5', 'INS-0064' => '4', 'INS-0016' => '2', 'INS-0019' => '5', 'INS-0080' => '1', 'INS-0079' => '1', 'INS-0082' => '1', 'INS-0081' => '1', 'INS-0015' => '1'],
        ],
        'RAM-0012' => [
            'name' => 'Luz y Amor', 'price' => '165',
            'components' => ['INS-0056' => '5', 'INS-0048' => '5', 'INS-0029' => '5', 'INS-0064' => '4', 'INS-0017' => '5', 'INS-0016' => '3', 'INS-0080' => '1', 'INS-0079' => '1', 'INS-0082' => '1', 'INS-0081' => '1', 'INS-0015' => '1'],
        ],
        'RAM-0013' => [
            'name' => 'Destello Dorado', 'price' => '55',
            'components' => ['INS-0044' => '1', 'INS-0029' => '4', 'INS-0064' => '3', 'INS-0028' => '2', 'INS-0019' => '4', 'INS-0080' => '1', 'INS-0079' => '1', 'INS-0082' => '1', 'INS-0081' => '1', 'INS-0015' => '1'],
        ],
        'RAM-0014' => [
            'name' => 'Tormenta de Rosas', 'price' => '510',
            'components' => ['INS-0056' => '70', 'INS-0017' => '10', 'INS-0029' => '40', 'INS-0078' => '1', 'INS-0015' => '1', 'INS-0081' => '1', 'INS-0079' => '1', 'INS-0080' => '1', 'INS-0082' => '1'],
        ],
        'RAM-0015' => [
            'name' => '100 Razones para Amar', 'price' => '590',
            'components' => ['INS-0056' => '100', 'INS-0017' => '10', 'INS-0029' => '20', 'INS-0078' => '1', 'INS-0015' => '1', 'INS-0081' => '1', 'INS-0079' => '1', 'INS-0080' => '1', 'INS-0082' => '1'],
        ],
        'RAM-0016' => [
            'name' => 'Corazón Profundo', 'price' => '540',
            'components' => ['INS-0056' => '80', 'INS-0017' => '10', 'INS-0029' => '10', 'INS-0078' => '1', 'INS-0015' => '1', 'INS-0081' => '1', 'INS-0079' => '1', 'INS-0080' => '1', 'INS-0082' => '1'],
        ],
        'RAM-0017' => [
            'name' => 'Ramo Majestad', 'price' => '590',
            'components' => ['INS-0056' => '50', 'INS-0017' => '8', 'INS-0013' => '5', 'INS-0078' => '1', 'INS-0015' => '2', 'INS-0081' => '1', 'INS-0079' => '1', 'INS-0080' => '1', 'INS-0082' => '1'],
        ],
    ];

    public function __construct(private CreateProductRecipe $createProductRecipe)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $context = $this->resolveContext();

        if ($context === null) {
            return self::FAILURE;
        }

        [$company, $membership, $unit, $category] = $context;
        $created = 0;
        $skipped = 0;
        $errors = 0;

        foreach (self::BOUQUETS as $sku => $definition) {
            $alreadyExists = Product::query()->withoutGlobalScope('company')
                ->where('company_id', $company->getKey())
                ->where('sku', $sku)
                ->exists();

            if ($alreadyExists) {
                $skipped++;
                $this->line("OMITIDO {$sku} — ya existe y no fue modificado.");

                continue;
            }

            $components = $this->resolveComponents($company, $sku, $definition['components']);

            if ($components === null) {
                $errors++;

                continue;
            }

            try {
                $wasCreated = DB::transaction(function () use ($company, $membership, $unit, $category, $sku, $definition, $components): bool {
                    $existing = Product::query()->withoutGlobalScope('company')
                        ->where('company_id', $company->getKey())
                        ->where('sku', $sku)
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        return false;
                    }

                    $bouquet = Product::query()->create([
                        'company_id' => $company->getKey(),
                        'unit_id' => $unit->getKey(),
                        'category_id' => $category->getKey(),
                        'sku' => $sku,
                        'barcode' => null,
                        'name' => $definition['name'],
                        'description' => null,
                        'item_type' => ProductItemType::Physical,
                        'inventory_behavior' => InventoryBehavior::Components,
                        'is_sellable' => true,
                        'sale_price_base' => $definition['price'],
                        'fallback_unit_cost_base' => null,
                        'is_active' => true,
                    ]);

                    $this->createProductRecipe->handle($membership, $bouquet, '1', $components);

                    return true;
                }, attempts: 3);

                if ($wasCreated) {
                    $created++;
                    $this->info("CREADO {$sku} — {$definition['name']}");
                } else {
                    $skipped++;
                    $this->line("OMITIDO {$sku} — ya existe y no fue modificado.");
                }
            } catch (Throwable $exception) {
                report($exception);
                $errors++;
                $this->error("ERROR {$sku} — no se guardaron cambios parciales: {$exception->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Resumen: creados={$created}, omitidos={$skipped}, errores={$errors}.");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{Company, Membership, Unit, Category}|null */
    private function resolveContext(): ?array
    {
        $company = Company::query()->find(self::COMPANY_ID);

        if ($company === null || $company->status !== CompanyStatus::Active) {
            return $this->contextError('La empresa company_id=1 no existe o no está activa.');
        }

        $membership = Membership::query()->withoutGlobalScope('company')
            ->with(['company', 'user'])
            ->where('company_id', $company->getKey())
            ->where('status', MembershipStatus::Active)
            ->where('is_owner', true)
            ->oldest('id')
            ->first();

        if ($membership === null) {
            return $this->contextError('La empresa company_id=1 no tiene una membership owner activa para responsabilizar las recetas.');
        }

        $unit = Unit::query()->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('code', 'UND')
            ->where('is_active', true)
            ->first();
        $category = Category::query()->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('code', 'RAMOS')->orWhere('name', 'Ramos'))
            ->first();

        if ($unit === null || $category === null) {
            return $this->contextError('La empresa company_id=1 necesita la unidad UND y la categoría Ramos activas. No se crearon duplicados.');
        }

        return [$company, $membership, $unit, $category];
    }

    /**
     * @param  array<string, string>  $quantitiesBySku
     * @return list<array{product: Product, quantity: string, waste_percentage: string}>|null
     */
    private function resolveComponents(Company $company, string $bouquetSku, array $quantitiesBySku): ?array
    {
        $products = Product::query()->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->whereIn('sku', array_keys($quantitiesBySku))
            ->get()
            ->keyBy('sku');
        $missing = array_values(array_diff(array_keys($quantitiesBySku), $products->keys()->all()));

        if ($missing !== []) {
            $this->error("ERROR {$bouquetSku} — faltan insumos en company_id=1: ".implode(', ', $missing).'.');

            return null;
        }

        $components = [];

        foreach ($quantitiesBySku as $componentSku => $quantity) {
            $product = $products->get($componentSku);

            if (! $product instanceof Product
                || ! $product->is_active
                || $product->item_type !== ProductItemType::Physical
                || $product->inventory_behavior !== InventoryBehavior::Self) {
                $this->error("ERROR {$bouquetSku} — {$componentSku} no es un insumo físico activo con inventario propio.");

                return null;
            }

            $components[] = ['product' => $product, 'quantity' => $quantity, 'waste_percentage' => '0'];
        }

        return $components;
    }

    private function contextError(string $message): null
    {
        $this->error($message);
        $this->info('Resumen: creados=0, omitidos=0, errores=16.');

        return null;
    }
}
