<?php

/*
 * Valores padrão das configurações da plataforma.
 * O administrador pode sobrescrevê-los em Painel > Configurações (tabela settings).
 * Não preencha documentos ou autorizações que ainda não existam.
 */
return [
    'organizer_name' => env('ORGANIZER_NAME'),
    'organizer_document' => env('ORGANIZER_DOCUMENT'),
    'organizer_city' => env('ORGANIZER_CITY'),
    'contact_email' => env('CONTACT_EMAIL'),
    'contact_whatsapp' => env('CONTACT_WHATSAPP'),

    'hero_title' => 'Escolha seus números.',
    'hero_highlight' => 'Concorra entre amigos.',
    'hero_text' => 'Escolha uma promoção, marque os números que quiser e pague por Pix ou cartão. Assim que o pagamento é confirmado, você recebe o comprovante com os seus números.',
];
