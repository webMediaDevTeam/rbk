<?php

namespace App\Support;

use App\Models\Employee;

/**
 * Projection « fiche employé » d'un commercial — **partagée** par les deux
 * réponses qui alimentent la modale « Modifier l'employé » :
 *  - les lignes de la page « Employés » (`UserController::formatUser()`) ;
 *  - la fiche d'un employé (`CommercialAdminController::show()`).
 *
 * La forme est donc **identique** dans les deux cas (`profil.{prenom, nom,
 * telephone, entreprise_id, info_supp, ringcentral_*}`) : la modale se
 * pré-remplit depuis l'une ou l'autre réponse sans adaptation, et un champ
 * ajouté ici apparaît automatiquement des deux côtés. C'est le seul endroit où
 * cette forme est écrite — la dupliquer dans un contrôleur fait dériver la
 * modale (champs silencieusement remis à vide à l'enregistrement).
 *
 * Les URL d'avatar / de logo sont produites ici parce que les deux entités les
 * exposent ; le préfixe de stockage (`avatars`, `logos`) est celui de
 * `Storage::disk('public')` lu par les contrôleurs qui téléversent ces fichiers.
 *
 * Elles sont **relatives** (`/storage/…`) et non absolues : une image doit être
 * demandée sur l'origine du navigateur, pas sur celle de l'API. Construite avec
 * `request()->getSchemeAndHttpHost()`, l'URL épinglait le port du backend
 * (`:8000` en dev — où le serveur n'écoutait pas `/storage`, d'où l'avatar
 * cassé) et dépendait des en-têtes transmisses derrière nginx en production.
 * Un chemin relatif traverse l'origine courante : le proxy `server.proxy` de
 * Vite en dev, le `location ^~ /storage/` de nginx en prod.
 */
class EmployeeProfile
{
    /**
     * Profil employé d'un commercial — `null` si le compte n'a pas de fiche
     * `employees` (compte d'avant la table, ou rôle sans fiche).
     *
     * @return array<string, mixed>|null
     */
    public static function forCommercial(?Employee $employee): ?array
    {
        if ($employee === null) {
            return null;
        }

        $enterprise = $employee->enterprise;

        return [
            'id' => $employee->id,
            'prenom' => $employee->first_name,
            'nom' => $employee->last_name,
            'telephone' => $employee->phone,
            'entreprise_id' => $employee->enterprise_id,
            'entreprise_name' => $enterprise?->name,
            'image_dp' => $employee->image_dp,
            'image_dp_url' => self::employeePhotoUrl($employee->image_dp),
            'info_supp' => $employee->additional_info,
            // Source d'appel RingCentral choisie à la création / en édition ;
            // le poste est déduit de l'appareil (`extension_id`).
            'ringcentral_device_id' => $employee->ringcentral_device_id,
            'ringcentral_extension_id' => $employee->ringcentral_extension_id,
            'ringcentral_from_number' => $employee->ringcentral_from_number,
            // Dernière synchronisation de l'annuaire RingCentral pour cet
            // employé : dit si la liste d'appareils proposés est fraîche.
            'ringcentral_synced_at' => $employee->ringcentral_synced_at,
            'entreprise' => $enterprise ? [
                'name' => $enterprise->name,
                'email' => $enterprise->email,
                'tax_number' => $enterprise->tax_number,
                'phone' => $enterprise->phone,
                'address' => $enterprise->address,
                'status' => $enterprise->status,
                // Source de l'entreprise (répertoire `sources`, lu par
                // `GET /api/v1/sources`) — badge dans la liste des employés.
                'source' => $enterprise->source,
                'logo_url' => self::logoUrl($enterprise->logo),
            ] : null,
        ];
    }

    /**
     * Photo de profil d'un employé (`employees.image_dp`), versée dans
     * `storage/avatars`.
     */
    public static function employeePhotoUrl(?string $filename): ?string
    {
        return self::publicUrl('avatars', $filename);
    }

    /**
     * Avatar du compte (`users.avatar`), même répertoire que la photo employé.
     */
    public static function avatarUrl(?string $filename): ?string
    {
        return self::employeePhotoUrl($filename);
    }

    /**
     * Logo d'entreprise (`enterprises.logo`), versé dans `storage/logos`.
     */
    public static function logoUrl(?string $filename): ?string
    {
        return self::publicUrl('logos', $filename);
    }

    /**
     * Chemin de lecture publique d'un fichier du disque `public` — `null` quand
     * il n'y a pas de fichier, pour que l'UI retombe sur les initiales plutôt
     * que sur une URL vide (qui rend un cliché cassé).
     */
    private static function publicUrl(string $directory, ?string $filename): ?string
    {
        return $filename === null || $filename === ''
            ? null
            : "/storage/{$directory}/{$filename}";
    }
}
