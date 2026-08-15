---
paths:
  - 'app/Http/Middleware/**'
  - 'app/Providers/**'
  - 'resources/views/pages/**'
---

# Pages

## Persist tenant middleware across Livewire requests
Any custom middleware that establishes or validates company context on Livewire page routes must be registered with Livewire::addPersistentMiddleware. Keep ResolveCurrentCompany before module and permission checks so every /livewire/update request re-resolves the active membership; action Policies remain mandatory for resource mutations.

## Costo dinámico de ramos
El costo visible de un ramo se calcula por almacén desde la receta activa y el mismo costo unitario vigente que usaría una salida de inventario, incluyendo waste_percentage. No se persiste en products porque cambia con las entradas.

## Present fallback as reference cost
Expose products.fallback_unit_cost_base as “Costo de referencia”: an optional orientative value only. Editing it must never create movements or change stock_balances, weighted average cost, last inbound cost, or historical inventory value; real costs change through inventory Actions.
