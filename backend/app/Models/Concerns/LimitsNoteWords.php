<?php

namespace App\Models\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * Limite la note à 8 mots maximum à la création et à l'édition.
 * Les notes existantes (legacy) restent lisibles : la limite n'est appliquée
 * qu'au moment du save (création ou re-édition).
 */
trait LimitsNoteWords
{
    public const MAX_NOTE_WORDS = 8;

    /**
     * Nom du champ portant la note ("content" pour Note, "note" pour CallOutcome).
     */
    abstract protected function noteWordField(): string;

    public static function bootLimitsNoteWords(): void
    {
        static::saving(function ($model) {
            $field = $model->noteWordField();
            $value = $model->{$field};

            if ($value === null || trim((string) $value) === '') {
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
