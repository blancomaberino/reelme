<x-mail::message>
# Confirmá tu correo

Hola {{ $name }}, te damos la bienvenida a Reelmap. Ingresá este código en la app para confirmar tu dirección de correo:

<x-mail::panel>
{{ $code }}
</x-mail::panel>

El código vence en 15 minutos. Si no creaste una cuenta en Reelmap, podés ignorar este correo.

Gracias,<br>
El equipo de Reelmap
</x-mail::message>
