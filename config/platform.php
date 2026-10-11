<?php

/*
 * Dados do responsável e de contato exibidos no site e nos comprovantes.
 * Ficam no .env até existir a tela de configurações no painel (Fase 4).
 * Não preencha documentos ou autorizações que ainda não existam.
 */
return [
    'organizer' => [
        'name' => env('ORGANIZER_NAME'),
        'document' => env('ORGANIZER_DOCUMENT'),
        'city' => env('ORGANIZER_CITY'),
    ],

    'contact' => [
        'email' => env('CONTACT_EMAIL'),
        'whatsapp' => env('CONTACT_WHATSAPP'),
    ],
];
