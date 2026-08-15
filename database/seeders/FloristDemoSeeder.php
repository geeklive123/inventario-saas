<?php

namespace Database\Seeders;

use App\Actions\Catalog\CreateProductRecipe;
use App\Actions\Inventory\RegisterOpeningStock;
use App\Enums\CompanyModuleStatus;
use App\Enums\CompanyStatus;
use App\Enums\InventoryBehavior;
use App\Enums\MembershipStatus;
use App\Enums\ProductItemType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\StockBalance;
use App\Models\StockMovementLine;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Support\Decimal;
use App\Support\Tenancy\CurrentCompany;
use DomainException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FloristDemoSeeder extends Seeder
{
    private const TARGET_COMPANY = 'Mi Florería';

    /** @var array<string, array{name: string, symbol: string, decimal_places: int}> */
    private const UNITS = [
        'UND' => ['name' => 'Unidad', 'symbol' => 'u', 'decimal_places' => 0],
        'M' => ['name' => 'Metro', 'symbol' => 'm', 'decimal_places' => 2],
    ];

    /** @var array<string, string> */
    private const CATEGORIES = [
        'FLORES' => 'Flores',
        'EMPAQUES' => 'Empaques',
        'RAMOS' => 'Ramos',
        'INSUMOS' => 'Insumos',
    ];

    /** @var array<string, array{name: string, category: string, unit: string, cost: string, stock: string}> */
    private const SUPPLIES = [
        'ROSA-001' => ['name' => 'Rosa Roja', 'category' => 'FLORES', 'unit' => 'UND', 'cost' => '5', 'stock' => '100'],
        'TUL-001' => ['name' => 'Tulipán', 'category' => 'FLORES', 'unit' => 'UND', 'cost' => '7', 'stock' => '60'],
        'PAP-001' => ['name' => 'Papel Decorativo', 'category' => 'EMPAQUES', 'unit' => 'UND', 'cost' => '2', 'stock' => '50'],
        'CIN-001' => ['name' => 'Cinta', 'category' => 'EMPAQUES', 'unit' => 'M', 'cost' => '1', 'stock' => '80'],
        'ROSA-ROS-001' => ['name' => 'Rosa Rosada', 'category' => 'FLORES', 'unit' => 'UND', 'cost' => '5.50', 'stock' => '80'],
        'MAR-001' => ['name' => 'Margarita', 'category' => 'FLORES', 'unit' => 'UND', 'cost' => '3', 'stock' => '70'],
        'GIR-001' => ['name' => 'Girasol', 'category' => 'FLORES', 'unit' => 'UND', 'cost' => '6', 'stock' => '40'],
        'ROSA-BLA-001' => ['name' => 'Rosa Blanca', 'category' => 'FLORES', 'unit' => 'UND', 'cost' => '6', 'stock' => '90'],
        'LIR-001' => ['name' => 'Lirio', 'category' => 'FLORES', 'unit' => 'UND', 'cost' => '8', 'stock' => '50'],
        'EUC-001' => ['name' => 'Eucalipto', 'category' => 'FLORES', 'unit' => 'UND', 'cost' => '2.50', 'stock' => '60'],
    ];

    /** @var array<string, array{name: string, price: string, components: array<string, string>}> */
    private const BOUQUETS = [
        'RAMO-AMOR-001' => [
            'name' => 'Ramo Amor',
            'price' => '80',
            'components' => ['ROSA-001' => '6', 'TUL-001' => '3', 'PAP-001' => '2', 'CIN-001' => '1.5'],
        ],
        'RAMO-PRIM-001' => [
            'name' => 'Ramo Primavera',
            'price' => '95',
            'components' => ['ROSA-ROS-001' => '4', 'MAR-001' => '5', 'GIR-001' => '3', 'PAP-001' => '2', 'CIN-001' => '2'],
        ],
        'RAMO-ELEG-001' => [
            'name' => 'Ramo Elegante',
            'price' => '120',
            'components' => ['ROSA-BLA-001' => '8', 'LIR-001' => '4', 'EUC-001' => '2', 'PAP-001' => '3', 'CIN-001' => '2.5'],
        ],
    ];

    public function run(
        CurrentCompany $currentCompany,
        RegisterOpeningStock $registerOpeningStock,
        CreateProductRecipe $createProductRecipe,
    ): void {
        [$company, $membership, $branch, $warehouse] = $this->resolveContext();
        $currentCompany->set($membership);
        $this->assertRequiredModulesAreEnabled($company);

        DB::transaction(function () use ($company, $membership, $warehouse, $registerOpeningStock, $createProductRecipe): void {
            $units = $this->seedUnits($company);
            $categories = $this->seedCategories($company);
            $supplies = $this->seedSupplies($company, $units, $categories);

            foreach (self::SUPPLIES as $sku => $definition) {
                $this->registerOpeningIfPristine(
                    $membership,
                    $warehouse,
                    $supplies[$sku],
                    $definition['stock'],
                    $definition['cost'],
                    $registerOpeningStock,
                );
            }

            $this->seedBouquets($company, $membership, $units['UND'], $categories['RAMOS'], $supplies, $createProductRecipe);
        }, attempts: 3);

        $this->command->info("Florería de prueba cargada en {$company->name} / {$branch->name} / {$warehouse->name}.");
    }

    /** @return array{Company, Membership, Branch, Warehouse} */
    private function resolveContext(): array
    {
        $companies = Company::query()
            ->where('name', self::TARGET_COMPANY)
            ->where('status', CompanyStatus::Active)
            ->get();

        if ($companies->count() !== 1) {
            throw new DomainException('FloristDemoSeeder requires exactly one active company named Mi Florería.');
        }

        $company = $companies->firstOrFail();
        $membership = Membership::query()
            ->withoutGlobalScope('company')
            ->with(['company', 'user'])
            ->where('company_id', $company->getKey())
            ->where('status', MembershipStatus::Active)
            ->where('is_owner', true)
            ->oldest('id')
            ->firstOrFail();
        $branch = Branch::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('is_active', true)
            ->oldest('id')
            ->firstOrFail();
        $warehouse = Warehouse::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('branch_id', $branch->getKey())
            ->where('is_active', true)
            ->oldest('id')
            ->firstOrFail();

        return [$company, $membership, $branch, $warehouse];
    }

    private function assertRequiredModulesAreEnabled(Company $company): void
    {
        $enabledModules = CompanyModule::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('status', CompanyModuleStatus::Enabled)
            ->whereHas('module', fn ($query) => $query->whereIn('code', ['catalog', 'inventory']))
            ->count();

        if ($enabledModules !== 2) {
            throw new DomainException('FloristDemoSeeder requires the catalog and inventory modules to be enabled.');
        }
    }

    /** @return array<string, Unit> */
    private function seedUnits(Company $company): array
    {
        $units = [];

        foreach (self::UNITS as $code => $definition) {
            $units[$code] = Unit::query()->updateOrCreate(
                ['company_id' => $company->getKey(), 'code' => $code],
                [...$definition, 'is_active' => true],
            );
        }

        return $units;
    }

    /** @return array<string, Category> */
    private function seedCategories(Company $company): array
    {
        $categories = [];

        foreach (self::CATEGORIES as $code => $name) {
            $categories[$code] = Category::query()->updateOrCreate(
                ['company_id' => $company->getKey(), 'code' => $code],
                ['name' => $name, 'is_active' => true],
            );
        }

        return $categories;
    }

    /**
     * @param  array<string, Unit>  $units
     * @param  array<string, Category>  $categories
     * @return array<string, Product>
     */
    private function seedSupplies(Company $company, array $units, array $categories): array
    {
        $supplies = [];

        foreach (self::SUPPLIES as $sku => $definition) {
            $supplies[$sku] = Product::query()->updateOrCreate(
                ['company_id' => $company->getKey(), 'sku' => $sku],
                [
                    'unit_id' => $units[$definition['unit']]->getKey(),
                    'category_id' => $categories[$definition['category']]->getKey(),
                    'name' => $definition['name'],
                    'item_type' => ProductItemType::Physical,
                    'inventory_behavior' => InventoryBehavior::Self,
                    'is_sellable' => false,
                    'sale_price_base' => 0,
                    'fallback_unit_cost_base' => $definition['cost'],
                    'is_active' => true,
                ],
            );
        }

        return $supplies;
    }

    private function registerOpeningIfPristine(
        Membership $membership,
        Warehouse $warehouse,
        Product $product,
        string $quantity,
        string $unitCost,
        RegisterOpeningStock $registerOpeningStock,
    ): void {
        $hasMovements = StockMovementLine::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $membership->company_id)
            ->where('product_id', $product->getKey())
            ->whereHas('movement', fn ($query) => $query->where('warehouse_id', $warehouse->getKey()))
            ->exists();

        if ($hasMovements) {
            return;
        }

        $hasBalance = StockBalance::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $membership->company_id)
            ->where('warehouse_id', $warehouse->getKey())
            ->where('product_id', $product->getKey())
            ->exists();

        if ($hasBalance) {
            throw new DomainException("The product {$product->sku} has a stock balance without movements.");
        }

        $registerOpeningStock->handle(
            $membership,
            $warehouse,
            $product,
            $quantity,
            $unitCost,
            'Apertura de inventario - FloristDemoSeeder',
        );
    }

    /**
     * @param  array<string, Product>  $supplies
     */
    private function seedBouquets(
        Company $company,
        Membership $membership,
        Unit $unit,
        Category $category,
        array $supplies,
        CreateProductRecipe $createProductRecipe,
    ): void {
        foreach (self::BOUQUETS as $sku => $definition) {
            $bouquet = Product::query()->updateOrCreate(
                ['company_id' => $company->getKey(), 'sku' => $sku],
                [
                    'unit_id' => $unit->getKey(),
                    'category_id' => $category->getKey(),
                    'name' => $definition['name'],
                    'item_type' => ProductItemType::Physical,
                    'inventory_behavior' => InventoryBehavior::Components,
                    'is_sellable' => true,
                    'sale_price_base' => $definition['price'],
                    'fallback_unit_cost_base' => null,
                    'is_active' => true,
                ],
            );
            $components = collect($definition['components'])
                ->map(fn (string $quantity, string $componentSku): array => [
                    'product' => $supplies[$componentSku],
                    'quantity' => $quantity,
                    'waste_percentage' => '0',
                ])
                ->values()
                ->all();
            $activeRecipe = ProductRecipe::query()
                ->with('items')
                ->where('company_id', $company->getKey())
                ->where('product_id', $bouquet->getKey())
                ->where('active_slot', 1)
                ->first();

            if ($activeRecipe === null) {
                if ($bouquet->recipes()->withoutGlobalScope('company')->exists()) {
                    throw new DomainException("The product {$sku} has recipe history but no active recipe.");
                }

                $createProductRecipe->handle($membership, $bouquet, '1', $components);

                continue;
            }

            if ($activeRecipe->version !== 1 || ! $this->recipeMatches($activeRecipe, $components)) {
                throw new DomainException("The active recipe for {$sku} differs from the demo recipe.");
            }
        }
    }

    /**
     * @param  array<int, array{product: Product, quantity: string, waste_percentage: string}>  $components
     */
    private function recipeMatches(ProductRecipe $recipe, array $components): bool
    {
        if (bccomp($recipe->yield_quantity, Decimal::normalize(1, 6), 6) !== 0
            || $recipe->items->count() !== count($components)) {
            return false;
        }

        $expected = collect($components)->keyBy(fn (array $component): int => (int) $component['product']->getKey());

        foreach ($recipe->items as $item) {
            $component = $expected->get($item->component_product_id);

            if ($component === null
                || bccomp($item->quantity, Decimal::normalize($component['quantity'], 6), 6) !== 0
                || bccomp($item->waste_percentage, Decimal::normalize($component['waste_percentage'], 6), 6) !== 0) {
                return false;
            }
        }

        return true;
    }
}
