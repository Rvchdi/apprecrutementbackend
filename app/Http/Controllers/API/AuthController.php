<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Etudiant;
use App\Models\Entreprise;
use App\Models\Competence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Str;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\URL;
use Carbon\Carbon;

class AuthController extends Controller
{
    /**
     * Enregistrer un nouvel utilisateur
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        \Log::info('Données reçues pour l\'inscription:', $request->all());
        // Validation des données de base requises pour tous les types d'utilisateurs
        $validator = Validator::make($request->all(), [
            'nom' => 'required|string|max:255',
            'prenom' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|in:etudiant,entreprise',
            'telephone' => 'required|string|max:20',
            'ville' => 'required|string|max:255',
        ]);
    
        if ($validator->fails()) {
            \Log::warning('Échec de validation de base:', $validator->errors()->toArray());
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
    
        // Validation spécifique selon le rôle
        if ($request->role === 'etudiant') {
            $validatorEtudiant = Validator::make($request->all(), [
                'date_naissance' => 'required|date',
                'niveau_etude' => 'required|string|max:255',
                'filiere' => 'required|string|max:255',
                'ecole' => 'required|string|max:255',
                'annee_diplome' => 'nullable|integer',
                'disponibilite' => 'required|in:immédiate,1_mois,3_mois,6_mois',
                'cv_file' => 'nullable|file|mimes:pdf|max:5120',
                'competences' => 'nullable', // Assurez-vous que la validation est permissive
            ]);
    
            if ($validatorEtudiant->fails()) {
                \Log::warning('Échec de validation étudiant:', $validatorEtudiant->errors()->toArray());
                return response()->json([
                    'message' => 'Données étudiant invalides',
                    'errors' => $validatorEtudiant->errors()
                ], 422);
            }
        } else if ($request->role === 'entreprise') {
            $validatorEntreprise = Validator::make($request->all(), [
                'nom_entreprise' => 'required|string|max:255',
                'secteur_activite' => 'required|string|max:255',
                'taille' => 'nullable|string|max:50',
                'site_web' => 'nullable|url|max:255',
                'description' => 'nullable|string',
            ]);

            if ($validatorEntreprise->fails()) {
                return response()->json([
                    'message' => 'Données entreprise invalides',
                    'errors' => $validatorEntreprise->errors()
                ], 422);
            }
        }

        // Tout est valide, on démarre une transaction
        DB::beginTransaction();

        try {
            // Créer l'utilisateur
            $user = User::create([
                'nom' => $request->nom,
                'prenom' => $request->prenom,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'telephone' => $request->telephone,
                'role' => $request->role,
                'email_verified_at' => null,
            ]);

            // Créer le profil spécifique en fonction du rôle
            if ($request->role === 'etudiant') {
                // Gérer le fichier CV s'il est présent
                $cvPath = null;
                if ($request->hasFile('cv_file')) {
                    $cvPath = $request->file('cv_file')->store('cv_files', 'public');
                }

                // Créer le profil étudiant
                $etudiant = Etudiant::create([
                    'user_id' => $user->id,
                    'date_naissance' => $request->date_naissance,
                    'niveau_etude' => $request->niveau_etude,
                    'filiere' => $request->filiere,
                    'ecole' => $request->ecole,
                    'annee_diplome' => $request->annee_diplome,
                    'disponibilite' => $request->disponibilite,
                    'cv_file' => $cvPath,
                    'adresse' => $request->adresse,
                    'ville' => $request->ville,
                    'code_postal' => $request->code_postal,
                    'pays' => $request->pays,
                ]);

                // Traiter les compétences si elles sont fournies
                if ($request->has('competences') && !empty($request->competences)) {
                    $competencesArray = is_string($request->competences) 
                        ? explode(',', $request->competences) 
                        : json_decode($request->competences);
                    
                    foreach ($competencesArray as $comp) {
                        $comp = trim($comp);
                        if (!empty($comp)) {
                            // Trouver ou créer la compétence
                            $competence = Competence::firstOrCreate(['nom' => $comp]);
                            
                            // Associer à l'étudiant avec niveau débutant par défaut
                            $etudiant->competences()->attach($competence->id, ['niveau' => 'débutant']);
                        }
                    }
                }

                // Analyser le CV avec l'IA si un CV a été téléchargé
                if ($cvPath) {
                    // TODO: Logique d'analyse de CV avec l'IA
                    // $this->analyzeCV($etudiant, $cvPath);
                }
            } elseif ($request->role === 'entreprise') {
                // Créer le profil entreprise
                $entreprise = Entreprise::create([
                    'user_id' => $user->id,
                    'nom_entreprise' => $request->nom_entreprise,
                    'description' => $request->description,
                    'secteur_activite' => $request->secteur_activite,
                    'taille' => $request->taille,
                    'site_web' => $request->site_web,
                    'adresse' => $request->adresse,
                    'ville' => $request->ville,
                    'code_postal' => $request->code_postal,
                    'pays' => $request->pays,
                    'est_verifie' => false, // Par défaut, les entreprises ne sont pas vérifiées
                ]);
            }

                // Générer un token de vérification pour l'email
                $verificationToken = $this->generateVerificationToken($user);

                // Ajouter des logs pour le débogage
                \Log::info('Envoi de l\'email de vérification à ' . $user->email . ' avec le token ' . $verificationToken);

                try {
                    // Envoyer l'email de vérification
                    $user->notify(new VerifyEmailNotification($verificationToken));
                    \Log::info('Email de vérification envoyé avec succès');
                } catch (\Exception $e) {
                    \Log::error('Erreur lors de l\'envoi de l\'email de vérification: ' . $e->getMessage());
                }

                DB::commit();

                // Générer un token API pour la session
                $token = $user->createToken('auth_token')->plainTextToken;

                // Pour faciliter les tests, inclure le token de vérification dans la réponse
                return response()->json([
                    'message' => 'Utilisateur créé avec succès. Veuillez vérifier votre adresse email.',
                    'user' => $user,
                    'token' => $token,
                    'token_type' => 'Bearer',
                    'verification_token' => $verificationToken // Uniquement pour les tests, à supprimer en production
                ], 201);
        } catch (\Exception $e) {
            \Log::error('Erreur : ' . $e->getMessage(), [
                'exception' => $e
            ]);
        
            DB::rollBack();
            
            // En cas d'erreur, supprimer les fichiers uploadés si existants
            if (isset($cvPath) && $cvPath && Storage::disk('public')->exists($cvPath)) {
                Storage::disk('public')->delete($cvPath);
            }

            return response()->json([
                'message' => 'Une erreur est survenue lors de l\'inscription',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Générer un token de vérification pour l'email
     * 
     * @param User $user
     * @return string
     */
    private function generateVerificationToken(User $user)
    {
        $token = Str::random(60);
        
        // Stocker le token dans la base de données
        DB::table('verification_tokens')->insert([
            'user_id' => $user->id,
            'token' => $token,
            'created_at' => now()
        ]);
        
        return $token;
    }

