<?php

namespace App\Livewire\Usuarios;

use App\Models\User;
use App\Support\Permisos;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Usuarios')]
class Index extends Component
{
    public bool $mostrarFormulario = false;

    public ?int $usuarioId = null;

    public string $name = '';

    public string $email = '';

    public string $iniciales = '';

    public string $telefono = '';

    public string $cuenta_correo = '';

    public string $rol = 'vendedor';

    public bool $activo = true;

    public bool $acceso_ned = false;

    /** @var list<string> pantallas de finanzas que puede ver (Support\Permisos) */
    public array $permisos = [];

    public string $password = '';

    public string $mensaje = '';

    public bool $verEliminados = false;

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->usuarioId)],
            'iniciales' => ['nullable', 'string', 'max:10'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'cuenta_correo' => ['nullable', Rule::in(array_keys(config('masume.cuentas_correo', [])))],
            'rol' => ['required', Rule::in(['admin', 'vendedor', 'campo', 'nedtracking'])],
            'activo' => ['boolean'],
            'acceso_ned' => ['boolean'],
            'permisos' => ['array'],
            'permisos.*' => [Rule::in(array_keys(Permisos::LISTA))],
            'password' => [$this->usuarioId ? 'nullable' : 'required', 'string', 'min:8'],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre.',
            'email.required' => 'Escribe el correo.',
            'email.email' => 'Escribe un correo válido.',
            'email.unique' => 'Ya existe un usuario con ese correo.',
            'rol.in' => 'Elige un rol válido.',
            'cuenta_correo.in' => 'Elige una cuenta de correo de la lista.',
            'password.required' => 'Escribe una contraseña.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ];
    }

    /** En cada acción (no solo al abrir la pantalla): si le quitaron el rol de admin con la pantalla abierta, ya no puede. */
    public function boot(): void
    {
        abort_unless(auth()->user()?->esAdmin(), 403);
    }

    public function nuevo(): void
    {
        $this->resetValidation();
        $this->reset(['usuarioId', 'name', 'email', 'iniciales', 'telefono', 'cuenta_correo', 'password', 'mensaje']);
        $this->rol = 'vendedor';
        $this->activo = true;
        $this->acceso_ned = false;
        $this->permisos = [];
        $this->mostrarFormulario = true;
    }

    public function editar(int $id): void
    {
        $usuario = User::findOrFail($id);

        $this->resetValidation();
        $this->mensaje = '';
        $this->usuarioId = $usuario->id;
        $this->name = $usuario->nombreReal();
        $this->email = $usuario->email;
        $this->iniciales = (string) $usuario->iniciales;
        $this->telefono = (string) $usuario->telefono;
        $this->cuenta_correo = (string) $usuario->cuenta_correo;
        $this->rol = $usuario->rol;
        $this->activo = $usuario->activo;
        $this->acceso_ned = (bool) $usuario->acceso_ned;
        $this->permisos = array_values((array) ($usuario->permisos ?? []));
        $this->password = '';
        $this->mostrarFormulario = true;
    }

    public function cancelar(): void
    {
        $this->mostrarFormulario = false;
        $this->resetValidation();
    }

    public function guardar(): void
    {
        $datos = $this->validate();

        // Un administrador no puede quitarse a sí mismo el acceso.
        if ($this->usuarioId === auth()->id() && (! $this->activo || $this->rol !== 'admin')) {
            $this->addError('rol', 'No puedes quitarte tu propio acceso de administrador.');

            return;
        }

        $campos = [
            'name' => $datos['name'],
            'email' => $datos['email'],
            'iniciales' => filled($datos['iniciales']) ? mb_strtoupper($datos['iniciales']) : null,
            'telefono' => filled($datos['telefono']) ? $datos['telefono'] : null,
            'cuenta_correo' => filled($datos['cuenta_correo']) ? $datos['cuenta_correo'] : null,
            'rol' => $datos['rol'],
            'activo' => $datos['activo'],
            // Solo aplica a vendedores (el admin siempre entra; campo nunca).
            'acceso_ned' => $datos['rol'] === 'vendedor' && $datos['acceso_ned'],
            // Finanzas: el admin ya entra a todo y Ned Tracking nunca.
            'permisos' => in_array($datos['rol'], ['admin', 'nedtracking'], true) ? null
                : array_values(array_intersect(array_keys(Permisos::LISTA), $datos['permisos'] ?? [])),
        ];

        if (filled($datos['password'])) {
            $campos['password'] = $datos['password'];
        }

        if ($this->usuarioId) {
            User::findOrFail($this->usuarioId)->update($campos);
            $this->mensaje = 'Usuario actualizado.';
        } else {
            User::create($campos);
            $this->mensaje = 'Usuario creado.';
        }

        $this->mostrarFormulario = false;
        $this->reset(['usuarioId', 'password']);
    }

    /**
     * Elimina al usuario: ya no entra ni sale en la lista, pero todo lo que hizo (cotizaciones, pedidos,
     * comentarios, OC…) se queda a su nombre con "(usuario inactivo)".
     */
    public function eliminar(int $id): void
    {
        if ($id === auth()->id()) {
            $this->mensaje = 'No puedes eliminarte a ti mismo.';

            return;
        }
        $usuario = User::findOrFail($id);
        $usuario->forceFill(['activo' => false, 'acceso_ned' => false, 'eliminado_at' => now(),
            'name' => $usuario->nombreReal().User::SUFIJO_ELIMINADO])->save();
        $this->mostrarFormulario = false;
        $this->mensaje = 'Se eliminó a '.$usuario->nombreReal().'. Sus movimientos se quedan y dicen "(usuario inactivo)".';
    }

    public function restaurar(int $id): void
    {
        $usuario = User::findOrFail($id);
        $usuario->forceFill(['eliminado_at' => null, 'activo' => true, 'name' => $usuario->nombreReal()])->save();
        $this->mensaje = $usuario->nombreReal().' volvió a estar activo.';
    }

    public function render()
    {
        return view('usuarios.index', [
            'usuarios' => User::whereNull('eliminado_at')->orderByDesc('activo')->orderBy('name')->get(),
            'eliminados' => $this->verEliminados ? User::whereNotNull('eliminado_at')->orderBy('name')->get() : collect(),
            'totalEliminados' => User::whereNotNull('eliminado_at')->count(),
            'cuentas' => array_keys(config('masume.cuentas_correo', [])),
        ]);
    }
}
