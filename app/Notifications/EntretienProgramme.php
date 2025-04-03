<?php

namespace App\Notifications;

use App\Models\Candidature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EntretienProgramme extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * La candidature concernée par l'entretien
     *
     * @var \App\Models\Candidature
     */
    protected $candidature;

    /**
     * Create a new notification instance.
     */
    public function __construct(Candidature $candidature)
    {
        $this->candidature = $candidature;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $entreprise = $this->candidature->offre->entreprise;
        $offre = $this->candidature->offre;
        $dateEntretien = date('d/m/Y à H:i', strtotime($this->candidature->date_entretien));
        
        $mailMessage = (new MailMessage)
            ->subject('Entretien programmé pour votre candidature')
            ->greeting('Bonjour ' . $notifiable->prenom . ' ' . $notifiable->nom . ',')
            ->line('Nous avons le plaisir de vous informer que votre candidature pour l\'offre "' . $offre->titre . '" a retenu l\'attention de l\'entreprise ' . $entreprise->nom_entreprise . '.')
            ->line('Un entretien a été programmé le ' . $dateEntretien . '.');
        
        // Ajouter les détails selon le type d'entretien
        if ($this->candidature->type_entretien === 'présentiel') {
            $mailMessage->line('Il s\'agit d\'un entretien en présentiel qui se déroulera à l\'adresse suivante :')
                        ->line($this->candidature->lieu_entretien);
        } else {
            $mailMessage->line('Il s\'agit d\'un entretien en visioconférence.')
                        ->line('Lien de connexion : ' . $this->candidature->lien_visio);
        }
        
        // Ajouter les notes/instructions si présentes
        if ($this->candidature->note_entretien) {
            $mailMessage->line('Instructions complémentaires :')
                        ->line($this->candidature->note_entretien);
        }
        
        $mailMessage->action('Voir les détails de votre candidature', url('/candidatures/' . $this->candidature->id))
                    ->line('Nous vous conseillons de confirmer votre présence en vous connectant à votre espace personnel.')
                    ->line('Bonne chance pour votre entretien !');
                    
        return $mailMessage;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'candidature_id' => $this->candidature->id,
            'offre_id' => $this->candidature->offre_id,
            'entreprise_id' => $this->candidature->offre->entreprise_id,
            'date_entretien' => $this->candidature->date_entretien,
            'type_entretien' => $this->candidature->type_entretien,
        ];
    }
}