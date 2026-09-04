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

function paymentReceiptOrdinal(int $number): string
{
    $number = max(1, $number);
    $lastTwoDigits = $number % 100;

    if ($lastTwoDigits >= 11 && $lastTwoDigits <= 13) {
        return $number . 'th';
    }

    $suffixes = [1 => 'st', 2 => 'nd', 3 => 'rd'];
    return $number . ($suffixes[$number % 10] ?? 'th');
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
    $pdf->SetFont('Helvetica', 'B', 19);
    $pdf->Cell(95, 9, 'PAYMENT RECEIPT', 0, 1);
    $pdf->SetX(15);
    $pdf->SetTextColor(...$mutedColor);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(95, 5, 'Official acknowledgement of fee payment', 0, 1);

    $pdf->SetFillColor(227, 248, 238);
    $pdf->SetDrawColor(182, 231, 207);
    $pdf->roundedRect(150, 48, 45, 12, 3, 'FD');
    $pdf->SetXY(153, 51);
    $pdf->SetTextColor(20, 128, 82);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(39, 6, 'PAYMENT RECEIVED', 0, 0, 'C');

    $pdf->SetFillColor(...$softBlue);
    $pdf->SetDrawColor(...$borderColor);
    $pdf->roundedRect(15, 67, 180, 18, 3, 'FD');

    $summary = [
        ['RECEIPT NUMBER', $receipt['receipt_no']],
        ['PAYMENT DATE', $receipt['payment_date']],
        ['PAYMENT METHOD', $receipt['payment_method']]
    ];
    $summaryX = [22, 83, 144];
    foreach ($summary as $index => $item) {
        $pdf->SetXY($summaryX[$index], 71);
        $pdf->SetTextColor(...$mutedColor);
        $pdf->SetFont('Helvetica', 'B', 7.5);
        $pdf->Cell(46, 4, $item[0], 0, 1);
        $pdf->SetX($summaryX[$index]);
        $pdf->SetTextColor(...$blueColor3);
        $pdf->SetFont('Helvetica', 'B', 10.5);
        $pdf->Cell(46, 6, paymentReceiptText($item[1]), 0, 0);
    }

    $pdf->SetXY(15, 98);
    $pdf->SetTextColor(...$blueColor3);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(180, 7, 'STUDENT DETAILS', 0, 1);

    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetDrawColor(...$borderColor);
    $pdf->roundedRect(15, 107, 180, 34, 3, 'FD');

    $studentDetails = [
        ['Student name', $receipt['student_name'], 21, 112],
        ['Student ID', $receipt['student_id'], 112, 112],
        ['Class & section', $receipt['class'] . ' - ' . $receipt['section'], 21, 127],
        ['Academic session', $receipt['session_name'], 112, 127]
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

    $pdf->SetXY(15, 149);
    $pdf->SetTextColor(...$blueColor3);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(180, 7, 'PAYMENT BREAKDOWN', 0, 1);

    $tableY = 158;
    $pdf->SetFillColor(...$blueColor3);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->SetXY(15, $tableY);
    $pdf->Cell(12, 9, '#', 0, 0, 'C', true);
    $pdf->Cell(88, 9, 'FEE / INSTALLMENT', 0, 0, 'L', true);
    $pdf->Cell(42, 9, 'SCHEDULE', 0, 0, 'L', true);
    $pdf->Cell(34, 9, 'AMOUNT', 0, 0, 'R', true);
    $pdf->Cell(4, 9, '', 0, 1, 'L', true);

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
        $installmentNumber = paymentReceiptOrdinal((int)($payment['installment_number'] ?? ($index + 1)));
        $feeLabel = ($payment['fee_name'] ?? 'Fee') . ' - ' . $installmentNumber . ' Installment';
        $pdf->Cell(88, 9, paymentReceiptText($feeLabel), 0, 0, 'L', true);
        $pdf->Cell(42, 9, paymentReceiptText($payment['scheduled_date'] ?? '-'), 0, 0, 'L', true);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(34, 9, paymentReceiptAmount($payment['amount']), 0, 0, 'R', true);
        $pdf->Cell(4, 9, '', 0, 1, 'L', true);
        $pdf->SetDrawColor(...$borderColor);
        $pdf->Line(15, $rowY + 9, 195, $rowY + 9);
        $rowY += 9;
    }

    $totalY = $rowY + 8;
    if ($totalY > 245) {
        $pdf->AddPage();
        $totalY = 30;
    }

    $cardWidth = 56;
    $cardGap = 6;
    $totalCards = [
        ['INSTALLMENT TOTAL', $receipt['total_installment_amount'] ?? $receipt['total_amount'], false],
        ['PAID AMOUNT', $receipt['total_paid_to_date'] ?? $receipt['total_amount'], true],
        ['DUE AMOUNT', $receipt['total_due_amount'] ?? 0, false]
    ];

    foreach ($totalCards as $index => $totalCard) {
        $cardX = 15 + (($cardWidth + $cardGap) * $index);
        if ($totalCard[2]) {
            $pdf->SetFillColor(...$themeColor);
            $pdf->SetDrawColor(...$themeColor);
            $labelColor = [235, 249, 255];
            $valueColor = [255, 255, 255];
        } else {
            $pdf->SetFillColor(...$softBlue);
            $pdf->SetDrawColor(...$borderColor);
            $labelColor = $mutedColor;
            $valueColor = $blueColor3;
        }

        $pdf->roundedRect($cardX, $totalY, $cardWidth, 21, 3, 'FD');
        $pdf->SetXY($cardX + 4, $totalY + 4);
        $pdf->SetTextColor(...$labelColor);
        $pdf->SetFont('Helvetica', 'B', 7.5);
        $pdf->Cell($cardWidth - 8, 4, $totalCard[0], 0, 1, 'C');
        $pdf->SetX($cardX + 4);
        $pdf->SetTextColor(...$valueColor);
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->Cell($cardWidth - 8, 8, paymentReceiptAmount($totalCard[1]), 0, 0, 'C');
    }

    $pdf->SetXY(15, $totalY + 23);
    $pdf->SetTextColor(...$mutedColor);
    $pdf->SetFont('Helvetica', '', 7.5);
    $pdf->Cell(180, 4, 'Amount received with this receipt: ' . paymentReceiptAmount($receipt['total_amount']), 0, 0, 'R');

    $footerY = max($totalY + 34, 267);
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
