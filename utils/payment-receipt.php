<?php

require_once __DIR__ . '/../FPDF/fpdf.php';

class PaymentReceiptPdf extends FPDF
{
    public function roundedRect($x, $y, $width, $height, $radius, $style = 'D'): void
    {
        $k = $this->k;
        $pageHeight = $this->h;
        $operation = $style === 'F' ? 'f' : ($style === 'FD' || $style === 'DF' ? 'B' : 'S');
        $curve = 4 / 3 * (sqrt(2) - 1);

        $this->_out(sprintf('%.2F %.2F m', ($x + $radius) * $k, ($pageHeight - $y) * $k));
        $this->_out(sprintf('%.2F %.2F l', ($x + $width - $radius) * $k, ($pageHeight - $y) * $k));
        $this->_arc(
            ($x + $width - $radius + $radius * $curve) * $k,
            ($pageHeight - $y) * $k,
            ($x + $width) * $k,
            ($pageHeight - ($y + $radius - $radius * $curve)) * $k,
            ($x + $width) * $k,
            ($pageHeight - ($y + $radius)) * $k
        );
        $this->_out(sprintf('%.2F %.2F l', ($x + $width) * $k, ($pageHeight - ($y + $height - $radius)) * $k));
        $this->_arc(
            ($x + $width) * $k,
            ($pageHeight - ($y + $height - $radius + $radius * $curve)) * $k,
            ($x + $width - $radius + $radius * $curve) * $k,
            ($pageHeight - ($y + $height)) * $k,
            ($x + $width - $radius) * $k,
            ($pageHeight - ($y + $height)) * $k
        );
        $this->_out(sprintf('%.2F %.2F l', ($x + $radius) * $k, ($pageHeight - ($y + $height)) * $k));
        $this->_arc(
            ($x + $radius - $radius * $curve) * $k,
            ($pageHeight - ($y + $height)) * $k,
            $x * $k,
            ($pageHeight - ($y + $height - $radius + $radius * $curve)) * $k,
            $x * $k,
            ($pageHeight - ($y + $height - $radius)) * $k
        );
        $this->_out(sprintf('%.2F %.2F l', $x * $k, ($pageHeight - ($y + $radius)) * $k));
        $this->_arc(
            $x * $k,
            ($pageHeight - ($y + $radius - $radius * $curve)) * $k,
            ($x + $radius - $radius * $curve) * $k,
            ($pageHeight - $y) * $k,
            ($x + $radius) * $k,
            ($pageHeight - $y) * $k
        );
        $this->_out($operation);
    }

    protected function _arc($x1, $y1, $x2, $y2, $x3, $y3): void
    {
        $this->_out(sprintf(
            '%.2F %.2F %.2F %.2F %.2F %.2F c',
            $x1,
            $y1,
            $x2,
            $y2,
            $x3,
            $y3
        ));
    }
}

function paymentReceiptText($value): string
{
    $value = (string)$value;
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            return $converted;
        }
    }

    return preg_replace('/[^\x20-\x7E]/', '', $value);
}

function paymentReceiptAmount($amount): string
{
    return 'INR ' . number_format((float)$amount, 2, '.', ',');
}

