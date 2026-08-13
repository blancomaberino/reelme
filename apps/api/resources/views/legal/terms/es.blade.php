@extends('legal.layout')

@section('title', 'Términos y condiciones')
@section('summary', 'Las reglas para usar Reelmap: qué podés hacer, qué no se tolera, cómo se moderan los abusos y qué pasa con las promociones y los pagos.')
@section('updated-label', 'Vigente desde el')
@section('toc-label', 'Contenido')
@section('other-doc', '← Política de privacidad')
@section('footer-contact', 'Contacto y moderación: hola@reelmap.app')

@section('toc')
  <li><a href="#aceptacion">Aceptación de estos términos</a></li>
  <li><a href="#quien">Quién puede usar Reelmap</a></li>
  <li><a href="#cuenta">Tu cuenta</a></li>
  <li><a href="#que-es">Qué es Reelmap y qué no</a></li>
  <li><a href="#tu-contenido">El contenido que publicás</a></li>
  <li><a href="#tolerancia-cero">Tolerancia cero con el contenido objetable</a></li>
  <li><a href="#reportar">Reportar y bloquear</a></li>
  <li><a href="#derechos-autor">Derechos de autor y retiros</a></li>
  <li><a href="#promociones">Promociones, canjes y pagos</a></li>
  <li><a href="#disponibilidad">Disponibilidad del servicio</a></li>
  <li><a href="#suspension">Suspensión y cierre de cuenta</a></li>
  <li><a href="#garantias">Garantías y responsabilidad</a></li>
  <li><a href="#ley">Ley aplicable</a></li>
  <li><a href="#tiendas">Condiciones de las tiendas de aplicaciones</a></li>
  <li><a href="#cambios">Cambios en estos términos</a></li>
@endsection

