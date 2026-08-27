<?php

namespace App\Support\Authorization;

use App\Models\Membership;
use App\Models\User;

class ReportAccess
{
    public const Sales = 'reports.sales.view';

    public const Inventory = 'reports.inventory.view';

    public const Expenses = 'reports.expenses.view';

    public const Financial = 'reports.financial.view';

    public function __construct(private CompanyAccess $access) {}

    /** @return list<string> */
    public function permissionCodes(): array
    {
        return [self::Sales, self::Inventory, self::Expenses, self::Financial];
    }

    public function canViewAnyCurrent(User $user): bool
    {
        return collect($this->permissionCodes())->contains(
            fn (string $permissionCode): bool => $this->access->allowsCurrent(
                $user,
                $permissionCode,
                mutation: false,
            ),
        );
    }

    /** @return array{sales: bool, inventory: bool, expenses: bool, financial: bool} */
    public function capabilities(Membership $membership): array
    {
        $allows = fn (string $permissionCode): bool => $this->access->allows(
            $membership->user,
            $membership->company,
            $permissionCode,
            mutation: false,
        );

        return [
            'sales' => $allows(self::Sales),
            'inventory' => $allows(self::Inventory),
            'expenses' => $allows(self::Expenses),
            'financial' => $allows(self::Financial),
        ];
    }
}
