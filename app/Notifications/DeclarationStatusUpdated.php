<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeclarationStatusUpdated extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    protected $message;
    protected $declaration;
    public function __construct($message,$declaration)
    {
        //
        $this->message = $message;
        $this->declaration = $declaration;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->line('The introduction to the notification.')
            ->action('Notification Action', url('/'))
            ->line('Thank you for using our application!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        
        return [
            //
            "declaration_id"=>$this->declaration->id,
            "reference"=>$this->declaration->reference,
            "status"=>$this->declaration->status,
            "message"=>$this->message
        ];
    }
}
