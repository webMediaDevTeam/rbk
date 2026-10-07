<?php

namespace App\Observers;

use App\Models\Client;

/**
 * Champs **dérivés de recherche** de la table `clients`.
 *
 * Deux colonnes sont maintenues ici et jamais ailleurs :
 *
 *  - `phone_normalized` — clé de chiffres nationaux (`8194186550`), produit
 *    par `Client::normalizePhone()` : indifférente au formatage saisi ;
 *  - `simple_name` — nom sans accent ni casse, produit par
 *    `Client::simpleName()` : `José Tremblay` → `jose tremblay`.
 *
 * Les deux sont hors `$fillable` : seul cet observateur les écrit, ce qui
 * garantit qu'elles ne peuvent ni être injectées par mass-assignment ni
 * diverger d'avec `phone` / `name`.
 */
class ClientObserver
{
    /**
     * Avant INSERT — les champs sont renseignés avant que la ligne n'existe,
     * donc aucune requête ne voit jamais une ligne avec des colonnes NULL
     * issues d'une création.
     */
    public function creating(Client $client): void
    {
        $this->populate($client);
    }

    /**
     * Avant UPDATE — recalcul systématique : `phone` / `name` peuvent changer
     * indépendamment l'un de l'autre, et un événement `updating` ne présume
     * jamais du motif.
     */
    public function updating(Client $client): void
    {
        $this->populate($client);
    }

    private function populate(Client $client): void
    {
        $client->phone_normalized = Client::normalizePhone($client->phone);
        $client->simple_name = Client::simpleName($client->name);
    }
}
