<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

enum DemoLine: string
{
    case Conversation = 'conversation';
    case AskForDetails = 'ask_for_details';
    case Resolution = 'resolution';
    case Thanks = 'thanks';
    case Reopen = 'reopen';
}
