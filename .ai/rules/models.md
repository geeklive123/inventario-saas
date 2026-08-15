---
paths:
  - 'resources/views/pages/⚡supplies.blade.php, resources/views/pages/⚡bouquets.blade.php, resources/views/pages/catalog/**, app/Models/Product.php'
---

# Models

## Insumos de florería nunca son vendibles
En la UI especializada de florería, todo insumo creado o editado desde Insumos se persiste con is_sellable=false y sale_price_base=0; no se expone un control Vendible. Ramos se persisten vendibles. Los selectores de venta futuros deben usar una consulta explícita is_sellable=true. El costo del ramo es dinámico por almacén desde la receta activa y el costo vigente del balance; no se persiste en products.
