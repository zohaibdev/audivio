<?php

namespace App\Enums;

enum CrawlUrlStatus: string
{
    case Pending = 'pending';
    case Crawling = 'crawling';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