@section('body')
  <h2 id="aceptacion">Aceptación de estos términos</h2>
  <p>
    Estos términos son el contrato entre vos y <strong>Marcelo Blanco</strong>, con domicilio en Montevideo,
    Uruguay, que desarrolla y opera Reelmap. Al crear una cuenta o usar la aplicación, aceptás lo que dice
    acá. Si no estás de acuerdo con alguna parte, no uses el servicio.
  </p>
  <p>
    El tratamiento de tus datos personales se rige por la
    <a href="{{ url('/privacy/es') }}">política de privacidad</a>, que forma parte de estos términos.
  </p>

  <h2 id="quien">Quién puede usar Reelmap</h2>
  <p>
    Tenés que tener al menos <strong>13 años</strong> para crear una cuenta. Si en tu país la edad mínima para
    consentir el tratamiento de datos personales es mayor, esa es la que se aplica. Al registrarte declarás
    que cumplís con ese requisito.
  </p>

  <h2 id="cuenta">Tu cuenta</h2>
  <p>
    Sos responsable de mantener tu contraseña a salvo y de la actividad que ocurra en tu cuenta. Los datos que
    cargues tienen que ser verdaderos: no te hagas pasar por otra persona, por un local ni por una marca.
    Podés activar la verificación en dos pasos desde <em>Ajustes → Seguridad</em>, y te lo recomendamos.
  </p>
  <p>
    Una cuenta por persona. Si reclamás el perfil de un creador o la ficha de un local, tenés que estar
    autorizado a hacerlo; los reclamos falsos son causal de cierre inmediato.
  </p>

  <h2 id="que-es">Qué es Reelmap y qué no</h2>
  <p>
    Reelmap toma publicaciones públicas de redes sociales que vos compartís, identifica de qué lugar hablan y
    lo ubica en un mapa. Eso implica dos cosas que conviene decir sin vueltas:
  </p>
  <ul>
    <li>
      <strong>Los datos de los lugares son colaborativos y automáticos.</strong> Se generan a partir del
      análisis de publicaciones y de fuentes externas como Google Places. Pueden estar
      <strong>equivocados, desactualizados o incompletos</strong>. Confirmá horarios, precios y direcciones
      con el local antes de ir. Si ves un error, podés sugerir una corrección desde la ficha del lugar.
    </li>
    <li>
      <strong>No somos el autor de las publicaciones.</strong> Las publicaciones originales pertenecen a
      quienes las crearon y a las plataformas donde están; Reelmap las enlaza o las embebe, no las rehospeda.
      Que un lugar aparezca en el mapa no implica que ese creador ni ese local tengan relación con nosotros
      ni que nos hayan dado su respaldo.
    </li>
  </ul>
  <p>
    Solo compartí publicaciones que sean públicas o que tengas derecho a compartir.
  </p>

  <h2 id="tu-contenido">El contenido que publicás</h2>
  <p>
    Tus reseñas, listas, correcciones sugeridas y demás aportes siguen siendo tuyos. Al publicarlos en
    Reelmap nos das una licencia no exclusiva, mundial y sin regalías para alojarlos, mostrarlos y
    distribuirlos dentro del servicio, con el único fin de hacerlo funcionar. Podés retirar tu contenido
    borrándolo o cerrando tu cuenta.
  </p>
  <p>
    Con una excepción que decimos de antemano: las <strong>correcciones que sugerís sobre un local</strong>
    pasan a formar parte del registro de ese local. Si borrás tu cuenta, esas correcciones siguen ahí, pero
    <strong>dejan de estar asociadas a tu nombre</strong> y el texto libre que hayas escrito se elimina.
  </p>

  <h2 id="tolerancia-cero">Tolerancia cero con el contenido objetable</h2>
  <div class="note">
    <p>
      <strong>No hay tolerancia alguna con el contenido objetable ni con los usuarios abusivos.</strong>
      Revisamos todo reporte que recibimos y, cuando corresponde, <strong>retiramos el contenido y damos de
      baja a la cuenta responsable dentro de las 24 horas</strong> de recibido el reporte.
    </p>
  </div>
  <p>Concretamente, está prohibido publicar o enviar:</p>
  <ul>
    <li>Acoso, amenazas, intimidación o incitación al odio o a la violencia contra una persona o un grupo.</li>
    <li>Contenido sexual explícito, o cualquier contenido que involucre a menores de manera sexualizada.</li>
    <li>Contenido que promueva actividades ilegales, autolesiones o el consumo de drogas.</li>
    <li>Difamación, datos privados de otras personas, o suplantación de identidad.</li>
    <li>Spam, promoción encubierta, reseñas falsas o manipulación de la reputación de un local.</li>
    <li>Material sobre el que no tenés derechos, o que infrinja los de un tercero.</li>
    <li>Intentos de vulnerar, sobrecargar o extraer masivamente datos del servicio, y el uso de sistemas
      automatizados para acceder a él sin nuestro permiso.</li>
  </ul>
  <p>
    Estas reglas alcanzan a todo lo que publiques: reseñas, listas, nombre de usuario, foto, biografía,
    correcciones sugeridas y notas de texto libre.
  </p>

  <h2 id="reportar">Reportar y bloquear</h2>
  <p>
    Tenés dos herramientas, y hacen cosas distintas a propósito:
  </p>
  <ul>
    <li>
      <strong>Reportar</strong> es pedirle a un moderador que revise algo. Podés reportar un perfil, un
      lugar o una reseña desde su propia pantalla.
    </li>
    <li>
      <strong>Bloquear</strong> tiene efecto inmediato y no espera a nadie: dejan de verse mutuamente, se
      cortan los seguimientos en ambos sentidos y esa cuenta desaparece de tu experiencia. Podés revertirlo
      desde <em>Ajustes → Cuentas bloqueadas</em>.
    </li>
  </ul>
  <p>
    Para denuncias urgentes o que no encajen en ninguna pantalla, escribinos a
    <a href="mailto:hola@reelmap.app">hola@reelmap.app</a>.
  </p>

  <h2 id="derechos-autor">Derechos de autor y retiros</h2>
  <p>
    Respetamos los derechos de autor. Los videos originales que se descargan para el análisis se borran
    dentro de las 72 horas y nunca se rehospedan: lo que se muestra es un enlace o un embebido a la
    publicación original.
  </p>
  <p>
    Si sos titular de un derecho y creés que algo en Reelmap lo infringe, escribinos a
    <a href="mailto:hola@reelmap.app">hola@reelmap.app</a> indicando qué contenido es y dónde está, qué
    derecho tenés sobre él, tus datos de contacto y una declaración de buena fe. Retiramos el material que
    corresponda y damos de baja a quienes infrinjan de forma reiterada. Si creés que retiramos algo tuyo por
    error, respondé a esa misma dirección y lo revisamos.
  </p>

  <h2 id="promociones">Promociones, canjes y pagos</h2>
  <p>
    Algunos locales publican promociones que podés canjear en el lugar. Sobre eso:
  </p>
  <ul>
    <li>
      <strong>La promoción es un acuerdo entre vos y el local</strong>, no con Reelmap. El local define las
      condiciones, la vigencia y el stock, y es quien responde por cumplirla. Nosotros emitimos y verificamos
      el código de canje.
    </li>
    <li>
      Los canjes son personales e intransferibles. Cualquier intento de duplicar, falsificar o revender un
      código anula el canje y puede terminar en el cierre de la cuenta.
    </li>
    <li>
      Si cobrás como creador, los pagos se procesan a través de <strong>Stripe</strong> y quedan sujetos
      también a las condiciones de Stripe. Para cobrar necesitás completar la verificación de identidad que
      Stripe exige. Los saldos y movimientos figuran en tu billetera dentro de la aplicación.
    </li>
  </ul>

  <h2 id="disponibilidad">Disponibilidad del servicio</h2>
  <p>
    Hacemos lo posible por mantener Reelmap funcionando, pero no garantizamos disponibilidad ininterrumpida.
    Podemos modificar, suspender o discontinuar funcionalidades. Si vamos a discontinuar el servicio por
    completo, te avisaremos con antelación razonable para que puedas descargar una copia de tus datos.
  </p>

  <h2 id="suspension">Suspensión y cierre de cuenta</h2>
  <p>
    Podemos suspender o cerrar una cuenta que incumpla estos términos, en particular la sección de
    <a href="#tolerancia-cero">tolerancia cero</a>, y hacerlo sin aviso previo cuando el incumplimiento sea
    grave o haya riesgo para otras personas. Vos podés cerrar tu cuenta cuando quieras desde
    <em>Ajustes → Privacidad y datos</em>; lo que pasa con tus datos está detallado en la
    <a href="{{ url('/privacy/es') }}">política de privacidad</a>.
  </p>

  <h2 id="garantias">Garantías y responsabilidad</h2>
  <p>
    Reelmap se ofrece «tal cual». En la medida en que la ley lo permita, no garantizamos que la información
    sobre un lugar sea exacta, actual o completa, ni que el servicio esté libre de errores, y no respondemos
    por daños indirectos o lucro cesante derivados del uso del servicio, ni por el contenido publicado por
    otros usuarios, ni por lo que ocurra en tu visita a un local.
  </p>
  <p>
    Nada de esto limita las responsabilidades que no pueden excluirse por ley, ni los derechos que te
    correspondan como consumidor.
  </p>

  <h2 id="ley">Ley aplicable</h2>
  <p>
    Estos términos se rigen por la ley de la <strong>República Oriental del Uruguay</strong>, y cualquier
    controversia se somete a los tribunales de Montevideo. Si residís en otro país, conservás la protección
    de las normas imperativas de defensa del consumidor de tu lugar de residencia.
  </p>

  <h2 id="tiendas">Condiciones de las tiendas de aplicaciones</h2>
  <p>
    Cuando descargás Reelmap desde la App Store, estos términos se celebran entre vos y nosotros
    únicamente, <strong>no con Apple</strong>. Apple no es responsable de la aplicación ni de su contenido,
    y no tiene ninguna obligación de brindarte soporte o mantenimiento. Si la aplicación no cumple con una
    garantía aplicable, podés notificarlo a Apple y Apple te reembolsará el precio de compra, si lo hubiera;
    más allá de eso, Apple no tiene ninguna otra obligación de garantía. Cualquier reclamo relacionado con la
    aplicación —incluidos los de responsabilidad por el producto, incumplimiento normativo o propiedad
    intelectual— nos corresponde a nosotros y no a Apple. Apple y sus subsidiarias son terceros beneficiarios
    de estos términos y podrán exigirlos frente a vos.
  </p>
  <p>
    Al usar la aplicación también te comprometés a cumplir las condiciones de uso de la tienda desde la que
    la descargaste.
  </p>

  <h2 id="cambios">Cambios en estos términos</h2>
  <p>
    Si cambiamos algo relevante, actualizamos la fecha de vigencia que figura arriba y te avisamos dentro de
    la aplicación antes de que entre en vigor. Seguir usando Reelmap después de esa fecha implica aceptar la
    nueva versión.
  </p>
  <p class="contact">
    Cualquier duda sobre estos términos: <a href="mailto:hola@reelmap.app">hola@reelmap.app</a>.
  </p>
@endsection
