<?php
/**
 * Shared print layout template for MAEXX2 Enterprises.
 *
 * Usage:
 *   Include this file, then call maexx_print_header() / maexx_print_styles()
 *   in your pages, or use the JS helper printTable() for table-only prints.
 *
 * Matches the reference format:
 *   - Top-left: date/time generated
 *   - Top-right: company name + report title
 *   - Company header block with logo
 *   - Green separator
 *   - DATE / PERIOD line
 *   - Content (summary cards + table)
 *   - "Prepared by" footer
 *   - Page numbers (1/N)
 */

/**
 * Returns the print CSS styles to be embedded in <style> tags.
 */
function maexx_print_css(): string
{
    return <<<'CSS'
/* ========== MAEXX PRINT STYLES ========== */
@media print {
    /* Hide UI chrome */
    .sidebar, .topbar, .filter-bar, .no-print,
    .search-bar, .card-toolbar .toolbar-btns,
    .tab-bar, .flash-box, .alert-banner,
    .modal, .modal-backdrop,
    .btn-primary-custom, .btn-success-custom, .btn-danger-custom,
    .btn-print, .btn-export, .btn-print-table { display: none !important; }

    /* Reset layout */
    body { background: #fff !important; margin: 0; padding: 0; font-family: Arial, sans-serif; font-size: 11px; color: #000 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .main-wrapper { margin-left: 0 !important; }
    .page-content { padding: 0 !important; }

    /* Cards lose shadows */
    .main-card, .report-card, .stat-card, .stats-grid .stat-card {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
        break-inside: avoid;
    }

    /* Print header */
    .print-report-header { display: block !important; }

    /* Table print styles */
    table { width: 100%; border-collapse: collapse; font-size: 10px; }
    thead th { background: #e8edf3 !important; color: #1a2f47 !important; font-size: 9px; font-weight: 700; padding: 6px 8px; border: 1px solid #b8c5d4; text-align: left; }
    tbody td { padding: 5px 8px; border: 1px solid #ddd; color: #333 !important; font-size: 10px; }
    tbody tr:nth-child(even) { background: #fafafa !important; }
    tfoot td { font-weight: 700; border-top: 2px solid #243f5f; }

    /* Stat cards in print */
    .stats-grid { display: flex !important; gap: 10px; margin-bottom: 16px; }
    .stat-card { flex: 1; padding: 8px 12px !important; }
    .stat-value, .stat-info .value { font-size: 16px !important; }
    .stat-label, .stat-info .label { font-size: 9px !important; }
    .stat-icon { width: 30px !important; height: 30px !important; font-size: 14px !important; }

    /* Page breaks */
    .page-break-before { page-break-before: always; }
    .no-break { page-break-inside: avoid; }

    /* Colors for badges */
    .badge-status { border: 1px solid #999 !important; font-size: 9px !important; }
    .bs-ok, .bs-delivered { background: #e8f5e9 !important; color: #2e7d32 !important; }
    .bs-low, .bs-pending { background: #fff8e1 !important; color: #f57f17 !important; }
    .bs-out { background: #ffebee !important; color: #c62828 !important; }

    /* Footer */
    .print-report-footer { display: block !important; }
}

/* ========== Print Report Header (hidden on screen) ========== */
.print-report-header {
    display: none;
    padding: 0 0 10px 0;
    margin-bottom: 15px;
}
.print-report-header .prh-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}
.print-report-header .prh-company {
    flex: 1;
}
.print-report-header .prh-company-name {
    font-size: 18px;
    font-weight: 700;
    color: #243f5f;
    margin: 0 0 4px 0;
}
.print-report-header .prh-company-details {
    font-size: 9px;
    color: #555;
    line-height: 1.6;
    margin: 0;
}
.print-report-header .prh-logo {
    width: 70px;
    height: 70px;
    object-fit: contain;
    margin-left: 20px;
}
.print-report-header .prh-separator {
    border: none;
    border-top: 3px solid #243f5f;
    margin: 10px 0;
}
.print-report-header .prh-meta {
    font-size: 10px;
    color: #333;
    margin-bottom: 4px;
}
.print-report-header .prh-subject {
    font-size: 13px;
    font-weight: 700;
    color: #000;
    margin: 8px 0 12px 0;
    text-transform: uppercase;
}

/* ========== Print Report Footer (hidden on screen) ========== */
.print-report-footer {
    display: none;
    margin-top: 40px;
    padding-top: 20px;
}
.print-report-footer .prf-prepared {
    font-size: 10px;
    color: #333;
    margin-bottom: 30px;
}
.print-report-footer .prf-line {
    width: 200px;
    border-top: 1.5px solid #243f5f;
    margin-top: 30px;
    padding-top: 4px;
    font-size: 10px;
    font-weight: 700;
    color: #243f5f;
}

/* ========== Print-only table wrapper (for standalone table prints) ========== */
#printArea { display: none; }

@page {
    size: A4 portrait;
    margin: 15mm 12mm 15mm 12mm;
}

@page {
    @top-left  { content: counter(page) " of " counter(pages); font-size: 8px; }
    @top-right { content: "MAEXX2 Enterprises Inc."; font-size: 8px; }
}
CSS;
}

/**
 * Generates the print header HTML block.
 *
 * @param string $subject   e.g. "PRODUCT MONITORING LIST"
 * @param string $period    e.g. "September 2026" or date range
 * @param string $preparedBy  Name of the logged-in user
 */
function maexx_print_header_html(string $subject, string $period = '', string $preparedBy = ''): string
{
    $date = date('F j, Y');
    $periodLine = $period ? "<span class=\"prh-meta\"><strong>DATE:</strong> {$date} &nbsp;&nbsp; <strong>PERIOD:</strong> {$period}</span><br>" : "<span class=\"prh-meta\"><strong>DATE:</strong> {$date}</span><br>";

    return <<<HTML
<div class="print-report-header">
    <div class="prh-top">
        <div class="prh-company">
            <p class="prh-company-name">MAEXX2 ENTERPRISES INC.</p>
            <p class="prh-company-details">
                Inventory &amp; Sales Monitoring System<br>
                <strong>Email:</strong> maexx2enterprises@gmail.com<br>
                <strong>Generated:</strong> {$date}
            </p>
        </div>
        <img src="img/LOGO.png" alt="MAEXX Logo" class="prh-logo">
    </div>
    <hr class="prh-separator">
    {$periodLine}
    <div class="prh-subject">SUBJECT: {$subject}</div>
</div>
HTML;
}

/**
 * Generates the "Prepared by" footer HTML.
 */
function maexx_print_footer_html(string $preparedBy): string
{
    return <<<HTML
<div class="print-report-footer">
    <div class="prf-prepared">Prepared by:</div>
    <div class="prf-line">{$preparedBy}</div>
</div>
HTML;
}

/**
 * Generates the JavaScript for the printTable() helper function.
 * This opens a new window with just the table content, properly formatted.
 */
function maexx_print_js(): string
{
    return <<<'JS'
/**
 * Print a specific table with the MAEXX header/footer layout.
 * @param {string} tableId - The ID of the table element to print
 * @param {string} title   - Report subject title
 * @param {Object} opts    - Optional: {period, stats}
 *   stats: array of {label, value} for summary boxes
 */
function printTable(tableId, title, opts = {}) {
    const table = document.getElementById(tableId);
    if (!table) { alert('Table not found: ' + tableId); return; }

    const now = new Date();
    const dateStr = now.toLocaleDateString('en-US', {year:'numeric',month:'long',day:'numeric'});
    const timeStr = now.toLocaleTimeString('en-US', {hour:'2-digit',minute:'2-digit'});
    const period = opts.period || '';
    const preparedBy = document.querySelector('.user-info .name')?.textContent || 'Administrator';

    let statsHtml = '';
    if (opts.stats && opts.stats.length) {
        statsHtml = '<div style="display:flex;gap:12px;margin-bottom:16px;">';
        opts.stats.forEach(s => {
            statsHtml += `<div style="flex:1;border:1.5px solid #243f5f;border-radius:8px;padding:10px 14px;text-align:center;">
                <div style="font-size:20px;font-weight:700;color:#243f5f;">${s.value}</div>
                <div style="font-size:9px;color:#64748b;text-transform:uppercase;">${s.label}</div>
            </div>`;
        });
        statsHtml += '</div>';
    }

    const clone = table.cloneNode(true);
    // Remove action columns if they exist
    clone.querySelectorAll('.no-print, .action-col').forEach(el => el.remove());

    const html = `<!DOCTYPE html>
<html><head>
<meta charset="UTF-8">
<title>${title} - MAEXX2 Enterprises</title>
<style>
    @page { size: A4 portrait; margin: 12mm 10mm; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Arial, sans-serif; font-size: 11px; color: #000; padding: 0; }
    .header { margin-bottom: 12px; }
    .header-top { display: flex; justify-content: space-between; align-items: flex-start; }
    .company-name { font-size: 18px; font-weight: 700; color: #243f5f; margin-bottom: 4px; }
    .company-details { font-size: 9px; color: #555; line-height: 1.6; }
    .logo { width: 65px; height: 65px; object-fit: contain; }
    .sep { border: none; border-top: 3px solid #243f5f; margin: 10px 0; }
    .meta { font-size: 10px; color: #333; margin-bottom: 4px; }
    .subject { font-size: 13px; font-weight: 700; color: #243f5f; text-transform: uppercase; margin: 8px 0 14px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    th { background: #e8edf3; color: #1a2f47; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; padding: 6px 8px; border: 1px solid #b8c5d4; text-align: left; }
    td { font-size: 10px; padding: 5px 8px; border: 1px solid #ddd; }
    tr:nth-child(even) { background: #f5f7fa; }
    tfoot td { font-weight: 700; border-top: 2px solid #243f5f; background: #e8edf3; }
    .footer { margin-top: 40px; }
    .prepared { font-size: 10px; color: #333; }
    .sig-line { width: 200px; border-top: 1.5px solid #243f5f; margin-top: 35px; padding-top: 4px; font-size: 10px; font-weight: 700; color: #243f5f; }
</style>
</head><body>
<div class="header">
    <div class="header-top">
        <div>
            <div class="company-name">MAEXX2 ENTERPRISES INC.</div>
            <div class="company-details">
                Inventory &amp; Sales Monitoring System<br>
                Email: maexx2enterprises@gmail.com<br>
                Generated: ${dateStr} ${timeStr}
            </div>
        </div>
        <img src="img/LOGO.png" class="logo" onerror="this.style.display='none'">
    </div>
    <hr class="sep">
    <div class="meta"><strong>DATE:</strong> ${dateStr}${period ? ' &nbsp;&nbsp; <strong>PERIOD:</strong> ' + period : ''}</div>
    <div class="subject">SUBJECT: ${title}</div>
</div>
${statsHtml}
${clone.outerHTML}
<div class="footer">
    <div class="prepared">Prepared by:</div>
    <div class="sig-line">${preparedBy}</div>
</div>
<script>window.onload=function(){window.print();}<\/script>
</body></html>`;

    const w = window.open('', '_blank');
    w.document.write(html);
    w.document.close();
}

/**
 * Print the entire report page (uses window.print with @media print CSS).
 */
function printReport() {
    window.print();
}
JS;
}
