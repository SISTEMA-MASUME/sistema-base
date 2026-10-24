@extends('errors.plantilla')
@section('titulo', 'No encontramos esa página')
@section('cuerpo')
    <p>La dirección no existe o ya no está disponible. Revisa que esté bien escrita o regresa al inicio.</p>
@endsection
@section('botones')
    <a class="btn btn-s" href="javascript:history.back()">Regresar</a>
    <a class="btn btn-p" href="{{ url('/') }}">Ir al inicio</a>
@endsection
