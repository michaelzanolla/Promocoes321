<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Páginas institucionais. O texto fica em resources/content/{slug}.md e deve ser
 * revisado pelo responsável e pela análise jurídica antes da publicação.
 */
class PageController extends Controller
{
    public const PAGES = [
        'sobre' => 'Sobre a plataforma',
        'termos-de-uso' => 'Termos de uso',
        'privacidade' => 'Política de privacidade',
        'cancelamento-e-reembolso' => 'Cancelamento e reembolso',
        'regras' => 'Regras e informações legais',
        'contato' => 'Contato e suporte',
    ];

    public function __invoke(string $page): View
    {
        $markdown = File::get(resource_path("content/{$page}.md"));

        return view('site.page', [
            'slug' => $page,
            'title' => self::PAGES[$page],
            'html' => Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
        ]);
    }
}
