<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Promotion;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Options\PageMargin;
use OpenSpout\Writer\XLSX\Options\PageOrientation;
use OpenSpout\Writer\XLSX\Options\PageSetup;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Planilha de compradores de uma promoção, com os números pagos (site e fora do site).
 * Aba "Por comprador": uma linha por pessoa com todos os números. Aba "Por número": uma linha por número.
 * XLSX em vez de CSV: o Excel não come zeros à esquerda nem vira telefone em notação científica.
 */
class BuyersReport
{
    public const HEADER = ['Número', 'Nome', 'Telefone', 'E-mail', 'Pedido', 'Confirmado em'];

    public const BUYER_HEADER = ['Nome', 'Telefone', 'E-mail', 'Qtd.', 'Números'];

    public const OFFLINE_NAME = 'Venda fora do site';

    // Paleta: cabeçalho azul-marinho com filete verde; números em verde; venda fora do site em âmbar.
    private const NAVY = '17324D';

    private const TEAL = '18A999';

    private const TEAL_BG = 'E6F6F3';

    private const TEAL_INK = '087F73';

    private const INK = '243746';

    private const MUTED = '5B6B7A';

    private const LINE = 'E1E7ED';

    private const WHITE = 'FFFFFF';

    private const ZEBRA = 'F5F7FA';

    private const AMBER_BG = 'FFF7E6';

    private const AMBER_BADGE = 'FCE7B2';

    private const AMBER_INK = '8A5A00';

    /**
     * @return list<list<string>>
     */
    public function rows(Promotion $promotion): array
    {
        $orders = Order::query()
            ->where('promotion_id', $promotion->id)
            ->where('status', OrderStatus::Confirmed)
            ->with(['items' => fn ($q) => $q->where('active', 1)])
            ->get();

        $rows = [];
        foreach ($orders as $o) {
            foreach ($o->items as $i) {
                $rows[] = [
                    $promotion->formatNumber((int) $i->number),
                    $o->isOffline()
                        ? self::OFFLINE_NAME.(filled($o->internal_note) ? " — {$o->internal_note}" : '')
                        : (string) $o->buyer_name,
                    (string) $o->buyer_phone,
                    (string) $o->buyer_email,
                    (string) $o->public_code,
                    $o->confirmed_at?->format('d/m/Y H:i') ?? '',
                ];
            }
        }
        // Números com o mesmo número de dígitos: ordem de texto = ordem numérica.
        usort($rows, fn (array $a, array $b) => strcmp($a[0], $b[0]));

        return $rows;
    }

    /**
     * Agrupa por comprador: pelo e-mail (junta vários pedidos da mesma pessoa);
     * venda fora do site não tem e-mail, então cada marcação é um grupo.
     *
     * @param  list<list<string>>  $rows  saída de rows()
     * @return list<array{string, string, string, int, string}>
     */
    public function byBuyer(array $rows): array
    {
        $groups = [];
        foreach ($rows as [$number, $name, $phone, $email, $code]) {
            $key = $email !== '' ? mb_strtolower($email) : "pedido:{$code}";
            $groups[$key] ??= ['name' => $name, 'phone' => '', 'email' => $email, 'numbers' => []];
            $groups[$key]['phone'] = $groups[$key]['phone'] ?: $phone;
            $groups[$key]['numbers'][] = $number;
        }

        $buyers = array_map(fn (array $g) => [$g['name'], $g['phone'], $g['email'], count($g['numbers']), implode(', ', $g['numbers'])], array_values($groups));
        usort($buyers, fn (array $a, array $b) => strcmp(mb_strtolower($a[0]), mb_strtolower($b[0])));

        return $buyers;
    }

