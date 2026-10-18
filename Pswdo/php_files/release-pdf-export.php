<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/pswdo-common.php';
require_once __DIR__ . '/pswdo-data-lib.php';
require_once __DIR__ . '/fpdf.php';

pswdo_start_session();

/**
 * Core PDF Generator class for AICS Release Masterlists.
 */
class AICSReleaseMasterlistPDF extends FPDF
{
    public string $municipality = 'Antique';
    public string $preparedBy = 'PSWDO Staff';
    public string $generatedAt = '';
    public string $dateRange = '';

    public function Header()
    {
        // Top Provincial Brand
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetTextColor(71, 85, 105); // slate-600
        $this->Cell(0, 4, 'REPUBLIC OF THE PHILIPPINES | PROVINCE OF ANTIQUE', 0, 1, 'C');

        $this->SetFont('Helvetica', 'B', 12);
        $this->SetTextColor(15, 23, 42); // slate-900
        $this->Cell(0, 6, 'PROVINCIAL SOCIAL WELFARE AND DEVELOPMENT OFFICE (PSWDO)', 0, 1, 'C');

        $this->SetFont('Helvetica', '', 8.5);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 4, 'Binirayan Hills, San Jose de Buenavista, Antique 5700 | AICS Operations Management', 0, 1, 'C');

        $this->Ln(3);
        $this->SetDrawColor(14, 165, 233); // sky-500
        $this->SetLineWidth(0.8);
        $this->Line(10, $this->GetY(), $this->GetPageWidth() - 10, $this->GetY());
        $this->Ln(4);

        // Document Title
        $this->SetFont('Helvetica', 'B', 13);
        $this->SetTextColor(3, 105, 161); // sky-700
        $muniTitle = strtoupper($this->municipality !== '' && strtolower($this->municipality) !== 'all'
            ? 'MUNICIPALITY OF ' . $this->municipality
            : 'PROVINCE-WIDE (ALL MUNICIPALITIES)');
        $this->Cell(0, 6, 'OFFICIAL AICS BENEFICIARY RELEASE REGISTRY - ' . $muniTitle, 0, 1, 'C');

        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(100, 116, 139);
        $metaText = 'Generated: ' . $this->generatedAt . '  |  Prepared By: ' . $this->preparedBy;
        if ($this->dateRange !== '') {
            $metaText .= '  |  Period: ' . $this->dateRange;
        }
        $this->Cell(0, 4, $metaText, 0, 1, 'C');
        $this->Ln(4);
    }

    public function Footer()
    {
        $this->SetY(-15);
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.3);
        $this->Line(10, $this->GetY(), $this->GetPageWidth() - 10, $this->GetY());
        $this->Ln(2);

        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(148, 163, 184);
        $this->Cell(140, 5, 'PSWDO Antique AICS Automated Portal  |  Official Municipal Coordination Document', 0, 0, 'L');
        $this->Cell(0, 5, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R');
    }
}

/**
 * Builds the complete binary PDF string and metadata.
 *
 * @param array<string, mixed> $filters
 * @return array{filename: string, binary: string, record_count: int, total_amount: float, municipality: string}
 */