function buildPaymentReceiptPdf(array $receipt): string
{
    $themeColor = [29, 161, 242];
    $blueColor3 = [0, 101, 141];
    $inkColor = [29, 45, 59];
    $mutedColor = [93, 112, 126];
    $borderColor = [216, 231, 239];
    $softBlue = [240, 249, 254];

    $pdf = new PaymentReceiptPdf('P', 'mm', 'A4');
    $pdf->SetTitle(paymentReceiptText('Payment Receipt - ' . $receipt['receipt_no']));
    $pdf->SetAuthor(paymentReceiptText($receipt['institution_name']));
    $pdf->SetMargins(15, 14, 15);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();

    $pdf->SetFillColor(...$blueColor3);
    $pdf->Rect(0, 0, 210, 7, 'F');
    $pdf->SetFillColor(...$themeColor);
    $pdf->Rect(0, 7, 210, 2, 'F');

    $logoPath = $receipt['logo_path'] ?? '';
    if ($logoPath !== '' && is_file($logoPath)) {
        $pdf->Image($logoPath, 15, 16, 52);
    }

    $pdf->SetXY(74, 16);
    $pdf->SetTextColor(...$blueColor3);
    $pdf->SetFont('Helvetica', 'B', 18);
    $pdf->Cell(121, 8, paymentReceiptText($receipt['institution_name']), 0, 1, 'R');

    $institutionLine = implode('  |  ', array_filter([
        $receipt['institution_phone'] ?? '',
        $receipt['institution_email'] ?? ''
    ]));
    $pdf->SetX(74);
    $pdf->SetTextColor(...$mutedColor);
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->Cell(121, 5, paymentReceiptText($institutionLine), 0, 1, 'R');
    $pdf->SetX(74);
    $pdf->Cell(121, 5, paymentReceiptText($receipt['institution_address'] ?? ''), 0, 1, 'R');

    $pdf->SetDrawColor(...$borderColor);
    $pdf->Line(15, 39, 195, 39);

    $pdf->SetXY(15, 47);
    $pdf->SetTextColor(...$inkColor);
    $pdf->SetFont('Helvetica', 'B', 22);
    $pdf->Cell(95, 9, 'PAYMENT RECEIPT', 0, 1);
    $pdf->SetX(15);
    $pdf->SetTextColor(...$mutedColor);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(95, 5, 'Official acknowledgement of fee payment', 0, 1);

    $pdf->SetFillColor(227, 248, 238);
    $pdf->SetDrawColor(182, 231, 207);
    $pdf->roundedRect(157, 48, 38, 12, 3, 'FD');
    $pdf->SetXY(157, 51);
    $pdf->SetTextColor(20, 128, 82);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(38, 6, 'PAYMENT RECEIVED', 0, 0, 'C');

    $pdf->SetFillColor(...$softBlue);
    $pdf->SetDrawColor(...$borderColor);
    $pdf->roundedRect(15, 68, 180, 29, 3, 'FD');

    $summary = [
        ['RECEIPT NUMBER', $receipt['receipt_no']],
        ['PAYMENT DATE', $receipt['payment_date']],
        ['PAYMENT METHOD', $receipt['payment_method']]
    ];
    $summaryX = [22, 83, 144];
    foreach ($summary as $index => $item) {
        $pdf->SetXY($summaryX[$index], 75);
        $pdf->SetTextColor(...$mutedColor);
        $pdf->SetFont('Helvetica', 'B', 7.5);
        $pdf->Cell(46, 5, $item[0], 0, 1);
        $pdf->SetX($summaryX[$index]);
        $pdf->SetTextColor(...$blueColor3);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->Cell(46, 7, paymentReceiptText($item[1]), 0, 0);
    }

    $pdf->SetXY(15, 106);
    $pdf->SetTextColor(...$blueColor3);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(180, 7, 'STUDENT DETAILS', 0, 1);

    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetDrawColor(...$borderColor);
    $pdf->roundedRect(15, 115, 180, 35, 3, 'FD');

    $studentDetails = [
        ['Student name', $receipt['student_name'], 21, 121],
        ['Student ID', $receipt['student_id'], 112, 121],
        ['Class & section', $receipt['class'] . ' - ' . $receipt['section'], 21, 136],
        ['Academic session', $receipt['session_name'], 112, 136]
    ];
    foreach ($studentDetails as $detail) {
        $pdf->SetXY($detail[2], $detail[3]);
        $pdf->SetTextColor(...$mutedColor);
        $pdf->SetFont('Helvetica', '', 7.5);
        $pdf->Cell(76, 4, strtoupper(paymentReceiptText($detail[0])), 0, 1);
        $pdf->SetX($detail[2]);
        $pdf->SetTextColor(...$inkColor);
        $pdf->SetFont('Helvetica', 'B', 10.5);
        $pdf->Cell(76, 6, paymentReceiptText($detail[1]), 0, 0);
    }

    $pdf->SetXY(15, 159);
    $pdf->SetTextColor(...$blueColor3);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(180, 7, 'PAYMENT BREAKDOWN', 0, 1);

    $tableY = 168;
    $pdf->SetFillColor(...$blueColor3);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->SetXY(15, $tableY);
    $pdf->Cell(12, 9, '#', 0, 0, 'C', true);
    $pdf->Cell(88, 9, 'FEE / INSTALLMENT', 0, 0, 'L', true);
    $pdf->Cell(42, 9, 'SCHEDULE', 0, 0, 'L', true);
    $pdf->Cell(38, 9, 'AMOUNT', 0, 1, 'R', true);

    $rowY = $tableY + 9;
    foreach ($receipt['payments'] as $index => $payment) {
        if ($rowY > 247) {
            $pdf->AddPage();
            $rowY = 25;
        }

        $pdf->SetFillColor($index % 2 === 0 ? 248 : 255, $index % 2 === 0 ? 252 : 255, $index % 2 === 0 ? 254 : 255);
        $pdf->SetTextColor(...$inkColor);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetXY(15, $rowY);
        $pdf->Cell(12, 9, (string)($index + 1), 0, 0, 'C', true);
        $feeLabel = ($payment['fee_name'] ?? 'Fee') . ' - Installment #' . $payment['installment_id'];
        $pdf->Cell(88, 9, paymentReceiptText($feeLabel), 0, 0, 'L', true);
        $pdf->Cell(42, 9, paymentReceiptText($payment['scheduled_date'] ?? '-'), 0, 0, 'L', true);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(38, 9, paymentReceiptAmount($payment['amount']), 0, 1, 'R', true);
        $pdf->SetDrawColor(...$borderColor);
        $pdf->Line(15, $rowY + 9, 195, $rowY + 9);
        $rowY += 9;
    }

    $totalY = $rowY + 8;
    if ($totalY > 251) {
        $pdf->AddPage();
        $totalY = 30;
    }

    $pdf->SetFillColor(...$themeColor);
    $pdf->roundedRect(112, $totalY, 83, 21, 3, 'F');
    $pdf->SetXY(119, $totalY + 4);
    $pdf->SetTextColor(235, 249, 255);
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->Cell(68, 5, 'TOTAL AMOUNT RECEIVED', 0, 1, 'R');
    $pdf->SetX(119);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 17);
    $pdf->Cell(68, 8, paymentReceiptAmount($receipt['total_amount']), 0, 0, 'R');

    $footerY = max($totalY + 31, 267);
    if ($footerY > 277) {
        $pdf->AddPage();
        $footerY = 260;
    }
    $pdf->SetDrawColor(...$borderColor);
    $pdf->Line(15, $footerY, 195, $footerY);
    $pdf->SetXY(15, $footerY + 4);
    $pdf->SetTextColor(...$mutedColor);
    $pdf->SetFont('Helvetica', '', 7.5);
    $pdf->Cell(180, 4, 'This is a system-generated receipt and does not require a signature.', 0, 1, 'C');
    $pdf->SetX(15);
    $pdf->Cell(180, 4, paymentReceiptText('Generated securely by Edu Connekt for ' . $receipt['institution_name']), 0, 1, 'C');

    return $pdf->Output('S');
}
