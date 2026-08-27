<?php

namespace App\Enums;

enum AuditStatus: string
{
    case Pending = 'pending';
    case Crawling = 'crawling';
    case Analyzing = 'analyzing';
    case Completed = 'completed';
    case Failed = 'failed';
}
