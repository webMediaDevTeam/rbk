<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Source — répertoire fermé **en lecture seule** : la seule route est
 * `GET /api/v1/sources` (`SourceController::index`), aucun endpoint de
 * création / mise à jour / suppression n'existe.
 *
 * Les lignes sont posées par `Database\Seeders\SourceSeeder` ; elles
 * alimentent le sélecteur « Source » des modales entreprise
 * (`enterprises.source` conserve le libellé choisi).
 */
class Source extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['name'];
}
