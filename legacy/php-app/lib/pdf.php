<?php
// lib/pdf.php
use Dompdf\Dompdf;
use Dompdf\Options;

if (!class_exists(Dompdf::class)) {
  $vendor = __DIR__ . '/../vendor/autoload.php';
  if (file_exists($vendor)) {
    require_once $vendor;
  }
}

/**
 * Render a branded PDF with a professional header (logo/name/address/contacts),
 * a strong document title, and a sticky footer with timestamp + page numbers.
 *
 * @param string $bodyHtml  Your page-specific HTML (only the inner body, no <html> wrapper needed)
 * @param string $docTitle  Heading displayed under the company header
 * @param string $filename  Output file name (e.g., "Receipt_123.pdf")
 * @param bool   $download  True to force download; false to open inline
 * @param array  $company   Override company info if needed
 */
function render_branded_pdf(string $bodyHtml, string $docTitle, string $filename = 'document.pdf', bool $download = true, array $company = []): void
{
  // ---- Company defaults (pull from config constants if available)
  $companyDefaults = [
    'name'    => defined('COMPANY_NAME')    ? COMPANY_NAME    : 'My Company Ltd.',
    'address' => defined('COMPANY_ADDRESS') ? COMPANY_ADDRESS : 'Street · City · Country',
    'phone'   => defined('COMPANY_PHONE')   ? COMPANY_PHONE   : '+000 000 000',
    'email'   => defined('COMPANY_EMAIL')   ? COMPANY_EMAIL   : 'info@example.com',
    // Optional: define('COMPANY_LOGO_URL', BASE_URL . '/assets/logo.png');
    'logo'    => defined('COMPANY_LOGO_URL') ? COMPANY_LOGO_URL : null,
    'website' => defined('BASE_URL') ? BASE_URL : '',
  ];
  $co = array_merge($companyDefaults, $company);

  // ---- Timestamp (Africa/Kampala)
  date_default_timezone_set('Africa/Kampala');
  $generatedAt = date('Y-m-d H:i:s') . ' EAT';

  // ---- Build full HTML with clean, professional CSS
  $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>{$docTitle}</title>
  <style>
    @page {
      margin: 120px 32px 70px 32px; /* top right bottom left */
    }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, "Noto Sans", sans-serif;
      font-size: 12px;
      color: #0f172a; /* slate-900 */
      line-height: 1.45;
    }
    /* --- Fixed header --- */
    .pdf-header {
      position: fixed;
      top: -90px;
      left: 0;
      right: 0;
      height: 90px;
      border-bottom: 1px solid #e5e7eb;
      padding: 12px 0 10px 0;
    }
    .h-wrap {
      width: 100%;
      display: table;
    }
    .h-left { display: table-cell; vertical-align: middle; width: 64px; }
    .h-center { display: table-cell; vertical-align: middle; }
    .h-right { display: table-cell; vertical-align: middle; text-align: right; white-space: nowrap; }
    .logo {
      width: 56px; height: 56px; border-radius: 8px; object-fit: contain; border: 1px solid #e5e7eb;
    }
    .co-name { font-size: 16px; font-weight: 700; margin: 0 0 2px 0; }
    .co-meta { font-size: 10px; color: #475569; } /* slate-600 */

    .doc-title {
      margin: 14px 0 0 0;
      font-size: 18px;
      font-weight: 700;
      letter-spacing: 0.3px;
    }

    /* --- Fixed footer --- */
    .pdf-footer {
      position: fixed;
      bottom: -50px;
      left: 0;
      right: 0;
      height: 50px;
      border-top: 1px solid #e5e7eb;
      font-size: 10px;
      color: #475569;
      display: table;
      width: 100%;
    }
    .f-left { display: table-cell; vertical-align: middle; }
    .f-center { display: table-cell; vertical-align: middle; text-align: center; }
    .f-right { display: table-cell; vertical-align: middle; text-align: right; }
    .muted { color: #64748b; }

    /* --- Content blocks --- */
    .section { margin: 10px 0 0 0; }
    .card {
      border: 1px solid #e5e7eb;
      border-radius: 8px;
      padding: 12px;
      margin-bottom: 10px;
    }

    /* --- Tables --- */
    table.doc-table {
      width: 100%;
      border-collapse: collapse;
    }
    table.doc-table thead th {
      text-align: left;
      padding: 8px 6px;
      font-size: 11px;
      color: #334155; /* slate-700 */
      background: #f8fafc; /* slate-50 */
      border-bottom: 1px solid #e2e8f0;
    }
    table.doc-table tbody td {
      padding: 8px 6px;
      border-bottom: 1px solid #f1f5f9;
    }
    table.doc-table tfoot td {
      padding: 8px 6px;
      border-top: 1px solid #e2e8f0;
      font-weight: 700;
    }
    .text-right { text-align: right; }
    .text-center { text-align: center; }
    .badge {
      display: inline-block; font-size: 10px; padding: 2px 6px; border: 1px solid #e5e7eb; border-radius: 999px;
      background: #f8fafc; color: #0f172a;
    }
  </style>
</head>
<body>

  <!-- HEADER -->
  <div class="pdf-header">
    <div class="h-wrap">
      <div class="h-left">
        <!-- Optional Logo -->
        <!-- If no logo URL, we hide the image box via inline style -->
        <img src="{$co['logo']}" class="logo" alt="Logo" style="display:{$co['logo'] ? 'block' : 'none'};" />
      </div>
      <div class="h-center">
        <div class="co-name">{$co['name']}</div>
        <div class="co-meta">{$co['address']}</div>
        <div class="co-meta">Tel: {$co['phone']} · {$co['email']}</div>
        <div class="doc-title">{$docTitle}</div>
      </div>
      <div class="h-right">
        <span class="badge">{$co['website']}</span>
      </div>
    </div>
  </div>

  <!-- FOOTER -->
  <div class="pdf-footer">
    <div class="f-left">
      Generated: {$generatedAt}
    </div>
    <div class="f-center">
      <!-- Page X of Y gets injected by Dompdf after render -->
      <span class="muted">Page </span><span class="page-num"></span><span class="muted"> of </span><span class="page-count"></span>
    </div>
    <div class="f-right">
      {$co['name']}
    </div>
  </div>

  <!-- BODY -->
  <div class="section">
    {$bodyHtml}
  </div>

</body>
</html>
HTML;

  // ---- Dompdf setup
  $options = new Options();
  $options->set('isRemoteEnabled', true);
  $options->set('isHtml5ParserEnabled', true);
  $dompdf = new Dompdf($options);

  $dompdf->loadHtml($html, 'UTF-8');
  $dompdf->setPaper('A4', 'portrait');
  $dompdf->render();

  // Inject page numbers into the footer
  $canvas = $dompdf->getCanvas();
  $w = $canvas->get_width();
  $h = $canvas->get_height();
  $y = $h - 28; // a bit above bottom edge

  // Left (we already printed timestamp in HTML, but keep alignment consistent)
  // Center page numbers:
  $canvas->page_text($w/2 - 24, $y, "Page {PAGE_NUM} of {PAGE_COUNT}", null, 9, [0.39, 0.45, 0.55]); // slate-ish

  // Stream file
  $dompdf->stream($filename, ['Attachment' => $download ? 1 : 0]);
  exit;
}