function pswdo_generate_release_pdf_binary(
    mysqli $connection,
    string $municipality,
    array $filters = [],
    string $staffName = ''
): array {
    $filters['municipality'] = $municipality;
    $records = pswdo_fetch_all_release_records($connection, $filters);
    $recordCount = count($records);

    $totalAmount = 0.0;
    foreach ($records as $r) {
        if ($r['amount_given'] !== null) {
            $totalAmount += (float) $r['amount_given'];
        }
    }

    $cleanMuni = trim($municipality) !== '' && strtolower(trim($municipality)) !== 'all'
        ? trim($municipality)
        : 'All-Antique';
    $timestamp = date('Ymd-His');
    $filename = 'AICS-Release-Masterlist-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $cleanMuni) . '-' . $timestamp . '.pdf';

    $pdf = new AICSReleaseMasterlistPDF('L', 'mm', 'A4');
    $pdf->municipality = $cleanMuni;
    $pdf->preparedBy = $staffName !== '' ? $staffName : 'PSWDO Staff';
    $pdf->generatedAt = date('F j, Y h:i A');

    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    if ($dateFrom !== '' || $dateTo !== '') {
        $pdf->dateRange = ($dateFrom !== '' ? $dateFrom : 'Beginning') . ' to ' . ($dateTo !== '' ? $dateTo : 'Present');
    }

    $pdf->AliasNbPages('{nb}');
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();

    // Summary Card Box above the table
    $pdf->SetFillColor(248, 250, 252); // slate-50
    $pdf->SetDrawColor(226, 232, 240); // slate-200
    $pdf->SetLineWidth(0.3);
    $pdf->Rect(10, $pdf->GetY(), 277, 14, 'DF');

    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetXY(14, $pdf->GetY() + 2);
    $pdf->Cell(65, 5, 'Target Jurisdiction: ' . ($cleanMuni !== 'All-Antique' ? 'Municipality of ' . $cleanMuni : 'All Municipalities'), 0, 0, 'L');
    $pdf->Cell(55, 5, 'Total Listed Records: ' . $recordCount, 0, 0, 'L');
    $pdf->Cell(75, 5, 'Total Assistance Disbursed: PHP ' . number_format($totalAmount, 2), 0, 0, 'L');
    $pdf->Cell(0, 5, 'Status Filter: ' . ucfirst(strtolower(trim((string) ($filters['status'] ?? 'all')))), 0, 1, 'R');
    $pdf->Ln(6);

    // Table Column Widths (Total: 277 mm)
    // 8 + 34 + 45 + 39 + 28 + 30 + 26 + 27 + 23 + 17 = 277 mm
    $colW = [
        'no' => 8,
        'ref' => 34,
        'beneficiary' => 45,
        'applicant' => 39,
        'barangay' => 28,
        'assistance' => 30,
        'amount' => 26,
        'sched_date' => 27,
        'batch' => 23,
        'status' => 17,
    ];

    // Table Header
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetFillColor(15, 23, 42); // slate-900
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetDrawColor(15, 23, 42);
    $pdf->SetLineWidth(0.2);

    $pdf->Cell($colW['no'], 7, 'No.', 1, 0, 'C', true);
    $pdf->Cell($colW['ref'], 7, 'Reference No.', 1, 0, 'C', true);
    $pdf->Cell($colW['beneficiary'], 7, 'Beneficiary Name', 1, 0, 'L', true);
    $pdf->Cell($colW['applicant'], 7, 'Applicant / Proxy', 1, 0, 'L', true);
    $pdf->Cell($colW['barangay'], 7, 'Barangay', 1, 0, 'L', true);
    $pdf->Cell($colW['assistance'], 7, 'Assistance Type', 1, 0, 'L', true);
    $pdf->Cell($colW['amount'], 7, 'Amount (PHP)', 1, 0, 'C', true);
    $pdf->Cell($colW['sched_date'], 7, 'Sched / Rel', 1, 0, 'C', true);
    $pdf->Cell($colW['batch'], 7, 'Batch / Venue', 1, 0, 'L', true);
    $pdf->Cell($colW['status'], 7, 'Status', 1, 1, 'C', true);

    // Table Rows
    $pdf->SetFont('Helvetica', '', 7.5);
    $fill = false;
    $idx = 1;

    if ($records === []) {
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell(277, 14, 'No release records found matching the specified municipality and criteria.', 1, 1, 'C');
    } else {
        foreach ($records as $row) {
            // Check page break trigger
            if ($pdf->GetY() > 165) {
                $pdf->AddPage();
                // Repeat Header
                $pdf->SetFont('Helvetica', 'B', 8);
                $pdf->SetFillColor(15, 23, 42);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->Cell($colW['no'], 7, 'No.', 1, 0, 'C', true);
                $pdf->Cell($colW['ref'], 7, 'Reference No.', 1, 0, 'C', true);
                $pdf->Cell($colW['beneficiary'], 7, 'Beneficiary Name', 1, 0, 'L', true);
                $pdf->Cell($colW['applicant'], 7, 'Applicant / Proxy', 1, 0, 'L', true);
                $pdf->Cell($colW['barangay'], 7, 'Barangay', 1, 0, 'L', true);
                $pdf->Cell($colW['assistance'], 7, 'Assistance Type', 1, 0, 'L', true);
                $pdf->Cell($colW['amount'], 7, 'Amount (PHP)', 1, 0, 'C', true);
                $pdf->Cell($colW['sched_date'], 7, 'Sched / Rel', 1, 0, 'C', true);
                $pdf->Cell($colW['batch'], 7, 'Batch / Venue', 1, 0, 'L', true);
                $pdf->Cell($colW['status'], 7, 'Status', 1, 1, 'C', true);
                $pdf->SetFont('Helvetica', '', 7.5);
            }

            $pdf->SetFillColor($fill ? 248 : 255, $fill ? 250 : 255, $fill ? 252 : 255);
            $pdf->SetDrawColor(226, 232, 240);
            $pdf->SetTextColor(30, 41, 59);

            $refNo = (string) ($row['reference_no'] ?? '-');
            $benName = substr((string) ($row['beneficiary_name'] ?? '-'), 0, 26);
            $appName = substr((string) ($row['applicant_name'] ?? '-'), 0, 22);
            $brgy = substr((string) ($row['barangay'] ?? '-'), 0, 18);
            $asst = substr((string) ($row['assistance'] ?? '-'), 0, 18);
            $amtStr = $row['amount_given'] !== null ? number_format((float) $row['amount_given'], 2) : '-';

            $rawDate = trim((string) ($row['scheduled_date_raw'] ?? ''));
            if ($rawDate !== '' && $rawDate !== '0000-00-00') {
                $ts = strtotime($rawDate);
                $dateStr = $ts !== false ? date('M d, Y', $ts) : $rawDate;
            } else {
                $dateStr = 'Unscheduled';
            }

            $batchVenue = (string) ($row['batch'] ?? 'Batch 1');
            $statusCode = (string) ($row['status_code'] ?? 'scheduled');
            $statusShort = $statusCode === 'released' ? 'Released' : ($statusCode === 'scheduled' ? 'Scheduled' : 'Approved');

            $pdf->Cell($colW['no'], 6, (string) $idx, 1, 0, 'C', true);
            $pdf->SetFont('Helvetica', 'B', 7.5);
            $pdf->Cell($colW['ref'], 6, $refNo, 1, 0, 'C', true);
            $pdf->SetFont('Helvetica', '', 7.5);
            $pdf->Cell($colW['beneficiary'], 6, $benName, 1, 0, 'L', true);
            $pdf->Cell($colW['applicant'], 6, $appName, 1, 0, 'L', true);
            $pdf->Cell($colW['barangay'], 6, $brgy, 1, 0, 'L', true);
            $pdf->Cell($colW['assistance'], 6, $asst, 1, 0, 'L', true);
            $pdf->Cell($colW['amount'], 6, $amtStr, 1, 0, 'R', true);
            $pdf->Cell($colW['sched_date'], 6, $dateStr, 1, 0, 'C', true);
            $pdf->Cell($colW['batch'], 6, $batchVenue, 1, 0, 'L', true);

            // Status color highlight
            if ($statusCode === 'released') {
                $pdf->SetTextColor(22, 101, 52); // green-800
                $pdf->SetFont('Helvetica', 'B', 7);
            } elseif ($statusCode === 'scheduled') {
                $pdf->SetTextColor(14, 116, 144); // cyan-700
                $pdf->SetFont('Helvetica', 'B', 7);
            } else {
                $pdf->SetTextColor(146, 64, 14); // amber-800
                $pdf->SetFont('Helvetica', 'B', 7);
            }
            $pdf->Cell($colW['status'], 6, $statusShort, 1, 1, 'C', true);

            $pdf->SetFont('Helvetica', '', 7.5);
            $pdf->SetTextColor(30, 41, 59);
            $fill = !$fill;
            $idx++;
        }
    }

    // Totals Row
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetFillColor(241, 245, 249);
    $headerSpan = $colW['no'] + $colW['ref'] + $colW['beneficiary'] + $colW['applicant'] + $colW['barangay'] + $colW['assistance'];
    $pdf->Cell($headerSpan, 7, 'TOTAL BENEFICIARIES: ' . $recordCount . '  |  DISBURSED AMOUNT:', 1, 0, 'R', true);
    $pdf->Cell($colW['amount'], 7, number_format($totalAmount, 2), 1, 0, 'R', true);
    $pdf->Cell($colW['sched_date'] + $colW['batch'] + $colW['status'], 7, '', 1, 1, 'C', true);

    // Signature certification block
    $pdf->Ln(8);
    if ($pdf->GetY() > 150) {
        $pdf->AddPage();
    }
    
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->SetLineWidth(0.4);
    $pdf->Line(10, $pdf->GetY(), 287, $pdf->GetY());
    $pdf->Ln(6);

    $sigY = $pdf->GetY();
    // Prepared by
    $pdf->SetXY(16, $sigY);
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(90, 4, 'PREPARED AND CERTIFIED CORRECT:', 0, 1, 'L');
    $pdf->Ln(10);
    $pdf->SetX(16);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(90, 5, strtoupper($pdf->preparedBy), 'B', 1, 'L');
    $pdf->SetX(16);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(90, 4, 'Provincial Social Welfare and Development Office (PSWDO) - Antique', 0, 1, 'L');

    // Noted by / MSWDO Coordination
    $pdf->SetXY(180, $sigY);
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(90, 4, 'TRANSMITTED TO MUNICIPAL SOCIAL WELFARE OFFICE:', 0, 1, 'L');
    $pdf->Ln(10);
    $pdf->SetX(180);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetTextColor(15, 23, 42);
    $mswdoHeader = $cleanMuni !== 'All-Antique' ? 'MSWDO - ' . strtoupper($cleanMuni) : 'ALL MUNICIPAL SOCIAL WELFARE OFFICES';
    $pdf->Cell(90, 5, $mswdoHeader, 'B', 1, 'L');
    $pdf->SetX(180);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(90, 4, 'Municipality of ' . $cleanMuni . ', Antique | Received Date: ____________', 0, 1, 'L');

    $binary = $pdf->Output('S');

    return [
        'filename' => $filename,
        'binary' => $binary,
        'record_count' => $recordCount,
        'total_amount' => $totalAmount,
        'municipality' => $cleanMuni,
    ];
}

