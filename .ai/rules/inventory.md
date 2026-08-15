---
paths:
  - 'resources/views/pages/inventory/⚡stock.blade.php,app/Actions/Inventory/RegisterOpeningStock.php'
---

# Inventory

## Apertura se determina por historial
La disponibilidad para Cargar existencia inicial se determina por producto + almacén + movimiento Opening existente, nunca por el saldo actual. Una apertura revertida o agotada sigue impidiendo una segunda apertura; las unidades posteriores entran como compra/entrada.
