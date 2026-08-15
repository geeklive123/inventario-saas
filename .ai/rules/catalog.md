---
paths:
  - 'resources/views/pages/catalog/**'
---

# Catalog

## Hide catalog domain enums behind friendly choices
Product UI must offer Producto, Insumo, Producto preparado/compuesto and Servicio. Validate that friendly choice and derive item_type/inventory_behavior server-side; never expose those enum values as editable controls. Producto and sellable Insumo intentionally share physical+self and are not persistently distinguishable without a future domain decision.

## Florería: insumos no vendibles
Esta decisión sustituye la variante anterior de “insumo vendible” para la adaptación de florería. registrationKind=supply siempre deriva physical+self, is_sellable=false y sale_price_base=0 en servidor, aunque Livewire sea manipulado. La UI no muestra precio de venta ni switch Vendible para insumos.
