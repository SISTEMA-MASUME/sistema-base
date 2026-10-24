<?php

namespace App\Livewire\Vacaciones;

use App\Models\User;
use App\Models\Vacacion;
use App\Support\CalendarioVacaciones;
use App\Support\Vacaciones;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Vacaciones por antigüedad. Cada empleado ve cuántos días le quedan y registra las suyas (Daniel ya se las aprobó por
 * WhatsApp); al registrar se crea el evento en el calendario de calendario@ejemplo.com. El admin ve a todo el equipo,
 * registra por otros, agrega ajustes y edita fecha de ingreso, días propios y sábados. Todo se valida en el servidor.
 */
#[Layout('layouts.app')]
#[Title('Vacaciones')]
class Index extends Component
{
    public const MESES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    /** Admin: a quién se le registran (vacío = a mí). */
    public string $paraUsuario = '';

    public string $desde = '';

    public string $hasta = '';

    public string $nota = '';

    /** Se pasa del saldo: espera el "Sí, ya me las aprobó Daniel". */
    public bool $pedirConfirmacion = false;

    public ?string $mensaje = null;

    public ?string $aviso = null;

    public ?string $error = null;

    // Ajustes (admin)
    public string $ajUsuario = '';

    public string $ajDias = '';

    public string $ajFecha = '';

    public string $ajNota = '';

    // Edición de empleado (admin)
    public ?int $editandoId = null;

    public string $edIngreso = '';

    public string $edDias = '';

    public bool $edSabados = false;

    /** Mes del calendario, 'Y-m'. */
    public string $mesCal = '';

    public function mount(): void
    {
        $this->mesCal = now()->format('Y-m');
        $this->ajFecha = now()->toDateString();
    }

    private function admin(): bool
    {
        return (bool) auth()->user()?->esAdmin();
    }

    /** Persona a quien se le registran vacaciones: yo, o (solo admin) la que se eligió. */
    private function destino(): User
    {
        if ($this->admin() && $this->paraUsuario !== '') {
            $u = $this->equipoBase()->find((int) $this->paraUsuario);
            if ($u) {
                return $u;
            }
        }

        return auth()->user();
    }

    private function equipoBase()
    {
        return User::query()->where('activo', true)->whereNull('eliminado_at')->where('rol', '!=', 'nedtracking');
    }

    private function limpiar(): void
    {
        $this->mensaje = $this->aviso = $this->error = null;
    }

    public function updatedParaUsuario(): void
    {
        $this->pedirConfirmacion = false;
    }

    public function updatedDesde(): void
    {
        $this->pedirConfirmacion = false;
        if ($this->hasta === '' || $this->hasta < $this->desde) {
            $this->hasta = $this->desde;
        }
    }

    public function updatedHasta(): void
    {
        $this->pedirConfirmacion = false;
    }

    /** Días hábiles del rango escrito (null si las fechas no sirven). */
    private function diasDelRango(User $u): ?int
    {
        try {
            if ($this->desde === '' || $this->hasta === '') {
                return null;
            }
            $d = Carbon::createFromFormat('Y-m-d', $this->desde)->startOfDay();
            $h = Carbon::createFromFormat('Y-m-d', $this->hasta)->startOfDay();
            if ($h->lt($d) || $d->year < 2000 || $h->year > 2100 || $d->diffInDays($h) > 366) {
                return null;
            }

            return Vacaciones::diasHabiles($u, $d, $h);
        } catch (Throwable) {
            return null;
        }
    }

    public function registrar(bool $confirmado = false): void
    {
        $this->limpiar();
        $this->validate([
            'desde' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2101-01-01'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde', 'before:2101-01-01'],
            'nota' => ['nullable', 'string', 'max:500'],
        ], [], ['desde' => 'la fecha de inicio', 'hasta' => 'la fecha final']);

        $u = $this->destino();
        $dias = $this->diasDelRango($u);
        if (! $dias) {
            $this->error = 'Ese rango no tiene días hábiles (solo domingos, festivos o sábados que no se trabajan) o es demasiado largo.';

            return;
        }

