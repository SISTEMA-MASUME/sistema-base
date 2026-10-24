<?php

namespace App\Http\Controllers;

use App\Models\Cotizacion;
use App\Models\User;
use App\Support\CompartirDocumento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF de una cotización u OC a proveedor por enlace seguro (sin sesión). La ruta exige firma válida
 * y vigente (middleware "signed"); cada apertura se registra como canal "enlace".
 */
class DocumentoPublicoController extends Controller
{
    /** Vistas previas que generan WhatsApp y otras apps al pegar el enlace: no son una descarga real. */
    private const ROBOTS = '/WhatsApp|facebookexternalhit|Facebot|TelegramBot|Slackbot|Twitterbot|LinkedInBot|Googlebot|bingbot|Discordbot|SkypeUriPreview|bot\b|crawler|spider/i';

    public function __invoke(Request $request, string $tipo, int $id): Response
    {
        $doc = CompartirDocumento::buscar($tipo, $id);
        // Cotización: se manda la copia exacta de la revisión que se compartió (si se editó después, el cliente
        // sigue viendo lo que se le mandó). Si falta una copia versionada, no se sustituye por otro documento.
        $copia = $doc instanceof Cotizacion && $request->filled('r') ? CompartirDocumento::rutaCopia($doc, $request->integer('r')) : null;
        abort_if($copia && ! Storage::disk('local')->exists($copia), 410, 'La copia de este envío no está disponible. Solicita un nuevo enlace.');
        $bytes = $copia && Storage::disk('local')->exists($copia) ? Storage::disk('local')->get($copia) : CompartirDocumento::pdf($doc);

        $quien = User::find($request->integer('u'));
        if ($quien && ! preg_match(self::ROBOTS, (string) $request->userAgent())) {
            CompartirDocumento::registrar($doc, 'enlace', 'Enlace abierto desde '.$request->ip(), $quien->id);
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.CompartirDocumento::nombreArchivo($doc).'"',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
