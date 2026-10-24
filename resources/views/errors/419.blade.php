@extends('errors.plantilla')
@section('titulo', 'Tu sesión expiró')
@section('cuerpo')
    <p>Tu sesión expiró, vuelve a entrar. Lo que ya estaba guardado sigue ahí.</p>
@endsection
@section('botones')
    <a class="btn btn-p" href="{{ url('/login') }}">Volver a entrar</a>
@endsection
