<?php

namespace App\Support\Tenancy;

use App\Models\Company;
use App\Models\Membership;
use LogicException;

class CurrentCompany
{
    private ?Membership $membership = null;

    public function set(Membership $membership): void
    {
        $this->membership = $membership;
    }

    public function isResolved(): bool
    {
        return $this->membership !== null;
    }

    public function id(): int
    {
        return $this->company()->getKey();
    }

    public function company(): Company
    {
        return $this->membership()->company;
    }

    public function membership(): Membership
    {
        return $this->membership ?? throw new LogicException('No current company has been resolved.');
    }
}
