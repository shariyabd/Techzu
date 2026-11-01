<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

enum DemoStepType: string
{
    case AutoAssign = 'auto_assign';
    case Claim = 'claim';
    case Assign = 'assign';
    case Handoff = 'handoff';
    case AgentReply = 'agent_reply';
    case CustomerReply = 'customer_reply';
    case InternalNote = 'internal_note';
    case Status = 'status';
    case Priority = 'priority';
    case Category = 'category';

    public function isStaffAction(): bool
    {
        return ! in_array($this, [self::AutoAssign, self::CustomerReply], true);
    }
}
