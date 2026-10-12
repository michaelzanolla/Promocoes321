import { expect, test } from '@playwright/test';

// O pagamento em si não entra aqui: depende do Asaas e é coberto pelos testes PHP (provedor simulado)
// e pela execução manual no sandbox do checklist de publicação (README).
const numeros = '/promocoes/ficticia-vale-compras-de-r-500/numeros';
const reservar = { name: 'Reservar e continuar' };

test('escolher números, reservar, confirmar os dados e desistir', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('link', { name: /Vale-compras de R\$ 500/ }).first().click();
    await page.getByRole('link', { name: 'Escolher números' }).click();

    await page.locator('#n7').click();
    await page.locator('#n10').click();
    await expect(page.locator('#n7')).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('button', reservar).click();
    await expect(page).toHaveURL(/\/pedido\/[A-Z0-9]{10}$/);

    await page.getByLabel('Nome completo').fill('Maria da Silva');
    await page.getByLabel('E-mail', { exact: true }).fill('maria@exemplo.test');
    await page.getByLabel('CPF').fill('529.982.247-25');
    await page.getByLabel('Pix', { exact: true }).check();
    await page.getByRole('checkbox', { name: /Li e aceito/ }).check();
    await page.getByRole('button', { name: 'Confirmar dados' }).click();

    await expect(page.getByText('Dados confirmados.')).toBeVisible();
    await expect(page.getByRole('button', { name: /Gerar Pix de R\$\s20,00/ })).toBeVisible();

    await page.getByText('Desistir deste pedido').click();
    await page.getByRole('button', { name: 'Cancelar pedido e liberar números' }).click();
    await expect(page.getByText('Pedido cancelado. Os números foram liberados.')).toHaveCount(1);
});

test('erros do formulário aparecem em português e marcam o campo', async ({ page }) => {
    await page.goto(numeros);
    await page.locator('#n20').click();
    await page.getByRole('button', reservar).click();
    await expect(page).toHaveURL(/\/pedido\//);

    await page.getByLabel('CPF').fill('111.111.111-11');
    await page.locator('form:has([name=buyer_cpf])').evaluate((f) => { f.noValidate = true });
    await page.getByRole('button', { name: 'Confirmar dados' }).click();

    await expect(page.getByLabel('Nome completo')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByText(/campo nome completo/)).toBeVisible();
    await expect(page.getByLabel('CPF')).toHaveAttribute('aria-invalid', 'true');
});

test('duas pessoas no mesmo número: só a primeira reserva', async ({ browser }) => {
    const a = await (await browser.newContext()).newPage();
    const b = await (await browser.newContext()).newPage();
    await a.goto(numeros);
    await b.goto(numeros);
    await a.locator('#n50').click();
    await b.locator('#n50').click();

    await a.getByRole('button', reservar).click();
    await expect(a).toHaveURL(/\/pedido\//);

    // Se a atualização automática da grade chegar antes, o número sai da seleção e o botão desativa:
    // nos dois casos a pessoa é avisada e nada é reservado.
    await b.getByRole('button', reservar).click({ timeout: 2_000 }).catch(() => {});
    await expect(b.getByText(/acabou de ser reservado/).first()).toBeVisible();
    await expect(b).toHaveURL(/\/numeros$/);
});

test('grade de números pelo teclado', async ({ page }) => {
    await page.goto(numeros);
    const grade = page.locator('[aria-describedby=dica-grade]');
    await expect(grade.locator('button[tabindex="0"]')).toHaveCount(1);

    await grade.locator('button[tabindex="0"]').focus();
    await page.keyboard.press('ArrowRight');
    await expect(page.locator('#n2')).toBeFocused();
    await page.keyboard.press('Space');
    await expect(page.locator('#n2')).toHaveAttribute('aria-pressed', 'true');

    await page.keyboard.press('End');
    await expect(page.locator('#n200')).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(grade.locator(':focus')).toHaveCount(0);
});
