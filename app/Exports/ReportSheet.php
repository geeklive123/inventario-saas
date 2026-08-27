<?php

namespace App\Exports;

use Carbon\CarbonInterface;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ReportSheet implements FromArray, ShouldAutoSize, WithEvents, WithStyles, WithTitle
{
    private int $detailHeaderRow = 0;

    private int $lastRow = 0;

    /**
     * @param  array{company: string, currency: string, period: string, filters: string, generated_at: CarbonInterface}  $document
     * @param  array{title: string, metrics: list<array{label: string, value: mixed, type: string}>, columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>}  $section
     */
    public function __construct(private array $document, private array $section) {}

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        $rows = [
            [$this->document['company']],
            [$this->section['title']],
            ['Período', $this->document['period']],
            ['Generado', $this->document['generated_at']->format('d/m/Y H:i')],
            ['Filtros', $this->document['filters']],
            ['Moneda', $this->document['currency']],
            [],
            ['Resumen de métricas'],
        ];

        foreach ($this->section['metrics'] as $metric) {
            $rows[] = [$metric['label'], $this->spreadsheetValue($metric['value'], $metric['type'])];
        }

        if ($this->section['columns'] !== []) {
            $rows[] = [];
            $rows[] = ['Detalle'];
            $this->detailHeaderRow = count($rows) + 1;
            $rows[] = collect($this->section['columns'])->pluck('label')->all();

            foreach ($this->section['rows'] as $row) {
                $rows[] = collect($this->section['columns'])->map(
                    fn (array $column): mixed => $this->spreadsheetValue($row[$column['key']] ?? null, $column['type']),
                )->all();
            }
        }

        $this->lastRow = count($rows);

        return $rows;
    }

    public function title(): string
    {
        return mb_substr($this->section['title'], 0, 31);
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        $lastColumn = Coordinate::stringFromColumnIndex(max(2, count($this->section['columns'])));
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->getStyle("A1:{$lastColumn}2")->getFont()->setBold(true)->setColor(new Color('FFFFFFFF'));
        $sheet->getStyle("A1:{$lastColumn}2")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF365849');
        $sheet->getStyle('A8:B8')->getFont()->setBold(true);

        foreach ($this->section['metrics'] as $index => $metric) {
            $row = 9 + $index;

            if ($metric['type'] === 'money') {
                $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode('#,##0.00 "'.$this->document['currency'].'"');
            }
        }

        if ($this->detailHeaderRow > 0) {
            $sheet->getStyle("A{$this->detailHeaderRow}:{$lastColumn}{$this->detailHeaderRow}")
                ->getFont()->setBold(true)->setColor(new Color('FFFFFFFF'));
            $sheet->getStyle("A{$this->detailHeaderRow}:{$lastColumn}{$this->detailHeaderRow}")
                ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF587A69');
            $sheet->freezePane('A'.($this->detailHeaderRow + 1));

            $sheet->setAutoFilter("A{$this->detailHeaderRow}:{$lastColumn}{$this->lastRow}");

            foreach ($this->section['columns'] as $index => $column) {
                if ($column['type'] === 'money') {
                    $letter = Coordinate::stringFromColumnIndex($index + 1);
                    $sheet->getStyle("{$letter}".($this->detailHeaderRow + 1).":{$letter}{$this->lastRow}")
                        ->getNumberFormat()->setFormatCode('#,##0.00 "'.$this->document['currency'].'"');
                }
            }
        } else {
            $sheet->freezePane('A9');
        }

        return [1 => ['font' => ['size' => 16]], 2 => ['font' => ['size' => 13]]];
    }

    /** @return array<class-string, callable> */
    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event): void {
            $event->sheet->getDelegate()->getStyle("A1:Z{$this->lastRow}")->getAlignment()->setVertical('center');
            $event->sheet->getDelegate()->getStyle("A1:Z{$this->lastRow}")->getAlignment()->setWrapText(true);
            $event->sheet->getDelegate()->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
        }];
    }

    private function spreadsheetValue(mixed $value, string $type): mixed
    {
        if ($value instanceof CarbonInterface) {
            return $value->timezone($this->document['generated_at']->timezone)->format('d/m/Y H:i');
        }

        return match ($type) {
            'money', 'quantity' => $value === null ? null : (float) $value,
            'integer' => $value === null ? null : (int) $value,
            default => $value,
        };
    }
}
