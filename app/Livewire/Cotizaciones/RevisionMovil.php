<?php

namespace App\Livewire\Cotizaciones;

use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Support\Bitacora;
use App\Support\Precios;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Revisar cotización')]
class RevisionMovil extends Component
{
    #[Locked]
    public int $cotizacionId;

    public string $mensaje = '';

    public function mount(Cotizacion $cotizacion): void
    {
        $this->autorizar();
        $this->cotizacionId = $cotizacion->id;
    }

    private function autorizar(): void
    {
        $usuario = auth()->user();
        abort_unless($usuario && $usuario->activo && ! $usuario->esCampo() && ! $usuario->esNed(), 403);
    }

    /** Detecta cambios de precio/costo/cantidad mientras se edita, sin bloquear cambios de descripción. */
    public static function version(CotizacionPartida $partida): string
    {
        return hash('sha256', json_encode([
            $partida->id, $partida->costo, $partida->cantidad, $partida->precio,
            $partida->utilidad_pct, $partida->incluir,
        ]));
    }

    /** Guarda únicamente la utilidad y sus importes derivados, sobre la misma cotización. */
    public function guardarUtilidad(int $partidaId, string $valor, string $version): ?array
    {
        $this->autorizar();
        $campo = 'utilidades.'.$partidaId;
        $this->resetErrorBag();
        $this->mensaje = '';
        $valor = str_replace(',', '.', trim($valor));
        $validator = Validator::make(['valor' => $valor], ['valor' => ['required', 'numeric', 'between:-100,99999.99']], [
            'valor.required' => 'Escribe el porcentaje de utilidad.',
            'valor.numeric' => 'Escribe un porcentaje válido.',
            'valor.between' => 'La utilidad debe estar entre -100 y 99,999.99%.',
        ]);
        if ($validator->fails()) {
            $this->addError($campo, $validator->errors()->first('valor'));

            return null;
        }

        try {
            return DB::transaction(function () use ($partidaId, $valor, $version, $campo) {
                $cot = Cotizacion::whereKey($this->cotizacionId)->lockForUpdate()->firstOrFail();
                $partida = $cot->partidas()->whereKey($partidaId)->lockForUpdate()->firstOrFail();
                if (! hash_equals(self::version($partida), $version)) {
                    $this->addError($campo, 'Los valores cambiaron en otra pantalla. Recarga la revisión antes de volver a editar. Tu cambio no se guardó.');

                    return null;
                }
                if ($partida->costo === null || (float) $partida->costo <= 0) {
                    $this->addError($campo, 'Primero captura el costo de esta partida en el cotizador.');

                    return null;
                }

                $utilidad = round((float) $valor, 2);
                $precio = Precios::precioDesdeUtilidad((float) $partida->costo, $utilidad);
                $partida->update([
                    'utilidad_pct' => $utilidad,
                    'precio' => $precio,
                    'importe' => Precios::importe($precio, (float) $partida->cantidad),
                ]);
                $cot->recalcularTotales();
                $this->mensaje = 'Utilidad de la partida '.$partida->orden.' guardada.';

                return ['valor' => number_format($utilidad, 2, '.', ''), 'version' => self::version($partida->fresh())];
            });
        } catch (ValidationException $e) {
            // Los límites de MariaDB y del total se validan también en los observers; la transacción se revierte.
            $this->addError($campo, collect($e->errors())->flatten()->first());

            return null;
        }
    }

    public function marcarRevisada(): void
    {
        $this->autorizar();
        DB::transaction(function () {
            $cot = Cotizacion::whereKey($this->cotizacionId)->lockForUpdate()->firstOrFail();
            $cot->forceFill(['revisada_at' => now(), 'revisada_por' => auth()->id()])->save();
            Bitacora::evento($cot, 'revisada');
        });
        $this->mensaje = 'Cotización marcada como revisada. Ya se puede mandar al cliente.';
        $this->dispatch('revision-actualizada');
    }

    public function render()
    {
        $this->autorizar();
        $cot = Cotizacion::with(['cliente', 'vendedor', 'revisionPedidaPor', 'revisadaPor'])->findOrFail($this->cotizacionId);
        $partidas = $cot->partidas()->with('proveedor:id,nombre')->get();
        $incluidas = $partidas->where('incluir', true);
        $sinCosto = $incluidas->filter(fn ($p) => $p->costo === null || (float) $p->costo <= 0)->count();
        $venta = round((float) $incluidas->sum('importe'), 2);
        $costo = round((float) $incluidas->sum(fn ($p) => (float) $p->costo * (float) $p->cantidad), 2);
        $utilidad = $sinCosto ? null : round($venta - $costo, 2);

        return view('livewire.cotizaciones.revision-movil', [
            'cot' => $cot, 'partidas' => $partidas,
            'totales' => Precios::totales([$venta]) + [
                'costo' => $sinCosto ? null : $costo, 'utilidad' => $utilidad,
                'utilidad_pct' => ! $sinCosto && $costo > 0 ? round($utilidad / $costo * 100, 2) : null,
                'sin_costo' => $sinCosto, 'incluidas' => $incluidas->count(),
            ],
        ]);
    }
}
