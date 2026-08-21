<?php

use App\Http\Controllers\Api\V1\Admin\CompanyController;
use App\Http\Controllers\Api\V1\Admin\CompanyStatusController;
use App\Http\Controllers\Api\V1\Admin\CompanyUsageController;
use App\Http\Controllers\Api\V1\Admin\PlanController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\DataQualityController;
use App\Http\Controllers\Api\V1\PipelineHealthController;
use App\Http\Controllers\Api\V1\PipelineStageController;
use App\Http\Controllers\Api\V1\ProjectActivityController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectMemberController;
use App\Http\Controllers\Api\V1\ProspectActionController;
use App\Http\Controllers\Api\V1\ProspectAnswerController;
use App\Http\Controllers\Api\V1\ProspectController;
use App\Http\Controllers\Api\V1\ProspectImportController;
use App\Http\Controllers\Api\V1\QuestionController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SubscriptionOverviewController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VariantComparisonController;
use App\Http\Controllers\Api\V1\VariantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Trois groupes, et les frontières entre eux portent les invariants :
|
| - `auth` : les rares routes sans jeton. Toute route ajoutée ici doit être
|   justifiée — le cloisonnement par entreprise ne s'y applique pas.
| - `admin` : back-office Super Admin, seul endroit qui sort du cloisonnement.
| - l'espace entreprise, cloisonné par CompanyScope et bridé en écriture par
|   `subscription.writes` quand l'abonnement ne le permet plus.
|
| RouteProtectionTest vérifie qu'aucune route ne sort de ce cadre par accident.
|
*/

