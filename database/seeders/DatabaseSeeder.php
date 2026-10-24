<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $clave = env('ADMIN_PASSWORD');

        if (! $clave && app()->environment('local', 'testing')) {
            $clave = 'cambiar123';
        }

        if ($clave) {
            User::updateOrCreate(
                ['email' => 'admin@ejemplo.com'],
                ['name' => 'Daniel', 'password' => $clave, 'rol' => 'admin', 'iniciales' => 'MAR', 'activo' => true],
            );
        } else {
            $this->command?->warn('ADMIN_PASSWORD no está definida en .env: no se creó el administrador.');
        }

        if (app()->environment('local', 'testing')) {
            foreach ([
                ['Alberto', 'ALBY', 'alberto@masume.test'],
                ['Rosa', 'ROSY', 'rosa@masume.test'],
                ['Irene', 'ILE', 'irene@masume.test'],
                ['Ernesto', 'EDG', 'ernesto@masume.test'],
            ] as [$nombre, $iniciales, $correo]) {
                User::updateOrCreate(
                    ['email' => $correo],
                    ['name' => $nombre, 'password' => 'prueba123', 'rol' => 'vendedor', 'iniciales' => $iniciales, 'activo' => true],
                );
            }
        }
    }
}
