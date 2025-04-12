<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Candidature;

class EntretienProgramme extends Notification
{
    use Queueable;

    protected $candidature;

    public function __construct(Candidature $candidature)
    {
        $this->candidature = $candidature;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $offre = $this->candidature->offre;
        $entreprise = $offre->entreprise;

        return (new MailMessage)
            ->subject('Entretien programmé - JobConnect')
            ->greeting('Bonjour,')
            ->line("Un entretien a été programmé pour votre candidature à l'offre : {$offre->titre}")
            ->line("Entreprise : {$entreprise->nom_entreprise}")
            ->line("Date de l'entretien : " . $this->candidature->date_entretien->format('d/m/Y H:i'))
            ->line("Type d'entretien : " . $this->candidature->type_entretien)
            ->when($this->candidature->type_entretien === 'présentiel', function ($message) {
                return $message->line("Lieu : " . $this->candidature->lieu_entretien);
            })
            ->when($this->candidature->type_entretien === 'visio', function ($message) {
                return $message->line("Lien de visioconférence : " . $this->candidature->lien_visio);
            })
            ->action('Voir les détails', url("/candidatures/{$this->candidature->id}"))
            ->line('Merci de confirmer votre présence.');
    }
}