// `throttle:api` couvre tout le préfixe. Les groupes plus stricts — écritures,
// opérations lourdes — s'y ajoutent plus bas : Laravel applique alors les deux
// limiteurs, et c'est le plus restrictif qui tranche.
Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('login', [LoginController::class, 'store'])->name('auth.login');
        Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])->name('auth.forgot-password');
        Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('auth.reset-password');
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', LogoutController::class)->name('auth.logout');
        Route::get('me', MeController::class)->name('me');

        // Délibérément hors du groupe `subscription.writes` : c'est quand
        // l'abonnement est suspendu ou expiré que cet écran doit répondre.
        Route::get('subscription', SubscriptionOverviewController::class)->name('subscription');

        // Back-office — hors cloisonnement, doublement gardé : middleware de
        // groupe et Policies au niveau de chaque objet.
        Route::prefix('admin')->middleware('super-admin')->name('admin.')->group(function () {
            Route::get('plans', [PlanController::class, 'index'])->name('plans.index');

            Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
            Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');
            Route::get('companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
            Route::patch('companies/{company}', [CompanyController::class, 'update'])->name('companies.update');

            Route::post('companies/{company}/suspend', [CompanyStatusController::class, 'suspend'])->name('companies.suspend');
            Route::post('companies/{company}/activate', [CompanyStatusController::class, 'activate'])->name('companies.activate');

            Route::get('companies/{company}/usage', CompanyUsageController::class)->name('companies.usage');
            Route::patch('companies/{company}/subscription', [SubscriptionController::class, 'update'])->name('companies.subscription.update');
        });

        // Espace entreprise. `subscription.writes` laisse passer les lectures
        // et bloque les écritures d'un abonnement suspendu ou expiré.
        Route::middleware('subscription.writes')->group(function () {
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::post('users', [UserController::class, 'store'])->name('users.store');

            // Variantes — vocabulaire de reporting propre à l'entreprise.
            Route::get('variants', [VariantController::class, 'index'])->name('variants.index');
            Route::post('variants', [VariantController::class, 'store'])->name('variants.store');
            Route::patch('variants/{variant}', [VariantController::class, 'update'])->name('variants.update');
            Route::delete('variants/{variant}', [VariantController::class, 'destroy'])->name('variants.destroy');

            Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
            Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
            Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
            Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
            // Pas de route de suppression : un projet s'archive, il ne se
            // supprime pas. Le pourquoi est dans ProjectController.

            Route::get('projects/{project}/pipeline-health', PipelineHealthController::class)
                ->name('projects.pipeline-health');
            Route::get('projects/{project}/data-quality', DataQualityController::class)
                ->name('projects.data-quality');
            Route::get('projects/{project}/activities', ProjectActivityController::class)
                ->name('projects.activities');
            Route::get('projects/{project}/variant-comparison', VariantComparisonController::class)
                ->name('projects.variant-comparison');

            Route::get('projects/{project}/members', [ProjectMemberController::class, 'index'])->name('projects.members.index');
            Route::put('projects/{project}/members', [ProjectMemberController::class, 'sync'])->name('projects.members.sync');

            // Étapes de pipeline. Le réordonnancement est déclaré avant la
            // route paramétrée sur {stage} : sans cela « reorder » serait
            // capturé comme un identifiant d'étape.
            Route::get('projects/{project}/stages', [PipelineStageController::class, 'index'])->name('projects.stages.index');
            Route::post('projects/{project}/stages', [PipelineStageController::class, 'store'])->name('projects.stages.store');
            Route::patch('projects/{project}/stages/reorder', [PipelineStageController::class, 'reorder'])->name('projects.stages.reorder');
            Route::patch('stages/{stage}', [PipelineStageController::class, 'update'])->name('stages.update');
            Route::delete('stages/{stage}', [PipelineStageController::class, 'destroy'])->name('stages.destroy');

            // Prospects. Les routes littérales précèdent celles paramétrées sur
            // {prospect} : sans cela « import », « export », « duplicates » et
            // « bulk-assign » seraient capturés comme des identifiants.
            // Import et export mobilisent la base plusieurs secondes : quelques
            // appels par minute suffisent à l'usage réel, et au-delà c'est un
            // script, pas un humain.
            Route::post('prospects/import', [ProspectImportController::class, 'store'])
                ->middleware('throttle:heavy')->name('prospects.import');
            Route::get('prospects/export', [ProspectImportController::class, 'export'])
                ->middleware('throttle:heavy')->name('prospects.export');
            Route::get('prospects/duplicates', [ProspectController::class, 'duplicates'])->name('prospects.duplicates');
            Route::patch('prospects/bulk-assign', [ProspectActionController::class, 'bulkAssign'])->name('prospects.bulk-assign');

            Route::get('prospects', [ProspectController::class, 'index'])->name('prospects.index');
            Route::post('prospects', [ProspectController::class, 'store'])->name('prospects.store');
            Route::get('prospects/{prospect}', [ProspectController::class, 'show'])->name('prospects.show');
            Route::patch('prospects/{prospect}', [ProspectController::class, 'update'])->name('prospects.update');
            Route::delete('prospects/{prospect}', [ProspectController::class, 'destroy'])->name('prospects.destroy');

            Route::patch('prospects/{prospect}/stage', [ProspectActionController::class, 'changeStage'])->name('prospects.stage');
            Route::patch('prospects/{prospect}/assign', [ProspectActionController::class, 'assign'])->name('prospects.assign');
            Route::patch('prospects/{prospect}/archive', [ProspectActionController::class, 'archive'])->name('prospects.archive');
            Route::patch('prospects/{prospect}/next-action', [ProspectActionController::class, 'planNextAction'])->name('prospects.next-action');
            Route::post('prospects/{prospect}/notes', [ProspectActionController::class, 'addNote'])->name('prospects.notes.store');
            Route::get('prospects/{prospect}/activities', [ProspectActionController::class, 'activities'])->name('prospects.activities');

            // Questionnaire du prospect : définitions et réponses ensemble.
            Route::get('prospects/{prospect}/questionnaire', [ProspectAnswerController::class, 'show'])->name('prospects.questionnaire.show');
            Route::put('prospects/{prospect}/questionnaire', [ProspectAnswerController::class, 'store'])->name('prospects.questionnaire.store');

            // Tableau de bord et rapports. `export` précède la route de
            // listing pour rester lisible, l'ordre n'important pas ici —
            // aucune n'est paramétrée.
            Route::get('dashboard', [ReportController::class, 'dashboard'])->name('dashboard');
            Route::get('reports/export', [ReportController::class, 'export'])
                ->middleware('throttle:heavy')->name('reports.export');
            Route::get('reports', [ReportController::class, 'index'])->name('reports.index');

            // Rendez-vous. `index` accepte une plage de journées locales,
            // convertie en bornes UTC par TimezoneResolver.
            Route::get('appointments', [AppointmentController::class, 'index'])->name('appointments.index');
            Route::post('appointments', [AppointmentController::class, 'store'])->name('appointments.store');
            Route::get('appointments/{appointment}', [AppointmentController::class, 'show'])->name('appointments.show');
            Route::patch('appointments/{appointment}', [AppointmentController::class, 'update'])->name('appointments.update');
            Route::delete('appointments/{appointment}', [AppointmentController::class, 'destroy'])->name('appointments.destroy');

            // Constructeur de questionnaire. `reorder` précède la route
            // paramétrée sur {question}, qui le capturerait sinon.
            Route::get('projects/{project}/questions', [QuestionController::class, 'index'])->name('projects.questions.index');
            Route::post('projects/{project}/questions', [QuestionController::class, 'store'])->name('projects.questions.store');
            Route::patch('projects/{project}/questions/reorder', [QuestionController::class, 'reorder'])->name('projects.questions.reorder');
            Route::patch('questions/{question}', [QuestionController::class, 'update'])->name('questions.update');
            Route::delete('questions/{question}', [QuestionController::class, 'destroy'])->name('questions.destroy');
        });
    });
});
