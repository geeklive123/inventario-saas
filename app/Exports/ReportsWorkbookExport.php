<?php

namespace App\Exports;

use Carbon\CarbonInterface;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ReportsWorkbookExport implements WithMultipleSheets
{
    /**
     * @param  array{company: string, currency: string, period: string, filters: string, generated_at: CarbonInterface, sections: list<array{title: string, metrics: list<array{label: string, value: mixed, type: string}>, columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>}>}  $document
     */
    public function __construct(private array $document) {}

    /** @return list<ReportSheet> */
    public function sheets(): array
    {
        $sheets = [];

        foreach ($this->document['sections'] as $section) {
            $sheets[] = new ReportSheet($this->document, $section);
        }

        return $sheets;
    }
}
