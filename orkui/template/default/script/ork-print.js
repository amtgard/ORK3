/*
 * ork-print.js — crash-safe table printing.
 *
 * Chrome 150 kills the renderer with RESULT_CODE_KILLED_BAD_MESSAGE when a
 * parent window calls print() on a popup it wrote into — which is exactly what
 * the DataTables `print` button does. A popup that prints ITSELF (via an inline
 * onload script) is fine. This helper uses that self-printing pattern, matching
 * the recommendations print (revised.js recsExportPrint) that works in Chrome 150.
 *
 * Usage:  orkPrintTable(dataTable [, title [, columnsSelector]])
 *   dataTable       - a DataTables API instance
 *   title           - optional heading/document title (defaults to document.title)
 *   columnsSelector - optional DataTables column selector for what to export
 *                     (defaults to ':visible:not(.no-export)', same as the buttons)
 */
(function () {
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c];
        });
    }

    window.orkPrintTable = function (dt, title, columns) {
        title = title || document.title || 'Report';
        columns = columns || ':visible:not(.no-export)';

        // exportData() respects the same column selector the CSV/print buttons use,
        // and returns already-flattened text (no nested markup) — keeps the print
        // document tiny and predictable.
        var data = dt.buttons.exportData({ columns: columns });

        var head = data.header.map(function (h) { return '<th>' + esc(h) + '</th>'; }).join('');
        var body = data.body.map(function (row) {
            return '<tr>' + row.map(function (c) { return '<td>' + esc(c) + '</td>'; }).join('') + '</tr>';
        }).join('');

        var win = window.open('', '_blank');
        if (!win) { alert('Please allow pop-ups for this site to print.'); return; }

        win.document.write(
            '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + esc(title) + '</title><style>' +
            'body{font-family:sans-serif;font-size:12px;padding:16px;color:#1a202c}' +
            'h2{font-size:15px;margin:0 0 12px;color:#2b6cb0}' +
            'table{border-collapse:collapse;width:100%}' +
            'th,td{border:1px solid #cbd5e0;padding:6px 8px;text-align:left;vertical-align:top}' +
            'th{background:#edf2f7;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.04em}' +
            'tr:nth-child(even) td{background:#f7fafc}' +
            '@media print{body{padding:0}}' +
            '</style></head><body>' +
            '<h2>' + esc(title) + '</h2>' +
            '<table><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table>' +
            // The popup prints ITSELF on load — the parent never calls win.print(),
            // which is what avoids the Chrome 150 renderer kill.
            '<script>window.onload=function(){window.print();}<\/script>' +
            '</body></html>'
        );
        win.document.close();
    };
})();
