<?php

namespace App\Models\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * Limite la note à 8 mots maximum à la création et à l'édition.
 * Les notes existantes (legacy) restent lisibles : la limite n'est appliquée
 * qu'au moment du save (création ou re-édition).
 *
 * La limite porte sur la **saisie humaine** : une description générée par le
 * système (`sender_id = SYSTEM`) peut l'outrepasser en redéfinissant
 * `noteWordsAreLimited()`.
 */
trait LimitsNoteWords
{
    public const MAX_NOTE_WORDS = 8;

    /**
     * Nom du champ portant la note ("description" sur Note).
     */
    abstract protected function noteWordField(): string;

    /** Descriptions générées (non saisies) : non limitées. */
    protected function noteWordsAreLimited(): bool
    {
        return true;
    }

    public static function bootLimitsNoteWords(): void
    {
        static::saving(function ($model) {
            $field = $model->noteWordField();
            $value = $model->{$field};

            if ($value === null || trim((string) $value) === '') {
                return;
            }

            if (! $model->noteWordsAreLimited()) {
                return;
            }

            $words = preg_split('/\s+/u', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY);

            if (is_array($words) && count($words) > static::MAX_NOTE_WORDS) {
                throw ValidationException::withMessages([
                    $field => 'La note ne peut pas dépasser '.static::MAX_NOTE_WORDS.' mots.',
                ]);
            }
        });
    }
}
