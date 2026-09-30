<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitationNotification extends Notification
{
    public function __construct(public string $workspaceName, public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Undangan workspace '.$this->workspaceName)
            ->line('Anda diundang bergabung ke '.$this->workspaceName.'.')
            ->action('Terima undangan', rtrim(config('frontend.url'), '/').'/invitations/accept?token='.rawurlencode($this->token))
            ->line('Undangan berlaku selama tujuh hari.');
    }
}
