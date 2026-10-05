---
paths:
  - 'app/Actions/Sales/**'
---

# Actions Sales

## Advertencia de venta potencialmente duplicada
La advertencia no bloqueante compara dentro de la misma company: celular boliviano normalizado desde sales.customer_name, día local de delivery_at cuando existe (si no, occurred_at) y solapamiento de product_id en sale_items. Excluye ventas voided/cancelled y nunca considera extras. Continuar debe autorizar solo la huella del formulario actual; un nuevo intento conserva la posibilidad de registrar una venta legítima idéntica.

## Per-sale recipe substitutions use component snapshots
Apply recipe ingredient overrides inside ConfirmSale before aggregating inventory requirements. Keep the master ProductRecipe immutable; SaleItemComponent stores original and planned identities/quantities, and pendings reference that exact snapshot. Substitutes must be active physical self-inventory supplies from the same company and exact same unit; use InventoryService for all consumption and costing.
