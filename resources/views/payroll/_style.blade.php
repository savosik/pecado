{{-- Общий стиль печатных форм зарплаты: DejaVu Sans — единственный шрифт dompdf, знающий кириллицу. --}}
<style>
    @page { margin: 18mm 14mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1a1a1a; line-height: 1.45; }
    h1 { font-size: 19px; margin: 0 0 2px; }
    h2 { font-size: 13px; margin: 18px 0 6px; padding-bottom: 4px; border-bottom: 1px solid #d8d8d8; }
    h3 { font-size: 11px; margin: 12px 0 4px; }
    .muted { color: #6b6b6b; }
    .small { font-size: 9px; }
    .right { text-align: right; }
    .num { font-size: 11px; }
    .big { font-size: 22px; font-weight: bold; }
    .head { border-bottom: 2px solid #1a1a1a; padding-bottom: 8px; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-size: 9px; color: #6b6b6b; font-weight: normal; padding: 4px 6px; border-bottom: 1px solid #d8d8d8; }
    td { padding: 5px 6px; border-bottom: 1px solid #ececec; vertical-align: top; }
    tr.total td { border-top: 2px solid #1a1a1a; border-bottom: none; font-weight: bold; font-size: 12px; padding-top: 8px; }
    .box { border: 1px solid #d8d8d8; padding: 8px 10px; margin-bottom: 8px; }
    .step { border-left: 3px solid #c8c8c8; padding: 2px 0 2px 10px; margin-bottom: 10px; }
    .bar { height: 7px; background: #ececec; margin-top: 3px; }
    .bar > div { height: 7px; background: #555; }
    .neg { color: #b00020; }
    .pos { color: #1b7a3d; }
    .tag { display: inline-block; border: 1px solid #c8c8c8; padding: 0 4px; font-size: 8px; color: #555; }
    .foot { margin-top: 16px; padding-top: 6px; border-top: 1px solid #d8d8d8; font-size: 8px; color: #8a8a8a; }
    .cols { width: 100%; }
    .cols td { border: none; padding: 0 8px 0 0; vertical-align: top; }
</style>
