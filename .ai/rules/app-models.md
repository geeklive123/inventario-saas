---
paths:
  - app/Models/Product.php
---

# App Models

## Insumos de florería nunca son vendibles
En esta versión, Insumos persiste physical+self con is_sellable=false y sale_price_base=0. Ramos persiste components con is_sellable=true. Los selectores de venta futuros deben consultar is_sellable=true explícitamente.
