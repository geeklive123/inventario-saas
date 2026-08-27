<?php

namespace App\Http\Controllers;

use App\Exports\ReportsWorkbookExport;
use App\Http\Requests\ReportExportRequest;
use App\Services\Reports\ReportExportData;
use App\Support\Tenancy\CurrentCompany;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportExportController extends Controller
{
    public function __construct(private ReportExportData $exportData) {}

    public function __invoke(
        ReportExportRequest $request,
        CurrentCompany $currentCompany,
        string $format,
    ): BinaryFileResponse|Response {
        abort_unless(in_array($format, ['pdf', 'xlsx'], true), 404);
        $validated = $request->validated();
        $document = $this->exportData->build(
            $currentCompany->membership(),
            $validated['period'],
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null,
            $validated['scope'],
            $validated['section'],
        );
        $filename = Str::slug($document['company'].'-'.$document['report_name'].'-'.now()->format('Y-m-d-His'));

        if ($format === 'xlsx') {
            return Excel::download(new ReportsWorkbookExport($document), "{$filename}.xlsx");
        }

        return Pdf::loadView('reports.pdf', compact('document'))
            ->setPaper('a4', 'landscape')
            ->setOption('defaultFont', 'DejaVu Sans')
            ->download("{$filename}.pdf");
    }
}
