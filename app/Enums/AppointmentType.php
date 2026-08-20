<?php

namespace App\Enums;

/**
 * Modalité d'un rendez-vous — §7 de l'esquisse.
 *
 * Contrairement aux étapes de pipeline, ces valeurs ne sont pas configurables :
 * elles décrivent la façon dont l'échange a lieu, pas la stratégie commerciale
 * du client.
 */
enum AppointmentType: string
{
    case Phone = 'phone';
    case Video = 'video';
    case OnSite = 'on_site';

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'Téléphone',
            self::Video => 'Visioconférence',
            self::OnSite => 'Physique',
        };
    }

    /**
     * Un rendez-vous physique a besoin d'un lieu ; les deux autres non.
     * C'est ce qui rend le champ conditionnellement obligatoire.
     */
    public function requiresLocation(): bool
    {
        return $this === self::OnSite;
    }
}
