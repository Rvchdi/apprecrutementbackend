# Backend de la Plateforme de Recrutement avec IA

Ce document présente l'architecture et les fonctionnalités du backend de notre plateforme de recrutement, qui connecte les étudiants aux entreprises et intègre des fonctionnalités d'IA pour l'analyse des CV.

## Technologies utilisées

- **Framework**: Laravel 10 (PHP 8.2)
- **Base de données**: MySQL 8.0
- **Authentification**: Laravel Sanctum
- **Intégration IA**: API OpenAI

## Structure de la base de données

La base de données est organisée autour de plusieurs entités principales:

### Utilisateurs et Profils

- **USERS**: Table centrale qui gère tous les utilisateurs (admin, étudiants, entreprises)
- **ETUDIANTS**: Profils spécifiques pour les étudiants cherchant des opportunités
- **ENTREPRISES**: Profils pour les entreprises qui publient des offres

### Offres et Candidatures

- **OFFRES**: Opportunités publiées par les entreprises
- **CANDIDATURES**: Demandes des étudiants pour des offres spécifiques
- **COMPETENCES**: Compétences techniques et professionnelles
- **ETUDIANT_COMPETENCES**: Association entre étudiants et compétences
- **OFFRE_COMPETENCES**: Compétences requises pour une offre

### Système de Tests

- **TESTS**: Tests QCM associés aux offres d'emploi
- **QUESTIONS**: Questions incluses dans les tests
- **REPONSES**: Options pour chaque question
- **REPONSES_ETUDIANTS**: Réponses fournies par les étudiants

### Notifications

- **NOTIFICATIONS**: Système de notifications pour tous les utilisateurs

## API RESTful

Le backend expose une API RESTful sécurisée avec les endpoints suivants:

### Authentification

- `POST /api/register`: Inscription (étudiants ou entreprises)
- `POST /api/login`: Connexion
- `POST /api/logout`: Déconnexion
- `GET /api/user`: Récupération des informations de l'utilisateur connecté

### Gestion des profils

- `GET|PUT /api/etudiants/{id}`: Récupération et mise à jour du profil étudiant
- `GET|PUT /api/entreprises/{id}`: Récupération et mise à jour du profil entreprise
- `POST /api/upload/cv`: Upload de CV pour les étudiants

### Offres et candidatures

- `GET /api/offres`: Liste des offres avec filtrage
- `POST /api/offres`: Création d'une nouvelle offre (entreprises)
- `GET|PUT|DELETE /api/offres/{id}`: Gestion d'une offre spécifique
- `POST /api/candidatures`: Soumission d'une candidature (étudiants)
- `GET|PUT /api/candidatures/{id}`: Gestion d'une candidature

### Système de tests

- `POST /api/tests`: Création d'un test (entreprises)
- `GET /api/tests/{id}`: Récupération des détails d'un test
- `POST /api/tests/{id}/submit`: Soumission d'un test complété (étudiants)
- `GET /api/tests/{id}/results`: Récupération des résultats d'un test

### Compétences

- `GET /api/competences`: Liste de toutes les compétences
- `GET /api/etudiants/{id}/competences`: Compétences d'un étudiant
- `PUT /api/etudiants/{id}/competences`: Mise à jour des compétences d'un étudiant

### Administration

- `GET /api/admin/users`: Gestion des utilisateurs (admin uniquement)
- `PUT /api/admin/entreprises/{id}/verify`: Vérification d'une entreprise
- `GET /api/admin/statistics`: Statistiques de la plateforme

## Intégration de l'IA

### Analyse de CV

Le backend intègre un service d'analyse de CV qui utilise l'API OpenAI pour:

1. Extraire le texte des CV (formats PDF, DOCX)
2. Analyser le texte pour identifier les compétences clés
3. Générer un résumé professionnel pour le profil de l'étudiant

```php
// Exemple de service d'analyse de CV
class CvAnalysisService
{
    protected $openai;
    
    public function __construct()
    {
        $this->openai = OpenAI::client(config('services.openai.api_key'));
    }
    
    public function analyzeCV($cvText)
    {
        // Appel à l'API OpenAI pour analyser le CV
        $response = $this->openai->chat()->create([
            'model' => 'gpt-4',
            'messages' => [
                ['role' => 'system', 'content' => 'Analysez ce CV et extrayez les compétences principales.'],
                ['role' => 'user', 'content' => $cvText]
            ],
        ]);
        
        return $response->choices[0]->message->content;
    }
    
    public function generateSummary($cvText)
    {
        // Génération d'un résumé professionnel
        $response = $this->openai->chat()->create([
            'model' => 'gpt-4',
            'messages' => [
                ['role' => 'system', 'content' => 'Créez un résumé professionnel concis à partir de ce CV.'],
                ['role' => 'user', 'content' => $cvText]
            ],
        ]);
        
        return $response->choices[0]->message->content;
    }
}
```

### Matching Intelligent

Un service de matching qui utilise les compétences et préférences des étudiants pour recommander des offres pertinentes:

```php
class MatchingService
{
    public function getMatchingOffres(Etudiant $etudiant, $limit = 10)
    {
        // Récupérer les compétences de l'étudiant
        $competencesIds = $etudiant->competences->pluck('id')->toArray();
        
        // Trouver les offres qui correspondent aux compétences
        $offres = Offre::whereHas('competences', function($query) use ($competencesIds) {
                $query->whereIn('competence_id', $competencesIds);
            })
            ->where('niveau_requis', '<=', $etudiant->niveau_etude)
            ->where('statut', 'active')
            ->withCount(['competences' => function($query) use ($competencesIds) {
                $query->whereIn('competence_id', $competencesIds);
            }])
            ->orderByDesc('competences_count')
            ->limit($limit)
            ->get();
            
        return $offres;
    }
}
```

## Sécurité

Le backend implémente plusieurs couches de sécurité:

- **Authentification**: Laravel Sanctum pour l'authentification API par token
- **Autorisation**: Middlewares et policies pour restreindre l'accès aux ressources
- **Validation**: Validation stricte des données entrantes
- **Protection CSRF**: Pour les endpoints sensibles
- **Rate Limiting**: Limitation des requêtes pour prévenir les abus

Exemple de middleware d'autorisation:

```php
class EnsureUserHasRole
{
    public function handle($request, Closure $next, $role)
    {
        if (!$request->user() || $request->user()->role !== $role) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        return $next($request);
    }
}
```

## Installation et configuration

1. Cloner le dépôt:
   ```bash
   git clone [url-du-repo]
   cd recrutement-app
   ```

2. Installer les dépendances:
   ```bash
   composer install
   ```

3. Configurer l'environnement:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. Configurer la base de données dans le fichier `.env`

5. Exécuter les migrations et seeders:
   ```bash
   php artisan migrate --seed
   ```

6. Configurer l'API OpenAI dans le fichier `.env`:
   ```
   OPENAI_API_KEY=votre-clé-api
   ```

7. Démarrer le serveur:
   ```bash
   php artisan serve
   ```

## Tests

Exécuter les tests avec PHPUnit:

```bash
php artisan test
```

## Documentation API

Une documentation Swagger complète de l'API est disponible à l'adresse:

```
/api/documentation
```

## Mise en production

Pour le déploiement en production, assurez-vous de:

1. Configurer un serveur web (Nginx recommandé) avec PHP-FPM
2. Activer les optimisations Laravel:
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
3. Configurer les queues pour les tâches asynchrones:
   ```bash
   php artisan queue:work
   ```