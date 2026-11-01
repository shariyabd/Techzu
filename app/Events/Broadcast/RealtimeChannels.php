<?php

declare(strict_types=1);

namespace App\Events\Broadcast;

use Illuminate\Broadcasting\PrivateChannel;

final class RealtimeChannels
{
    public static function ticket(string $number): PrivateChannel
    {
        return new PrivateChannel("tickets.{$number}");
    }

    public static function ticketStaff(string $number): PrivateChannel
    {
        return new PrivateChannel("tickets.{$number}.staff");
    }

    public static function queue(): PrivateChannel
    {
        return new PrivateChannel('tickets.queue');
    }

    public static function user(int $id): PrivateChannel
    {
        return new PrivateChannel("App.Models.User.{$id}");
    }
}
