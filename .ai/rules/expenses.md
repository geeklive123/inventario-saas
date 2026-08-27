---
paths:
  - 'app/Actions/Expenses/**'
---

# Expenses

## Expense classification is legacy-only
Operational expense creation must not ask for or accept Direct/Indirect classification or a related sale. Keep existing database columns readable for historical compatibility, but exclude them from forms, current Actions, reports, and exports.
