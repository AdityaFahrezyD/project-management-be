<?php

namespace App\Enums;

enum AnalysisStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Archived = 'archived';
}
