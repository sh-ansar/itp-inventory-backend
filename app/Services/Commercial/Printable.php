<?php

namespace App\Services\Commercial;

use Dompdf\Dompdf;
use Dompdf\Options;

final class Printable
{
    private static function h(mixed $text): string
    {
        return htmlspecialchars((string)$text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }

    private function respond(string $html,string $filename)
    {
        abort_unless(class_exists(Dompdf::class),503,'PDF renderer is unavailable.');
        $opts = new Options();
        $opts->set('isRemoteEnabled',false);
        $opts->set('isPhpEnabled',false);
        $opts->set('isJavascriptEnabled',false);
        $opts->set('defaultFont','DejaVu Sans');
        $pdf = new Dompdf($opts);
        $pdf->setPaper('A4');
        $pdf->loadHtml('<!doctype html><html><head><meta charset="UTF-8">'.
          '<style>body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#193744}'.
          'h1{font-size:22px;color:#009cde}table{width:100%;border-collapse:collapse;margin:20px 0}'.
          'th,td{border:1px solid #cfdee5;padding:8px;text-align:left;vertical-align:top}'.
          'th{background:#f0f7fa}.muted{color:#789}pre{white-space:pre-wrap;font:inherit}</style>'.
          '</head><body>'.$html.'</body></html>','UTF-8');
        $pdf->render();
        return response($pdf->output(),200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'inline; filename="'.$filename.'"',
            'Cache-Control'=>'private,no-store',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function invoice(object $invoice)
    {
        $supplier=json_decode($invoice->supplier_snapshot??'{}',true)?:[];
        $buyer=json_decode($invoice->buyer_snapshot??'{}',true)?:[];
        $items=json_decode($invoice->items,true)?:[];
        $html='<h1>ITP Inventory · '.self::h($invoice->number??'Invoice preview').'</h1>';
        $html.='<p><strong>Supplier:</strong> '.self::h($supplier['name']??'—').'<br>'.
             '<strong>Customer:</strong> '.self::h($buyer['name']??'—').'<br>'.
             '<strong>Period:</strong> '.self::h($invoice->period_from).' — '.self::h($invoice->period_to).'<br>'.
             '<strong>Due:</strong> '.self::h($invoice->due_date).'</p>';
        $html.='<p>'.self::h($invoice->basis).'</p><table><thead><tr>'.
             '<th>Description</th><th>Quantity</th><th>Unit price</th><th>Amount</th></tr></thead><tbody>';
        foreach ($items as $item) {
            $qty=(int)($item['quantity']??0);$unit=(int)($item['unit_minor']??0);
            $html.='<tr><td>'.self::h($item['description']??'').'</td><td>'.$qty.'</td>'.
                '<td>'.number_format($unit/100,2,'.',' ').'</td>'.
                '<td>'.number_format($qty*$unit/100,2,'.',' ').'</td></tr>';
        }
        $html.='</tbody></table><h2>Total: '.number_format($invoice->total_minor/100,2,'.',' ').
            ' '.self::h($invoice->currency).'</h2>';
        $html.='<p class="muted">Status: '.self::h($invoice->status).'</p>';
        return $this->respond($html,'inventory-invoice-'.$invoice->id.'.pdf');
    }

    public function legal(object $document)
    {
        $html='<h1>'.self::h($document->title).'</h1><p>'.
            self::h(strtoupper($document->locale)).' · Version '.$document->version.
            ' · '.self::h($document->jurisdiction).'</p><pre>'.
            self::h($document->body).'</pre><p class="muted">SHA-256: '.self::h($document->sha256).'</p>';
        return $this->respond($html,'inventory-legal-'.$document->id.'.pdf');
    }
}
