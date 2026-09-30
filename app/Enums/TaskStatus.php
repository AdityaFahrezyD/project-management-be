<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Review = 'review';
    case Done = 'done';

    public function completion(): int
    {
        return match ($this) {
            self::Todo => 0,
            self::InProgress => 50,
            self::Review => 75,
            self::Done => 100,
        };
    }
}
