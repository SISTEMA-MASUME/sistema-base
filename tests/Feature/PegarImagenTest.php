<?php

namespace Tests\Feature;

use App\Livewire\Cotizaciones\Editor;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PegarImagenTest extends TestCase
{
    use RefreshDatabase;

    public function test_png_del_portapapeles_se_guarda_redimensionado_y_hay_recuadro(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['iniciales' => 'ALBY']);
        $this->actingAs($user);
        $cot = Cotizacion::factory()->create(['vendedor_id' => $user->id]);

        $c = Livewire::test(Editor::class, ['cotizacion' => $cot])->call('agregarPartida');
        $c->set('imagenes.0', UploadedFile::fake()->image('image.png', 1200, 900))->assertHasNoErrors();

        $ruta = CotizacionPartida::first()->imagen_path;
        $this->assertNotNull($ruta);
        Storage::disk('public')->assertExists($ruta);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($ruta));
        $this->assertSame(800, $w);
        $this->assertSame(600, $h);

        $c->assertSeeHtml('x-on:paste');
    }
}
