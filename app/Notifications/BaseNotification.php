<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

abstract class BaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title($notifiable))
            ->line($this->body($notifiable))
            ->action($this->actionText(), $this->url());
    }

    /** @return array{ticket_number: string|null, title: string, body: string, url: string} */
    public function toArray(object $notifiable): array
    {
        return [
            'ticket_number' => $this->ticketNumber(),
            'title' => $this->title($notifiable),
            'body' => $this->body($notifiable),
            'url' => $this->url(),
        ];
    }

    abstract protected function title(object $notifiable): string;

    abstract protected function body(object $notifiable): string;

    abstract protected function url(): string;

    protected function ticketNumber(): ?string
    {
        return null;
    }

    protected function actionText(): string
    {
        return 'View ticket';
    }
}