    /**
     * Connecter un utilisateur
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
    // Journaliser les données reçues pour le débogage
    \Log::info('Tentative de connexion:', ['email' => $request->email]);
    
    $validator = Validator::make($request->all(), [
        'email' => 'required|string|email',
        'password' => 'required|string',
    ]);
    
    if ($validator->fails()) {
        return response()->json([
            'message' => 'Veuillez vérifier vos identifiants',
            'errors' => $validator->errors()
        ], 422);
    }
    
    // Vérifier les identifiants
    $user = User::where('email', $request->email)->first();
    
    if (!$user || !Hash::check($request->password, $user->password)) {
        return response()->json([
            'message' => 'Email ou mot de passe incorrect'
        ], 401);
    }
    
    // Vérifier si l'email a été vérifié
    if ($user->email_verified_at === null) {
        // Créer un token pour la session mais avertir que l'email n'est pas vérifié
        $token = $user->createToken('auth_token')->plainTextToken;
        
        return response()->json([
            'message' => 'Connexion réussie, mais votre adresse email n\'a pas été vérifiée.',
            'email_verified' => false,
            'user' => [
                'id' => $user->id,
                'nom' => $user->nom,
                'prenom' => $user->prenom,
                'email' => $user->email,
                'role' => $user->role
            ],
            'token' => $token,
            'token_type' => 'Bearer'
        ], 200);
    }
    
    // Créer un token pour l'utilisateur
    $token = $user->createToken('auth_token')->plainTextToken;
    // Mettre à jour la date de dernière connexion
    $user->last_login_at = now();
    $user->last_login_ip = $request->ip();
    $user->save();
    
    // Ajouter les informations spécifiques selon le rôle
    if ($user->role === 'etudiant') {
        $user->load('etudiant');
    } elseif ($user->role === 'entreprise') {
        $user->load('entreprise');
    }
    
    // Structure de réponse adaptée au frontend React
    return response()->json([
        'message' => 'Connexion réussie',
        'email_verified' => true,
        'user' => [
            'id' => $user->id,
            'nom' => $user->nom,
            'prenom' => $user->prenom,
            'email' => $user->email,
            'role' => $user->role,
            'profile' => $user->role === 'etudiant' ? $user->etudiant : 
                        ($user->role === 'entreprise' ? $user->entreprise : null)
        ],
        'token' => $token,
        'token_type' => 'Bearer'
    ]);
    }

    /**
     * Déconnecter un utilisateur
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Déconnexion réussie'
        ]);
    }

    /**
     * Récupérer les informations de l'utilisateur actuellement connecté
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(Request $request)
    {
        $user = $request->user();

        // Charger les informations supplémentaires en fonction du rôle
        if ($user->role === 'etudiant') {
            $user->load(['etudiant', 'etudiant.competences']);
        } elseif ($user->role === 'entreprise') {
            $user->load('entreprise');
        }

        return response()->json([
            'user' => $user,
            'email_verified' => $user->email_verified_at !== null
        ]);
    }

    /**
     * Vérifier l'email d'un utilisateur
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Token de vérification invalide',
                'errors' => $validator->errors()
            ], 422);
        }

        // Rechercher le token dans la base de données
        $verificationData = DB::table('verification_tokens')
            ->where('token', $request->token)
            ->first();

        if (!$verificationData) {
            return response()->json([
                'message' => 'Lien de vérification invalide ou expiré'
            ], 400);
        }

        // Vérifier que le token n'est pas expiré (24h)
        $tokenCreatedAt = Carbon::parse($verificationData->created_at);
        if (now()->diffInHours($tokenCreatedAt) > 24) {
            // Supprimer le token expiré
            DB::table('verification_tokens')->where('token', $request->token)->delete();
            
            return response()->json([
                'message' => 'Lien de vérification expiré. Veuillez demander un nouveau lien.'
            ], 400);
        }

        // Récupérer l'utilisateur associé au token
        $user = User::find($verificationData->user_id);
        
        if (!$user) {
            return response()->json([
                'message' => 'Utilisateur non trouvé'
            ], 404);
        }

        // Si l'email est déjà vérifié
        if ($user->email_verified_at !== null) {
            return response()->json([
                'message' => 'Email déjà vérifié'
            ]);
        }

        // Marquer l'email comme vérifié
        $user->email_verified_at = now();
        $user->save();

        // Supprimer le token après vérification
        DB::table('verification_tokens')->where('token', $request->token)->delete();

        return response()->json([
            'message' => 'Votre adresse email a été vérifiée avec succès'
        ]);
    }

    /**
     * Renvoyer un email de vérification
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resendVerificationEmail(Request $request)
    {
        $user = $request->user();

        // Vérifier si l'email est déjà vérifié
        if ($user->email_verified_at !== null) {
            return response()->json([
                'message' => 'Votre adresse email est déjà vérifiée'
            ]);
        }

        // Supprimer les anciens tokens pour cet utilisateur
        DB::table('verification_tokens')->where('user_id', $user->id)->delete();

        // Générer un nouveau token
        $verificationToken = $this->generateVerificationToken($user);

        // Envoyer l'email de vérification
        $user->notify(new VerifyEmailNotification($verificationToken));

        return response()->json([
            'message' => 'Un nouvel email de vérification a été envoyé'
        ]);
    }

    /**
     * Demander un lien de réinitialisation de mot de passe
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|exists:users',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Adresse email non trouvée',
                'errors' => $validator->errors()
            ], 422);
        }

        // Générer un token de réinitialisation
        $token = Str::random(60);

        // Stocker le token dans la table password_resets
        DB::table('password_resets')->updateOrInsert(
            ['email' => $request->email],
            [
                'token' => Hash::make($token),
                'created_at' => now()
            ]
        );

        // Récupérer l'utilisateur pour envoyer l'email
        $user = User::where('email', $request->email)->first();
        
        // TODO: Créer et envoyer une notification pour la réinitialisation du mot de passe
        // $user->notify(new ResetPasswordNotification($token));

        return response()->json([
            'message' => 'Lien de réinitialisation envoyé par email'
        ]);
    }

    /**
     * Réinitialiser le mot de passe
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|exists:users',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }

        // Vérifier le token
        $tokenData = DB::table('password_resets')
            ->where('email', $request->email)
            ->first();

        if (!$tokenData || !Hash::check($request->token, $tokenData->token)) {
            return response()->json([
                'message' => 'Token invalide ou expiré'
            ], 401);
        }

        // Vérifier que le token n'est pas expiré (24h)
        $tokenCreatedAt = Carbon::parse($tokenData->created_at);
        if (now()->diffInHours($tokenCreatedAt) > 24) {
            return response()->json([
                'message' => 'Token expiré'
            ], 401);
        }

        // Mettre à jour le mot de passe
        $user = User::where('email', $request->email)->first();
        $user->password = Hash::make($request->password);
        $user->save();

        // Supprimer le token
        DB::table('password_resets')->where('email', $request->email)->delete();

        return response()->json([
            'message' => 'Mot de passe réinitialisé avec succès'
        ]);
    }

    /**
     * Analyser le CV d'un étudiant avec l'IA
     * 
     * @param  \App\Models\Etudiant  $etudiant
     * @param  string  $cvPath
     * @return void
     */
    private function analyzeCV($etudiant, $cvPath)
    {
        // TODO: Implémenter l'analyse du CV avec l'API IA
        // Ceci est une implémentation fictive qui devra être remplacée
        
        // Extraction du texte du CV
        // $cvText = $this->extractTextFromCV($cvPath);
        
        // Analyse du CV avec l'API IA
        // $analysis = $this->callAIService($cvText);
        
        // Génération du résumé
        // $resume = $analysis['resume'] ?? 'Résumé non disponible';
        
        // Mise à jour du profil étudiant
        // $etudiant->cv_resume = $resume;
        // $etudiant->save();
        
        // Ajout des compétences détectées
        // if (isset($analysis['skills']) && is_array($analysis['skills'])) {
        //     foreach ($analysis['skills'] as $skillName => $level) {
        //         $competence = Competence::firstOrCreate(['nom' => $skillName]);
        //         
        //         // Convertir le niveau détecté au format attendu
        //         $mappedLevel = $this->mapSkillLevel($level);
        //         
        //         // Associer à l'étudiant
        //         $etudiant->competences()->syncWithoutDetaching([
        //             $competence->id => ['niveau' => $mappedLevel]
        //         ]);
        //     }
        // }
    }
}