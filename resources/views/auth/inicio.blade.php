@extends('layouts.app')
@section('titulo', 'Inicio')
@section('contenido')
    <div class="rounded-xl bg-white p-6 shadow-sm">
        <p class="text-lg">Hola, <strong>{{ auth()->user()->name }}</strong>. Bienvenido al sistema de MASUME.</p>
    </div>
@endsection
