@extends('errors.plantilla')
@section('titulo', 'No tienes permiso')
@section('cuerpo')
    <p>Esta sección es solo para administradores. Si crees que deberías tener acceso, avísale a Daniel.</p>
@endsection
@section('botones')
    <a class="btn btn-s" href="javascript:history.back()">Regresar</a>
    <a class="btn btn-p" href="{{ url('/') }}">Ir al inicio</a>
@endsection
