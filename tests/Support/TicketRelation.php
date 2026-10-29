<?php

declare(strict_types=1);

namespace Tests\Support;

enum TicketRelation: string
{
    case Own = 'own';

    case Assigned = 'assigned';

    case Unassigned = 'unassigned';

    case Others = 'others';
}
