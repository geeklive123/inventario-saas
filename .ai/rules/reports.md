---
paths:
  - 'app/Services/Reports/**,app/Exports/**,resources/views/pages/reports/**,resources/views/reports/**'
---

# Reports

## Separar ventas del período de cobros del período
Las métricas de ventas usan sales.occurred_at y sus acumulados persistidos total_base, paid_total_base y balance_due_base. El movimiento de dinero usa sale_payments.occurred_at, aunque el pago pertenezca a una venta de otra fecha. En el detalle por venta, Efectivo/QR/Otros suman todos los pagos de esa venta sin filtrar por fecha de pago; mantener siempre company_id y estado confirmado.
