<?php

namespace App\Support;

class ClientKey
{
    /**
     * Identificação de quem acessa, usada nos limites de tentativas e de pedidos abertos.
     * IPv6 conta pelo bloco /64: uma única conexão costuma receber o bloco inteiro, e trocar
     * de endereço dentro dele não pode zerar os limites. IPv4 (inclusive mapeado em IPv6) fica igual.
     */
    public static function of(?string $ip): string
    {
        $bin = $ip !== null ? @inet_pton($ip) : false;

        if ($bin === false || strlen($bin) !== 16) {
            return (string) $ip;
        }

        if (str_starts_with($bin, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($bin, 12));
        }

        return inet_ntop(substr($bin, 0, 8).str_repeat("\0", 8)).'/64';
    }
}
