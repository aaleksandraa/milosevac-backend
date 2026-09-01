<?php

namespace App\Enums;

enum DataSourceClass: string
{
    case Official = 'A';
    case ReputablePublic = 'B';
    case CommunityGeographic = 'C';
    case ManualResearch = 'D';
    case Derived = 'E';
}
