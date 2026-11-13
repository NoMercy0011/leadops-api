<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Prospect;
use App\Support\ContactNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Import CSV de prospects.
 *
 * Deux partis pris structurants :
 *
 * 1. **Le quota est vérifié sur le volume total, en amont de la boucle.** Le
 *    contrôler ligne par ligne accepterait un import partiel, laissant
 *    l'utilisateur avec un fichier à moitié chargé et aucun moyen simple de
 *    savoir où reprendre.
 * 2. **Une ligne invalide n'annule pas l'import.** Un fichier de plusieurs
 *    milliers de lignes contient presque toujours quelques erreurs de saisie ;
 *    tout rejeter pour une adresse mal formée rendrait la fonction
 *    inutilisable. Les lignes fautives sont renvoyées avec leur numéro et leur
 *    motif, pour correction et réimport.
 */
final class ProspectImporter
{
    /**
     * Au-delà, l'import devra passer par une file d'attente — noté au §9 du
     * plan. La borne est ici pour que le dépassement soit un refus explicite
     * plutôt qu'un délai d'exécution dépassé.
     */
    private const MAX_ROWS = 5000;

    /**
     * Colonnes reconnues, et leurs libellés acceptés dans l'en-tête.
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'first_name' => ['prenom', 'prénom', 'first_name', 'firstname'],
        'last_name' => ['nom', 'last_name', 'lastname'],
        'company_name' => ['societe', 'société', 'entreprise', 'company'],
        'phone' => ['telephone', 'téléphone', 'phone', 'tel'],
        'email' => ['email', 'mail', 'courriel'],
        'address' => ['adresse', 'address'],
        'source' => ['source', 'origine'],
        'notes' => ['notes', 'note', 'commentaire'],
    ];

    public function __construct(
        private readonly ProspectService $prospects,
        private readonly SubscriptionLimitService $limits,
    ) {}

    /**
     * @return array{imported: int, skipped: int, errors: list<array{line: int, message: string}>}
     */
    public function import(Project $project, UploadedFile $file): array
    {
        $lignes = $this->readRows($file);

        if ($lignes === []) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => [
                ['line' => 0, 'message' => 'Le fichier est vide ou son en-tête est illisible.'],
            ]];
        }

        if (count($lignes) > self::MAX_ROWS) {
            return ['imported' => 0, 'skipped' => count($lignes), 'errors' => [
                ['line' => 0, 'message' => sprintf(
                    'Le fichier contient %d lignes ; la limite est de %d par import.',
                    count($lignes),
                    self::MAX_ROWS,
                )],
            ]];
        }

        // Quota vérifié sur le volume total avant toute insertion.
        $this->limits->assertCanCreate($project->company, 'prospects', count($lignes));

        $pays = $project->company->default_country_code;
        $etape = $project->stages()->orderBy('position')->firstOrFail();

        $importes = 0;
        $ignores = 0;
        $erreurs = [];

        foreach ($lignes as $index => $ligne) {
            // +2 : l'en-tête occupe la première ligne, et les humains comptent
            // à partir de 1. Le numéro affiché doit correspondre à ce que
            // l'utilisateur voit dans son tableur.
            $numero = $index + 2;

            $validation = Validator::make($ligne, [
                'first_name' => ['required', 'string', 'max:255'],
                'last_name' => ['nullable', 'string', 'max:255'],
                'company_name' => ['nullable', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                'email' => ['nullable', 'email', 'max:255'],
                'address' => ['nullable', 'string', 'max:255'],
                'source' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string', 'max:5000'],
            ]);

            if ($validation->fails()) {
                $erreurs[] = ['line' => $numero, 'message' => $validation->errors()->first()];
                $ignores++;

                continue;
            }

            $donnees = $validation->validated();

            // Doublon strict dans le projet : même numéro *et* même adresse
            // vides ne comptent pas. On ignore la ligne plutôt que de créer un
            // second exemplaire, en le signalant pour que l'utilisateur sache
            // pourquoi son compte final ne correspond pas à son fichier.
            if ($this->isDuplicate($project, $donnees, $pays)) {
                $erreurs[] = ['line' => $numero, 'message' => 'Doublon dans ce projet, ligne ignorée.'];
                $ignores++;

                continue;
            }

            DB::transaction(function () use ($project, $donnees, $etape, &$importes) {
                $this->prospects->create($project, [...$donnees, 'stage_id' => $etape->id], imported: true);
                $importes++;
            });
        }

        return ['imported' => $importes, 'skipped' => $ignores, 'errors' => $erreurs];
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    private function isDuplicate(Project $project, array $donnees, string $pays): bool
    {
        $telephone = ContactNormalizer::phone($donnees['phone'] ?? null, $pays);
        $email = ContactNormalizer::email($donnees['email'] ?? null);

        if ($telephone === null && $email === null) {
            // Sans coordonnée exploitable, rien ne permet de rapprocher : on
            // laisse passer plutôt que de rejeter arbitrairement.
            return false;
        }

        // Archivés compris (lot 9), comme à la création unitaire.
        return Prospect::query()
            ->withArchived()
            ->potentialDuplicatesOf($project->id, $telephone, $email)
            ->exists();
    }

    /**
     * Lit le fichier et projette chaque ligne sur les colonnes reconnues.
     *
     * @return list<array<string, string|null>>
     */
    private function readRows(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return [];
        }

        // Le BOM UTF-8 qu'Excel ajoute se collerait au premier nom de colonne
        // et rendrait l'en-tête méconnaissable.
        $premier = fgets($handle);
        rewind($handle);

        if ($premier !== false && str_starts_with($premier, "\xEF\xBB\xBF")) {
            fseek($handle, 3);
        }

        $separateur = $this->detectSeparator($premier ?: '');

        // Le séparateur détecté doit être passé ici aussi : sans lui, un
        // en-tête en point-virgule — ce qu'exporte Excel francophone — est lu
        // comme une colonne unique, aucune correspondance n'est trouvée, et
        // l'import se solde par zéro ligne sans erreur explicite.
        $entete = fgetcsv($handle, separator: $separateur, escape: '');

        if ($entete === false) {
            fclose($handle);

            return [];
        }

        $mapping = $this->mapHeader($entete);

        if (! isset($mapping['first_name'])) {
            fclose($handle);

            return [];
        }

        $lignes = [];

        while (($colonnes = fgetcsv($handle, separator: $separateur, escape: '')) !== false) {
            // Ligne entièrement vide : les tableurs en produisent en fin de
            // fichier, elles ne doivent pas compter comme des erreurs.
            if ($colonnes === [null] || implode('', array_map('strval', $colonnes)) === '') {
                continue;
            }

            $ligne = [];

            foreach ($mapping as $champ => $position) {
                $valeur = trim((string) ($colonnes[$position] ?? ''));
                $ligne[$champ] = $valeur === '' ? null : $valeur;
            }

            $lignes[] = $ligne;
        }

        fclose($handle);

        return $lignes;
    }

    /**
     * Excel francophone exporte en point-virgule, la plupart des autres outils
     * en virgule. Deviner évite un import vide et incompréhensible.
     */
    private function detectSeparator(string $entete): string
    {
        return substr_count($entete, ';') > substr_count($entete, ',') ? ';' : ',';
    }

    /**
     * @param  list<string|null>  $entete
     * @return array<string, int>
     */
    private function mapHeader(array $entete): array
    {
        $mapping = [];

        foreach ($entete as $position => $libelle) {
            $normalise = mb_strtolower(trim((string) $libelle));

            foreach (self::COLUMNS as $champ => $alias) {
                if (in_array($normalise, $alias, true)) {
                    $mapping[$champ] = $position;
                    break;
                }
            }
        }

        return $mapping;
    }
}
