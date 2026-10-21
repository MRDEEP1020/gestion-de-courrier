<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ListeReference extends Model
{
    public const TYPE_DOCUMENT = 'type_document';

    public const MODE_RECEPTION = 'mode_reception';

    public const PRIORITE = 'priorite';

    protected $table = 'listes_reference';

    protected $fillable = ['type', 'valeur', 'ordre', 'actif', 'protege'];

    protected function casts(): array
    {
        return [
            'ordre' => 'integer',
            'actif' => 'boolean',
            'protege' => 'boolean',
        ];
    }

    private static function cleCache(string $type): string
    {
        return "listes_reference.{$type}";
    }

    /**
     * Valeurs actives d'une liste, dans l'ordre — c'est cette liste qui
     * alimente les <select> de saisie et les Rule::in() de validation.
     *
     * @return array<int, string>
     */
    public static function valeursActives(string $type): array
    {
        return Cache::rememberForever(
            self::cleCache($type),
            fn () => self::query()
                ->where('type', $type)
                ->where('actif', true)
                ->orderBy('ordre')
                ->pluck('valeur')
                ->all(),
        );
    }

    /**
     * Toutes les valeurs d'une liste (actives et inactives), dans l'ordre
     * — utilisé uniquement par l'écran d'administration.
     */
    public static function toutes(string $type)
    {
        return self::query()
            ->where('type', $type)
            ->orderBy('ordre')
            ->get();
    }

    public static function invaliderCache(string $type): void
    {
        Cache::forget(self::cleCache($type));
    }

    protected static function booted(): void
    {
        static::saved(fn (self $liste) => self::invaliderCache($liste->type));
        static::deleted(fn (self $liste) => self::invaliderCache($liste->type));
    }
}
