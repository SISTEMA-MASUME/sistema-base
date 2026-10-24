<div>
    <div class="mb-4 flex justify-end">
        <button type="button" wire:click="nuevo" class="btn-primario w-full sm:w-auto">+ Nuevo usuario</button>
    </div>

    @if ($mensaje)
        <div role="status" class="mb-4 rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif

    @if ($mostrarFormulario)
        <form wire:submit="guardar" class="mb-6 space-y-4 rounded-xl bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-marino">{{ $usuarioId ? 'Editar usuario' : 'Nuevo usuario' }}</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="etiqueta" for="u-name">Nombre</label>
                    <input id="u-name" type="text" wire:model="name" class="campo">
                    @error('name') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="u-email">Correo</label>
                    <input id="u-email" type="email" wire:model="email" class="campo">
                    @error('email') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="u-iniciales">Iniciales (aparecen en las tarjetas)</label>
                    <input id="u-iniciales" type="text" wire:model="iniciales" maxlength="10" class="campo uppercase">
                    @error('iniciales') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="u-tel">Teléfono</label>
                    <input id="u-tel" type="tel" wire:model="telefono" class="campo">
                    @error('telefono') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="u-cuenta">Cuenta de correo de salida</label>
                    <select id="u-cuenta" wire:model="cuenta_correo" class="campo">
                        <option value="">Sin cuenta</option>
                        @foreach ($cuentas as $c)
                            <option value="{{ $c }}">{{ $c }}</option>
                        @endforeach
                    </select>
                    @error('cuenta_correo') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="u-rol">Rol</label>
                    <select id="u-rol" wire:model="rol" class="campo">
                        <option value="vendedor">Vendedor</option>
                        <option value="campo">Campo</option>
                        <option value="admin">Administrador</option>
                        <option value="nedtracking">Ned Tracking (externo)</option>
                    </select>
                    @error('rol') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="u-pass">{{ $usuarioId ? 'Nueva contraseña (déjala vacía para no cambiarla)' : 'Contraseña' }}</label>
                    <input id="u-pass" type="password" wire:model="password" autocomplete="new-password" class="campo">
                    @error('password') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
            </div>
            <label class="flex items-center gap-2 text-base">
                <input type="checkbox" wire:model="activo" class="h-5 w-5 rounded border-slate-300"> Usuario activo (puede entrar al sistema)
            </label>
            <label x-show="$wire.rol === 'vendedor'" class="flex items-center gap-2 text-base">
                <input type="checkbox" wire:model="acceso_ned" class="h-5 w-5 rounded border-slate-300"> Acceso a Ned Tracking
            </label>
            <fieldset x-show="$wire.rol === 'vendedor' || $wire.rol === 'campo'" class="rounded-lg border border-slate-200 p-3">
                <legend class="px-1 text-sm font-semibold text-marino">Finanzas (solo lo que palomees)</legend>
                @foreach (\App\Support\Permisos::LISTA as $clave => $texto)
                    <label class="flex items-center gap-2 py-1 text-base">
                        <input type="checkbox" wire:model="permisos" value="{{ $clave }}" class="h-5 w-5 rounded border-slate-300"> {{ $texto }}
                    </label>
                @endforeach
            </fieldset>
            <div class="flex flex-col gap-2 sm:flex-row">
                <button type="submit" class="btn-primario">Guardar</button>
                <button type="button" wire:click="cancelar" class="btn-secundario">Cancelar</button>
            </div>
        </form>
    @endif

    <div class="overflow-hidden rounded-xl bg-white shadow-sm">
        <ul class="divide-y divide-slate-200">
            @foreach ($usuarios as $u)
                <li class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="usuario-{{ $u->id }}">
                    <div class="min-w-0">
                        <p class="font-semibold">
                            {{ $u->name }}
                            @if ($u->iniciales) <span class="ml-1 rounded bg-marino px-2 py-0.5 text-xs text-white">{{ $u->iniciales }}</span> @endif
                        </p>
                        <p class="truncate text-sm text-slate-600">{{ $u->email }}@if ($u->telefono) · {{ $u->telefono }}@endif</p>
                        <p class="mt-1 text-xs">
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-700">{{ ['admin' => 'Administrador', 'vendedor' => 'Vendedor', 'campo' => 'Campo', 'nedtracking' => 'Ned Tracking'][$u->rol] ?? $u->rol }}</span>
                            @unless ($u->activo) <span class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-red-700">Inactivo</span> @endunless
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" wire:click="editar({{ $u->id }})" class="btn-secundario">Editar</button>
                        @if ($u->id !== auth()->id())
                            <button type="button" wire:click="eliminar({{ $u->id }})"
                                    wire:confirm="¿Eliminar a {{ $u->name }}? Ya no podrá entrar, pero todo lo que hizo se queda y dirá &quot;(usuario inactivo)&quot;."
                                    class="btn-secundario !text-red-600">Eliminar</button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </div>

    @if ($totalEliminados > 0)
        <div class="mt-4">
            <button type="button" wire:click="$toggle('verEliminados')" class="text-sm text-slate-600 underline">
                {{ $verEliminados ? 'Ocultar' : 'Ver' }} usuarios eliminados ({{ $totalEliminados }})
            </button>
            @if ($verEliminados)
                <ul class="mt-2 divide-y divide-slate-200 rounded-xl bg-white shadow-sm">
                    @foreach ($eliminados as $u)
                        <li class="flex items-center justify-between gap-2 p-4" wire:key="eliminado-{{ $u->id }}">
                            <span class="text-slate-600">{{ $u->name }}</span>
                            <button type="button" wire:click="restaurar({{ $u->id }})" class="btn-secundario">Reactivar</button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</div>
