<?php

namespace App\Enums;

enum BudgetStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Archived = 'archived';
}
