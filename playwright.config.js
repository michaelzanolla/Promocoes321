import { defineConfig } from '@playwright/test';

// Testes ponta a ponta: npm run test:e2e (precisa do MySQL do Docker rodando e de npm run build).
// Sobe um servidor próprio na porta 8001 usando o banco promocoes_test, recriado com os dados de
// demonstração a cada execução. O mesmo banco é zerado pelos testes PHP: não rode os dois ao mesmo tempo.
const env = {
    DB_DATABASE: 'promocoes_test',
    APP_URL: 'http://127.0.0.1:8001',
    // Cache em memória: os limites de tentativas (ex.: 10 reservas por minuto) não atrapalham os testes.
    CACHE_STORE: 'array',
    MAIL_MAILER: 'array',
};

export default defineConfig({
    testDir: 'tests/e2e',
    // O servidor embutido do PHP atende uma requisição por vez.
    workers: 1,
    use: { baseURL: env.APP_URL, locale: 'pt-BR' },
    webServer: {
        command: 'php artisan migrate:fresh --seed --force && cd public && php -S 127.0.0.1:8001 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
        url: `${env.APP_URL}/up`,
        env,
        reuseExistingServer: false,
        timeout: 120_000,
    },
});
