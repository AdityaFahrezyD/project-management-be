<?php

namespace App\Enums;

enum ProjectRole: string
{
    case Manager = 'manager';
    case Finance = 'finance';
    case Member = 'member';
    case Viewer = 'viewer';
}
