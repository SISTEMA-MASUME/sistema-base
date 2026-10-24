@extends('errors.plantilla')
@section('titulo', 'Esa página no se abre así')
@section('cuerpo')
    <p>Regresa y vuelve a usar el botón dentro del sistema.</p>
@endsection
@section('botones')
    <a class="btn btn-s" href="javascript:history.back()">Regresar</a>
    <a class="btn btn-p" href="{{ url('/') }}">Ir al inicio</a>
@endsection
