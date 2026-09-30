<?php

namespace App\Enums;

enum BaselineStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
