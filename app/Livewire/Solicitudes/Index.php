<?php

namespace App\Livewire\Solicitudes;

use App\Models\SolicitudCotizacion;
use App\Support\Solicitudes;
use Carbon\Carbon;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Por cotizar')]
class Index extends Component
{
    use WithPagination;

    public const PESTANAS = ['pendiente' => 'Pendientes', 'en_proceso' => 'En proceso', 'cotizada' => 'Cotizadas', 'descartada' => 'Descartadas', 'tiempos' => 'Tiempos'];

    #[Url(as: 'pestana')]
    public string $pestana = 'pendiente';

    #[Url(as: 'q')]
    public string $buscar = '';

    #[Url]
    public string $mes = '';

    public string $mensaje = '';

    public string $error = '';

    public function mount(): void
    {
        abort_unless(Solicitudes::puedeUsar(auth()->user()), 403);
        if (! isset(self::PESTANAS[$this->pestana])) {
            $this->pestana = 'pendiente';
        }
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->mes)) {
            $this->mes = now()->format('Y-m');
        }
    }

    public function updatedPestana(): void
    {
        $this->resetPage();
    }

    public function updatedMes(): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->mes)) {
            $this->mes = now()->format('Y-m');
        }
    }

    public function updatedBuscar(): void
    {
        $this->resetPage();
    }

    public function tomar(int $id)
    {
        $this->mensaje = $this->error = '';
        $s = SolicitudCotizacion::findOrFail($id);
        if (! $s->cliente_id) {
            session()->flash('error', 'Escoge el cliente de la solicitud (o dalo de alta) y luego pulsa "Tomar y cotizar".');

            return $this->redirectRoute('solicitudes.ver', $s);
        }
        try {
            $cot = Solicitudes::tomar($s, auth()->user());
        } catch (DomainException $e) {
            $this->error = $e->getMessage();

            return null;
        }

        return $this->redirectRoute('cotizaciones.editar', $cot);
    }

    private function consulta(string $estatus)
    {
        $q = SolicitudCotizacion::where('estatus', $estatus)
            ->withCount('archivos')->with(['cliente:id,nombre', 'tomadaPor:id,name', 'cotizacion:id,folio']);

        if (trim($this->buscar) !== '') {
            foreach (array_slice(preg_split('/\s+/u', trim($this->buscar)) ?: [], 0, 6) as $p) {
                $like = '%'.addcslashes($p, '%_\\').'%';
                $q->where(fn ($w) => $w->where('cliente_texto', 'like', $like)->orWhere('detalle', 'like', $like)
                    // partidas es JSON: en MariaDB su intercalación es binaria (distingue mayúsculas), por eso LOWER.
                    ->orWhereRaw('LOWER(partidas) LIKE ?', [mb_strtolower($like)])->orWhere('contacto_texto', 'like', $like)
                    ->orWhereHas('cliente', fn ($c) => $c->where('nombre', 'like', $like)));
            }
        }

        // Las cotizadas (ya enviadas al cliente) se quitan de la lista al día; siguen contando en Tiempos y el buscador las encuentra.
        if ($estatus === 'cotizada' && trim($this->buscar) === '') {
            $q->where('updated_at', '>=', now()->subDay());
        }

        return $estatus === 'pendiente' ? $q->orderBy('recibida_at') : $q->orderByDesc('recibida_at');
    }

    public function render()
    {
        $datos = ['conteos' => SolicitudCotizacion::toBase()->selectRaw('estatus, COUNT(*) n')->groupBy('estatus')->pluck('n', 'estatus')->all()];
        $datos['conteos']['cotizada'] = SolicitudCotizacion::where('estatus', 'cotizada')->where('updated_at', '>=', now()->subDay())->count();

        if ($this->pestana === 'tiempos') {
            $datos['metricas'] = Solicitudes::metricas($this->mes);
            // El trabajo por día de cada persona solo lo ve el admin.
            $datos['porDia'] = auth()->user()?->esAdmin() ? Solicitudes::porDia($this->mes) : null;
            $datos['mesTexto'] = Carbon::createFromFormat('Y-m-d', $this->mes.'-01')->locale('es')->translatedFormat('F Y');
        } else {
            $lista = $this->consulta($this->pestana)->paginate(20);
            $datos['solicitudes'] = $lista;
            $datos['envios'] = $this->pestana === 'cotizada' ? Solicitudes::primerosEnvios($lista->pluck('cotizacion_id')) : [];
        }

        return view('livewire.solicitudes.index', $datos);
    }
}
