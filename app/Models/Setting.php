<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Configurações editáveis no painel. O que não foi salvo no banco usa o padrão de config/platform.php.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    private const CACHE_KEY = 'settings.all';

    public static function read(string $key): ?string
    {
        $saved = Cache::rememberForever(self::CACHE_KEY, fn () => self::query()->pluck('value', 'key')->all());

        return filled($saved[$key] ?? null) ? $saved[$key] : config("platform.{$key}");
    }

    /**
     * @param  array<string, string|null>  $values
     */
    public static function write(array $values): void
    {
        foreach ($values as $key => $value) {
            self::query()->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
        }

        Cache::forget(self::CACHE_KEY);
    }
}
