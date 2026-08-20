<?php

namespace App\Enums;

/**
 * Jeu restreint de couleurs d'étapes.
 *
 * Les étapes sont configurables par projet, mais leur couleur ne l'est pas
 * librement : un sélecteur ouvert produit des pipelines criards et illisibles,
 * et surtout des teintes qui échouent au contraste sur l'un des deux thèmes.
 *
 * Ces huit valeurs sont des *noms*, pas des codes hexadécimaux. Le rendu est
 * décidé côté front, où vivent déjà les tokens de la charte : transmettre un
 * `#RRGGBB` depuis l'API figerait la palette en base et interdirait toute
 * retouche sans migration de données.
 */
enum StageColor: string
{
    case Slate = 'slate';
    case Blue = 'blue';
    case Teal = 'teal';
    case Sage = 'sage';
    case Amber = 'amber';
    case Violet = 'violet';
    case Rose = 'rose';
    case Red = 'red';

    public function label(): string
    {
        return match ($this) {
            self::Slate => 'Gris',
            self::Blue => 'Bleu',
            self::Teal => 'Teal',
            self::Sage => 'Sauge',
            self::Amber => 'Ocre',
            self::Violet => 'Violet',
            self::Rose => 'Rose',
            self::Red => 'Rouge',
        };
    }

    /**
     * Couleur suggérée pour une étape terminale, appliquée à la création du
     * pipeline par défaut. L'utilisateur reste libre de la changer.
     */
    public static function forWon(): self
    {
        return self::Sage;
    }

    public static function forLost(): self
    {
        return self::Red;
    }
}
