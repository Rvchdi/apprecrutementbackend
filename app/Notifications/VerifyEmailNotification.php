<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class VerifyEmailNotification extends Notification
{
    use Queueable;

    protected $token;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($token)
    {
        $this->token = $token;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        Log::info('Génération de l\'email de vérification pour ' . $notifiable->email . ' avec token ' . $this->token);
        
        $url = route('verification.verify', ['token' => $this->token]);
        
        return (new MailMessage)
                    ->subject('Vérification de votre adresse email')
                    ->line('Bienvenue sur notre plateforme de recrutement.')
                    ->line('Veuillez cliquer sur le bouton ci-dessous pour vérifier votre adresse email.')
                    ->action('Vérifier mon adresse email', $url)
                    ->line('Si vous n\'avez pas créé de compte, aucune action n\'est requise.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }
}