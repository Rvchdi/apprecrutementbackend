<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\CvResume;
use App\Models\Etudiant;
use Carbon\Carbon;
use Smalot\PdfParser\Parser;

class CvAnalysisService
{
    /**
     * Extraire le texte d'un fichier PDF
     */
    public function extractTextFromPdf(string $pdfPath): string
    {
        try {
            // Vérifier que le fichier existe
            if (!Storage::disk('public')->exists($pdfPath)) {
                throw new \Exception("Le fichier PDF n'existe pas: {$pdfPath}");
            }

            // Chemin complet du fichier
            $fullPath = Storage::disk('public')->path($pdfPath);
            
            // Utiliser la bibliothèque PdfParser pour extraire le texte
            $parser = new Parser();
            $pdf = $parser->parseFile($fullPath);
            $text = $pdf->getText();
            
            return $text;
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'extraction du texte du PDF', [
                'pdf_path' => $pdfPath,
                'error' => $e->getMessage()
            ]);
            
            return '';
        }
    }
    public function parseJsonFromText(string $jsonText): array
{
    try {
        // Décoder le texte JSON
        $decodedJson = json_decode($jsonText, true);

        // Vérifier si le JSON est valide
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Le texte JSON fourni est invalide : ' . json_last_error_msg());
        }

        return $decodedJson;
    } catch (\Exception $e) {
        Log::error('Erreur lors de la conversion du texte JSON en tableau', [
            'json_text' => $jsonText,
            'error' => $e->getMessage()
        ]);

        // Retourner une structure vide en cas d'erreur
        return [
            'Compétences Techniques' => [],
            'Compétences Organisationnelles' => []
        ];
    }
}
    /**
     * Résumer le CV avec OpenAI (DeepSeek via OpenRouter)
     */
    public function summarizeCvWithAi(string $cvText): array
    {
        try {
            $apiKey = config('services.openrouter.key');
            
            if (empty($apiKey)) {
                throw new \Exception("Clé API OpenRouter non configurée");
            }

            // Construire le prompt pour l'IA
            $prompt = "
            Analysez le CV suivant et fournissez:
            1. Les compétences détectées sous forme de liste pas de messages seulement un format seulement deux catégories Techniques et Organisationnelles ainsi que les expériences .
            CV:
            {$cvText}
            ";

            // Appel à l'API OpenRouter
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json'
            ])->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => 'deepseek/deepseek-r1:free',
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ]
            ]);

            if ($response->successful()) {
                $content = $response->json('choices.0.message.content');
                
                // Extraire et structurer les différentes parties
                $summary = $content;
                $skills = [];
                $parsedJson = $this->parseJsonFromText($content);
                // Vérifier si on peut extraire une liste de compétences
                preg_match('/comp[ée]tences.*?:\s*(.*?)(?:\n\n|\n[0-9]|\z)/is', $content, $skillsMatch);
                if (!empty($skillsMatch[1])) {
                    $skillsText = $skillsMatch[1];
                    // Extraire les compétences individuelles
                    preg_match_all('/[-•*]\s*([^,\n]+)/', $skillsText, $matches);
                    if (!empty($matches[1])) {
                        $skills = array_map('trim', $matches[1]);
                    } else {
                        // Essayer de diviser par virgules ou espaces si pas de puces
                        $skills = preg_split('/[,;]/', $skillsText);
                        $skills = array_map('trim', $skills);
                    }
                }

                return [
                    'resume' => $content,
                    'competences' => [
                        'techniques' => $parsedJson['Compétences Techniques'] ?? [],
                        'organisationnelles' => $parsedJson['Compétences Organisationnelles'] ?? []
                    ]
                ];
                \Log::info('Résumé du CV généré avec succès', [
                    'etudiant_id' => $etudiant->id,
                    'resume' => $summary,
                    'competences' => $skills
                ]);
            } else {
                throw new \Exception("Erreur API: " . $response->body());
            }
        } catch (\Exception $e) {
            Log::error('Erreur lors du résumé du CV avec l\'IA', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'resume' => 'Impossible de générer un résumé automatique.',
                'competences' => []
            ];
        }
    }

    /**
     * Traiter le CV d'un étudiant
     */
    public function processCv(Etudiant $etudiant): ?CvResume
    {
        if (empty($etudiant->cv_file)) {
            Log::info("Aucun CV disponible pour l'étudiant #{$etudiant->id}");
            return null;
        }

        try {
            // Extraire le texte du PDF
            $cvText = $this->extractTextFromPdf($etudiant->cv_file);
            
            if (empty($cvText)) {
                throw new \Exception("Impossible d'extraire le texte du CV");
            }

            // Résumer le CV avec l'IA
            $aiResult = $this->summarizeCvWithAi($cvText);
            
            // Créer ou mettre à jour l'entrée dans la table cv_resumes
            $cvResume = CvResume::updateOrCreate(
                ['etudiant_id' => $etudiant->id],
                [
                    'cv_text' => $cvText,
                    'resume' => $aiResult['resume'],
                    'competences_detectees' => json_encode($aiResult['competences']),
                    'is_processed' => true,
                    'processed_at' => Carbon::now()
                ]
            );

            return $cvResume;
        } catch (\Exception $e) {
            Log::error('Erreur lors du traitement du CV', [
                'etudiant_id' => $etudiant->id,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }
}