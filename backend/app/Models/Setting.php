<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Préférence applicative stockée en base, gérée depuis l'interface
 * d'administration (table `settings`, clé → valeur texte).
 *
 * Différence avec `config()` : une valeur `config/*.php` vient de
 * l'environnement et peut être figée par `config:cache` — elle n'est donc pas
 * modifiable à chaud. Ce qui doit se régler sans redéploiement (le
 * coupe-circuit du géorepérage GPS, par exemple) vit ici, et **garde la
 * configuration comme valeur par défaut** : tant qu'aucune ligne n'existe,
 * `bool()` / `value()` retournent ce que dit `config()`.
 *
 * Pas de `HasFactory` ni de timestamps Eloquent : la table porte des défauts
 * SQL (`useCurrent`) et les lignes sont écrites une par une via `put()`.
 */
class Setting extends Model
{
    /** Clé du coupe-circuit global du géorepérage GPS (page « GPS »). */
    public const GEOFENCE_ENABLED = 'geofence.enabled';

    protected $table = 'settings';

    /** Clé primaire : c'est la clé de la préférence, pas un auto-incrément. */
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['key', 'value'];

    /** Valeur brute (`null` si la préférence n'a jamais été posée). */
    public static function value(string $key): ?string
    {
        return static::query()->where('key', $key)->value('value');
    }

    /**
     * Préférence lue comme booléen, avec repli sur la valeur de configuration.
     *
     * `'1'` / `'true'` / `'on'` (insensibles à la casse) = vrai ; toute autre
     * valeur non vide = faux ; ligne absente = `$default`.
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::value($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** Écriture (création ou mise à jour) de la valeur, en chaîne. */
    public static function put(string $key, string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** Pose un booléen — stocké `'1'` / `'0'`, les seules formes relues. */
    public static function setBool(string $key, bool $value): void
    {
        self::put($key, $value ? '1' : '0');
    }
}
