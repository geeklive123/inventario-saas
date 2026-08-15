---
paths:
  - 'app/Actions/Sales/**,app/Models/Sale*.php,resources/views/pages/sales/**'
---

# Sales

## Ventas consumen recetas mediante inventario oficial
Confirmar una venta solo admite productos compuestos vendibles con receta activa, congela receta/precio/costos/componentes y registra una única salida StockMovementType::Sale mediante InventoryService. Las salidas de venta no se revierten desde Inventario: solo VoidSale puede generar la reversión y marcar la venta anulada.
