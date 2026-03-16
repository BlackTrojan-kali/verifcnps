<?php

namespace App\Services;

class NiuService
{
    /**
     * Nettoie le NIU (enlève les espaces, tirets et met en majuscules)
     */
    public function sanitize(string $niu): string
    {
        $cleaned = preg_replace('/[^a-zA-Z0-9]/', '', $niu);
        return strtoupper($cleaned);
    }

    /**
     * Vérifie si le format du NIU est valide
     * Format standard attendu : 1 Lettre (M/P) + 12 Chiffres + 1 Lettre
     */
    public function isValid(string $niu): bool
    {
        $cleanNiu = $this->sanitize($niu);

        // Regex : Commence par M ou P, puis 12 chiffres, puis une lettre (A-Z)
        // Vous pouvez adapter la regex '/^[A-Z]\d{12}[A-Z]$/' si les premières lettres varient plus
        $pattern = '/^[MP]\d{12}[A-Z]$/';

        return preg_match($pattern, $cleanNiu) === 1;
    }

    /**
     * Extrait le type de contribuable basé sur la première lettre
     */
    public function getTaxpayerType(string $niu): string
    {
        $cleanNiu = $this->sanitize($niu);
        $firstLetter = substr($cleanNiu, 0, 1);

        if ($firstLetter === 'M') {
            return 'Personne Morale (Entreprise)';
        } elseif ($firstLetter === 'P') {
            return 'Personne Physique';
        }

        return 'Inconnu';
    }

    /**
     * Masque une partie du NIU pour des raisons de sécurité (ex: M12345****12X)
     */
    public function mask(string $niu): string
    {
        $cleanNiu = $this->sanitize($niu);
        
        if (strlen($cleanNiu) < 14) {
            return $cleanNiu; 
        }

        return substr($cleanNiu, 0, 6) . '****' . substr($cleanNiu, -4);
    }
}