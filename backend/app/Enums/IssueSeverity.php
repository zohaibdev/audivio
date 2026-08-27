<?php

namespace App\Enums;

enum IssueSeverity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Passed = 'passed';
}
