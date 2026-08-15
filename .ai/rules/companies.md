---
paths:
  - 'app/Console/Commands/CreateInitialAdmin.php,app/Actions/Companies/CreateInitialAdmin.php'
---

# Companies

## Bootstrap the first production administrator through the command
Create the first production user only through app:create-initial-admin. It must keep the strict empty-application guard, use CreateCompany and AssignRole inside the transactional Action, hash via the User cast, and require a first-login password change. Never enable public registration or bootstrap an admin through a production seeder.
