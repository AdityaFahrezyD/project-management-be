<?php

namespace App\Enums;

enum ResourceType: string
{
    case Labor = 'labor';
    case Material = 'material';
    case Equipment = 'equipment';
    case Software = 'software';
    case Other = 'other';
}
