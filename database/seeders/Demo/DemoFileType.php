<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

enum DemoFileType: string
{
    case Screenshot = 'png';
    case Document = 'pdf';
    case Log = 'txt';
    case Spreadsheet = 'csv';
}
