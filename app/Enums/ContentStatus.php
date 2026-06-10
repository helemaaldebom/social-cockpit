<?php

namespace App\Enums;

enum ContentStatus: string
{
    case Concept = 'concept';
    case Gegenereerd = 'gegenereerd';
    case InReview = 'in_review';
    case Goedgekeurd = 'goedgekeurd';
    case Ingepland = 'ingepland';
    case Geplaatst = 'geplaatst';
    case Mislukt = 'mislukt';

    public function label(): string
    {
        return match($this) {
            self::Concept => 'Concept',
            self::Gegenereerd => 'Gegenereerd',
            self::InReview => 'In review',
            self::Goedgekeurd => 'Goedgekeurd',
            self::Ingepland => 'Ingepland',
            self::Geplaatst => 'Geplaatst',
            self::Mislukt => 'Mislukt',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Concept => 'gray',
            self::Gegenereerd => 'blue',
            self::InReview => 'yellow',
            self::Goedgekeurd => 'green',
            self::Ingepland => 'indigo',
            self::Geplaatst => 'success',
            self::Mislukt => 'danger',
        };
    }

    public static function allowedTransitions(): array
    {
        return [
            // Concept kan via de AI-generatieflow naar Gegenereerd, of voor
            // handgeschreven posts direct naar Goedgekeurd (review wordt overgeslagen).
            self::Concept->value => [self::Gegenereerd->value, self::Goedgekeurd->value],
            self::Gegenereerd->value => [self::InReview->value],
            self::InReview->value => [self::Goedgekeurd->value],
            self::Goedgekeurd->value => [self::Ingepland->value],
            self::Ingepland->value => [self::Geplaatst->value],
        ];
    }

    public function canTransitionTo(self $new): bool
    {
        if ($new === self::Mislukt) {
            return true;
        }
        $allowed = self::allowedTransitions()[$this->value] ?? [];
        return in_array($new->value, $allowed, true);
    }
}
