@php($codigo = request()->attributes->get('error_codigo'))
@extends('errors.plantilla')
@section('titulo', 'Algo salió mal')
@section('cuerpo')
    <p>Algo salió mal. Ya quedó registrado.</p>
    @if ($codigo)
        <div class="codigo" id="codigo-error">{{ $codigo }}</div>
        <p>Si necesitas ayuda, manda este código a Daniel.</p>
    @else
        <p>Si el problema sigue, avísale a Daniel.</p>
    @endif
@endsection
@section('botones')
    <a class="btn btn-s" href="javascript:history.back()">Regresar</a>
    <a class="btn btn-p" href="{{ url('/') }}">Ir al inicio</a>
@endsection