    /** Grava a planilha em $path. Texto vira sempre célula de texto: nome começando com "=" nunca vira fórmula. */
    public function writeXlsx(Promotion $promotion, string $path): int
    {
        $rows = $this->rows($promotion);
        $options = new Options;
        $options->setPageSetup(new PageSetup(PageOrientation::LANDSCAPE, null, 0, 1)); // impressão: largura em uma página
        $options->setPageMargin(new PageMargin(0.5, 0.25, 0.5, 0.25, 0.5, 0.5));
        $writer = new Writer($options);
        $writer->openToFile($path);

        $buyers = $this->byBuyer($rows);
        $this->startSheet($writer, $writer->getCurrentSheet()->setName('Por comprador'), self::BUYER_HEADER, [43, 18, 34, 12, 100], count($buyers));
        foreach ($buyers as $b) {
            // Venda fora do site não tem e-mail; comprador do site sempre tem.
            $offline = $b[2] === '' && str_starts_with($b[0], self::OFFLINE_NAME);
            $bg = $offline ? self::AMBER_BG : self::WHITE;
            $styles = [
                $offline ? $this->style($bg, color: self::AMBER_INK, bold: true) : $this->style($bg),
                $this->style($bg),
                $this->style($bg),
                $offline ? $this->style(self::AMBER_BADGE, 11, self::AMBER_INK, true, 'center') : $this->style(self::TEAL_BG, 11, self::TEAL_INK, true, 'center'),
                $this->style($bg, wrap: true),
            ];
            $lines = (int) ceil(mb_strlen($b[4]) / 110); // ~110 caracteres por linha na coluna Números
            $this->addRow($writer, $b, $styles, max(30, $lines * 14));
        }

        $this->startSheet($writer, $writer->addNewSheetAndMakeItCurrent()->setName('Por número'), self::HEADER, [13, 49, 18, 32, 19, 23], count($rows), freezeFirstColumn: true);
        foreach ($rows as $index => $r) {
            $bg = $index % 2 ? self::ZEBRA : self::WHITE;
            $styles = [
                $this->style(self::TEAL_BG, color: self::TEAL_INK, bold: true, align: 'center'),
                $this->style($bg, wrap: true),
                $this->style($bg),
                $this->style($bg),
                $this->style($bg, 9, self::MUTED, align: 'center'),
                $this->style($bg, 9, self::MUTED, align: 'center'),
            ];
            $lines = (int) ceil(mb_strlen($r[1]) / 54); // ~54 caracteres por linha na coluna Nome
            $this->addRow($writer, $r, $styles, max(23, $lines * 16));
        }
        $writer->close();

        return count($rows);
    }

    /**
     * Larguras (em caracteres), cabeçalho congelado e com filtro, sem linhas de grade.
     *
     * @param  non-empty-list<string>  $header
     * @param  list<int>  $widths
     * @param  int<0, max>  $count  linhas de dados (para o filtro)
     */
    private function startSheet(Writer $writer, Sheet $sheet, array $header, array $widths, int $count, bool $freezeFirstColumn = false): void
    {
        foreach ($widths as $column => $width) {
            $sheet->setColumnWidth($width, $column + 1);
        }
        $view = (new SheetView)->setFreezeRow(2)->setShowGridLines(false)->setZoomScale(90);
        $sheet->setSheetView($freezeFirstColumn ? $view->setFreezeColumn('B') : $view)
            ->setAutoFilter(new AutoFilter(0, 1, count($header) - 1, $count + 1));

        $style = fn (string $align) => (new Style)->setFontName('Aptos Display')->setFontSize(11)->setFontBold()->setFontColor(self::WHITE)
            ->setBackgroundColor(self::NAVY)->setBorder(new Border(new BorderPart(Border::BOTTOM, self::TEAL, Border::WIDTH_MEDIUM)))
            ->setCellAlignment($align)->setCellVerticalAlignment('center')->setShouldWrapText();
        $writer->addRow((new Row(array_map(
            fn (string $v) => new StringCell($v, $style($v === 'Número' ? 'center' : 'left')),
            $header,
        )))->setHeight(32));
    }

    /**
     * @param  array<int, string|int>  $values
     * @param  list<Style>  $styles
     */
    private function addRow(Writer $writer, array $values, array $styles, float $height): void
    {
        $cells = [];
        foreach (array_values($values) as $i => $v) {
            $cells[] = is_int($v) ? new NumericCell($v, $styles[$i]) : new StringCell($v, $styles[$i]);
        }
        $writer->addRow((new Row($cells))->setHeight($height));
    }

    private function style(string $fill, int $size = 10, string $color = self::INK, bool $bold = false, string $align = 'left', bool $wrap = false): Style
    {
        $style = (new Style)->setFontName('Aptos')->setFontSize($size)->setFontColor($color)->setBackgroundColor($fill)
            ->setBorder(new Border(new BorderPart(Border::BOTTOM, self::LINE, Border::WIDTH_THIN)))
            ->setCellAlignment($align)->setCellVerticalAlignment('center')->setShouldWrapText($wrap);

        return $bold ? $style->setFontBold() : $style;
    }
}