        $traslape = Vacacion::where('user_id', $u->id)->where('tipo', 'tomadas')->where('estatus', 'registrada')
            ->where('desde', '<=', $this->hasta)->where('hasta', '>=', $this->desde)->exists();
        if ($traslape) {
            $this->error = 'Ya hay vacaciones registradas que se cruzan con esas fechas.';

            return;
        }

        $resumen = Vacaciones::resumen($u, Carbon::parse($this->desde));
        if ($resumen['calculable'] && $dias > $resumen['saldo'] && ! $confirmado) {
            $this->pedirConfirmacion = true;

            return;
        }

        $vacacion = Vacacion::create([
            'user_id' => $u->id, 'tipo' => 'tomadas', 'desde' => $this->desde, 'hasta' => $this->hasta, 'dias' => $dias,
            'notas' => trim($this->nota) ?: null, 'estatus' => 'registrada', 'registrada_por' => auth()->id(),
        ]);
        $vacacion->setRelation('usuario', $u);

        $evento = CalendarioVacaciones::crear($vacacion);
        if ($evento) {
            $vacacion->update(['evento_id' => $evento]);
        } else {
            $this->aviso = CalendarioVacaciones::aviso();
        }

        $this->mensaje = 'Listo: se registraron '.$dias.($dias === 1 ? ' día' : ' días').' de vacaciones'
            .($u->id !== auth()->id() ? ' de '.$u->nombreReal() : '').'.';
        $this->desde = $this->hasta = $this->nota = '';
        $this->pedirConfirmacion = false;
    }

    public function cancelarConfirmacion(): void
    {
        $this->pedirConfirmacion = false;
    }

    public function cancelar(int $id): void
    {
        $this->limpiar();
        $v = Vacacion::with('usuario')->where('tipo', 'tomadas')->where('estatus', 'registrada')->find($id);
        if (! $v || (! $this->admin() && ($v->user_id !== auth()->id() || $v->desde->lt(now()->startOfDay())))) {
            $this->error = 'No puedes cancelar esas vacaciones.';

            return;
        }
        $v->update(['estatus' => 'cancelada']);
        if (! CalendarioVacaciones::borrar($v)) {
            $this->aviso = CalendarioVacaciones::aviso(CalendarioVacaciones::AVISO_BORRAR);
        }
        $this->mensaje = 'Vacaciones canceladas.';
    }

    public function guardarAjuste(): void
    {
        abort_unless($this->admin(), 403);
        $this->limpiar();
        $this->ajDias = str_replace(',', '.', trim($this->ajDias));
        $this->validate([
            'ajUsuario' => ['required', 'integer'],
            'ajDias' => ['required', 'numeric', 'not_in:0', 'between:-60,60'],
            'ajFecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2101-01-01'],
            'ajNota' => ['required', 'string', 'min:3', 'max:300'],
        ], [], ['ajUsuario' => 'la persona', 'ajDias' => 'los días', 'ajFecha' => 'la fecha', 'ajNota' => 'la nota']);

        $u = $this->equipoBase()->find((int) $this->ajUsuario);
        if (! $u) {
            $this->error = 'Elige a una persona del equipo.';

            return;
        }
        Vacacion::create(['user_id' => $u->id, 'tipo' => 'ajuste', 'desde' => $this->ajFecha, 'dias' => round((float) $this->ajDias, 1),
            'notas' => trim($this->ajNota), 'estatus' => 'registrada', 'registrada_por' => auth()->id()]);
        $this->mensaje = 'Ajuste guardado para '.$u->nombreReal().'.';
        $this->ajDias = $this->ajNota = '';
    }

    public function editar(int $id): void
    {
        abort_unless($this->admin(), 403);
        $u = $this->equipoBase()->findOrFail($id);
        $this->editandoId = $u->id;
        $this->edIngreso = $u->fecha_ingreso?->toDateString() ?? '';
        $this->edDias = $u->vacaciones_dias_anuales !== null ? (string) $u->vacaciones_dias_anuales : '';
        $this->edSabados = (bool) $u->trabaja_sabados;
    }

    public function cancelarEdicion(): void
    {
        $this->editandoId = null;
    }

    public function guardarEmpleado(): void
    {
        abort_unless($this->admin(), 403);
        $this->limpiar();
        $this->validate([
            'edIngreso' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1980-01-01', 'before:2101-01-01'],
            'edDias' => ['nullable', 'integer', 'between:0,60'],
            'edSabados' => ['boolean'],
        ], [], ['edIngreso' => 'la fecha de ingreso', 'edDias' => 'los días por año']);

        $u = $this->equipoBase()->findOrFail($this->editandoId);
        $u->update([
            'fecha_ingreso' => $this->edIngreso !== '' ? $this->edIngreso : null,
            'vacaciones_dias_anuales' => $this->edDias !== '' ? (int) $this->edDias : null,
            'trabaja_sabados' => $this->edSabados,
        ]);
        $this->editandoId = null;
        $this->mensaje = 'Datos de '.$u->nombreReal().' guardados.';
    }

    public function mesAnterior(): void
    {
        $this->mesCal = Carbon::createFromFormat('Y-m-d', $this->mesCal.'-01')->subMonth()->format('Y-m');
    }

    public function mesSiguiente(): void
    {
        $this->mesCal = Carbon::createFromFormat('Y-m-d', $this->mesCal.'-01')->addMonth()->format('Y-m');
    }

    public function esteMes(): void
    {
        $this->mesCal = now()->format('Y-m');
    }

    /** @return array{inicio: Carbon, dias: array<string, list<string>>} quién está de vacaciones cada día del mes */
    private function calendario(): array
    {
        try {
            $inicio = Carbon::createFromFormat('Y-m-d', $this->mesCal.'-01')->startOfDay();
        } catch (Throwable) {
            $inicio = now()->startOfMonth();
        }
        $fin = $inicio->copy()->endOfMonth()->startOfDay();
        $dias = [];
        $filas = Vacacion::with('usuario')->where('tipo', 'tomadas')->where('estatus', 'registrada')
            ->where('desde', '<=', $fin->toDateString())->where('hasta', '>=', $inicio->toDateString())->get();
        foreach ($filas as $v) {
            if (! $v->usuario) {
                continue;
            }
            for ($d = $inicio->copy(); $d->lte($fin); $d->addDay()) {
                if ($d->betweenIncluded($v->desde, $v->hasta) && Vacaciones::esHabil($v->usuario, $d)) {
                    $dias[$d->toDateString()][] = $v->usuario->nombreReal();
                }
            }
        }

        return ['inicio' => $inicio, 'dias' => $dias];
    }

    public function render()
    {
        $yo = auth()->user();
        $hoy = now()->startOfDay();
        $destino = $this->destino();
        $resumenDestino = Vacaciones::resumen($destino, $this->desde !== '' && strtotime($this->desde) ? Carbon::parse($this->desde) : $hoy);
        $diasRango = $this->diasDelRango($destino);

        $mias = Vacacion::where('user_id', $yo->id)->where('estatus', 'registrada')
            ->orderByDesc('desde')->orderByDesc('id')->limit(60)->get();

        $equipo = collect();
        if ($this->admin()) {
            $proximas = Vacacion::where('tipo', 'tomadas')->where('estatus', 'registrada')->where('hasta', '>=', $hoy->toDateString())
                ->orderBy('desde')->get()->groupBy('user_id');
            $equipo = $this->equipoBase()->orderBy('name')->get()->map(fn (User $u) => [
                'usuario' => $u,
                'resumen' => Vacaciones::resumen($u, $hoy),
                'antiguedad' => Vacaciones::antiguedadTexto($u, $hoy),
                'proxima' => $proximas->get($u->id)?->first(),
            ]);
        }

        return view('livewire.vacaciones.index', [
            'yo' => $yo,
            'hoy' => $hoy,
            'miResumen' => Vacaciones::resumen($yo, $hoy),
            'miAntiguedad' => Vacaciones::antiguedadTexto($yo, $hoy),
            'destino' => $destino,
            'resumenDestino' => $resumenDestino,
            'diasRango' => $diasRango,
            'mias' => $mias,
            'equipo' => $equipo,
            'cal' => $this->calendario(),
            'esAdmin' => $this->admin(),
        ]);
    }
}