// -------------------------------------------------------------
// Direct invocation via HTTP GET to view/download PDF
// -------------------------------------------------------------
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    $accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
    $displayName = pswdo_current_display_name();

    $municipality = trim((string) ($_GET['municipality'] ?? 'Hamtic'));
    $status = trim((string) ($_GET['status'] ?? 'all'));
    $search = trim((string) ($_GET['search'] ?? ''));
    $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
    $dateTo = trim((string) ($_GET['date_to'] ?? ''));
    $rawIds = trim((string) ($_GET['ids'] ?? ''));

    $requestIds = [];
    if ($rawIds !== '') {
        $requestIds = array_values(array_filter(array_map('intval', explode(',', $rawIds))));
    }

    $download = (int) ($_GET['download'] ?? 1);

    try {
        $connection = aics_db_connect();
        pswdo_ensure_schema($connection);

        $pdfData = pswdo_generate_release_pdf_binary(
            $connection,
            $municipality,
            [
                'status' => $status,
                'search' => $search,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'request_ids' => $requestIds,
            ],
            $displayName
        );

        $connection->close();

        header('Content-Type: application/pdf');
        $disposition = $download ? 'attachment' : 'inline';
        header('Content-Disposition: ' . $disposition . '; filename="' . $pdfData['filename'] . '"');
        header('Content-Length: ' . strlen($pdfData['binary']));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        echo $pdfData['binary'];
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain');
        echo 'Failed to generate PDF: ' . $e->getMessage();
        exit;
    }
}
