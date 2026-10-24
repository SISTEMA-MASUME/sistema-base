@extends('errors.plantilla')
@section('titulo', 'Estamos en mantenimiento')
@section('cuerpo')
    <p>El sistema está en mantenimiento por unos minutos. Intenta de nuevo en un rato.</p>
@endsection
@section('botones')
    <a class="btn btn-p" href="javascript:location.reload()">Reintentar</a>
@endsection
