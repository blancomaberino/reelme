@extends('legal.layout')

@section('title', 'Política de privacidad')
@section('summary', 'Qué datos recoge Reelmap, para qué los usa, con quién los comparte y cómo pedir una copia o borrarlos.')
@section('updated-label', 'Vigente desde el')
@section('toc-label', 'Contenido')
@section('other-doc', 'Términos y condiciones →')
@section('footer-contact', 'Consultas sobre privacidad: {{ $contact }}')

@section('toc')
  <li><a href="#responsable">Quién es responsable de tus datos</a></li>
  <li><a href="#que-recogemos">Qué datos recogemos</a></li>
  <li><a href="#para-que">Para qué los usamos y con qué base legal</a></li>
  <li><a href="#ia">Cómo se analiza lo que compartís</a></li>
  <li><a href="#permisos">Ubicación, cámara y notificaciones</a></li>
  <li><a href="#terceros">Con quién los compartimos</a></li>
  <li><a href="#publicidad">Publicidad y seguimiento</a></li>
  <li><a href="#conservacion">Cuánto tiempo los conservamos</a></li>
  <li><a href="#derechos">Tus derechos</a></li>
  <li><a href="#borrar">Borrar tu cuenta</a></li>
  <li><a href="#transferencias">Transferencias internacionales</a></li>
  <li><a href="#menores">Menores de edad</a></li>
  <li><a href="#seguridad">Seguridad</a></li>
  <li><a href="#cambios">Cambios en esta política</a></li>
@endsection

@section('body')
  <h2 id="responsable">Quién es responsable de tus datos</h2>
  <p>
    Reelmap es una aplicación desarrollada y operada por <strong>{{ $controller }}</strong>, con domicilio en
    {{ $domicile }}. A los efectos de la Ley N.º 18.331 de Protección de Datos Personales y, cuando
    corresponda, del Reglamento General de Protección de Datos de la Unión Europea (RGPD), es
    <strong>quien responde por el tratamiento</strong> de los datos que se describen acá.
  </p>
  <p>
    Para cualquier consulta, reclamo o pedido sobre tus datos, escribinos a
    <a href="mailto:{{ $contact }}">{{ $contact }}</a>. Es la misma dirección que usamos para moderación
    y soporte, y la leemos.
  </p>

  <h2 id="que-recogemos">Qué datos recogemos</h2>
  <p>
    Solo lo que la aplicación necesita para funcionar. No compramos datos ni los cruzamos con fuentes externas.
  </p>

  <h3>Cuenta</h3>
  <p>
    Tu correo electrónico, nombre, nombre de usuario y contraseña. La contraseña se guarda <em>hasheada</em>:
    no la conservamos ni la podemos leer. Si activás la verificación en dos pasos, guardamos el secreto y los
    códigos de recuperación cifrados.
  </p>

  <h3>Perfil (opcional)</h3>
  <p>
    Foto, biografía, fecha de nacimiento, país, idioma y tus listas de temas y comidas favoritas. Todo esto es
    opcional, lo editás vos y podés borrarlo cuando quieras. Tu perfil puede ser público o privado; vos elegís.
  </p>

  <h3>Lo que creás en la aplicación</h3>
  <p>
    Los enlaces a publicaciones que compartís y los datos de lugares que se extraen de ellos, tus reseñas,
    tus listas, tus etiquetas privadas sobre un lugar, las correcciones que sugerís sobre un local (incluido
    el texto libre que escribas), los reportes que enviás y a quién seguís.
  </p>

  <h3>Cuentas de redes vinculadas</h3>
  <p>
    Si vinculás una cuenta de Instagram, guardamos el identificador de esa cuenta y el token de acceso que
    nos entrega la plataforma, para poder leer tus propias publicaciones. Podés desvincularla cuando quieras;
    el token se elimina en ese momento.
  </p>

  <h3>Dispositivo</h3>
  <p>
    El token de notificaciones push, el nombre del dispositivo y la versión de la aplicación, para poder
    avisarte cuando termina un análisis o alguien interactúa con vos.
  </p>

  <h3>Pagos y canjes</h3>
  <p>
    Si canjeás una promoción o cobrás como creador, guardamos el registro del canje y los asientos contables
    correspondientes. <strong>No guardamos datos de tarjetas.</strong> La verificación de identidad y el pago
    los realiza Stripe a través de una cuenta Stripe Connect Express que es tuya, y esos datos quedan en Stripe.
  </p>

  <h3>Diagnóstico</h3>
  <p>
    Cuando algo falla, se envía un reporte de error a Sentry para poder arreglarlo.
  </p>
  <div class="note">
    <p>
      Ese reporte <strong>no lleva datos personales por construcción, no por promesa</strong>: el envío de
      información personal y de parámetros de consultas está desactivado en el código y no es configurable
      por variable de entorno, justamente para que nadie pueda activarlo en medio de un incidente. Lo que
      recibe Sentry es la traza del error y unos identificadores técnicos.
    </p>
  </div>

  <h3>Lo que no recogemos</h3>
  <p>
    No pedimos ni recibimos tus contactos, tu historial de navegación, datos de salud, ni números de cuenta
    bancaria o de tarjeta.
  </p>

  <h2 id="para-que">Para qué los usamos y con qué base legal</h2>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Para qué usamos tus datos y con qué base legal">
  <table>
    <thead>
      <tr><th>Para qué</th><th>Qué usamos</th><th>Base legal</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>Crear tu cuenta, autenticarte y sostener el servicio</td>
        <td>Correo, nombre de usuario, contraseña</td>
        <td>Ejecución del contrato</td>
      </tr>
      <tr>
        <td>Mostrar tu perfil público y tus aportes</td>
        <td>Perfil, contenido que creás</td>
        <td>Ejecución del contrato</td>
      </tr>
      <tr>
        <td>Analizar las publicaciones que compartís y ubicar los lugares</td>
        <td>Enlaces compartidos, video y audio de la publicación</td>
        <td>Ejecución del contrato</td>
      </tr>
      <tr>
        <td>Centrar el mapa donde estás</td>
        <td>Ubicación aproximada del dispositivo</td>
        <td>Consentimiento (permiso del sistema)</td>
      </tr>
      <tr>
        <td>Avisarte de novedades relevantes</td>
        <td>Token de notificaciones</td>
        <td>Consentimiento (permiso del sistema)</td>
      </tr>
      <tr>
        <td>Emitir y verificar canjes, y pagar a creadores</td>
        <td>Registros de canje y asientos contables</td>
        <td>Ejecución del contrato y obligación legal</td>
      </tr>
      <tr>
        <td>Moderar abusos, prevenir fraude y mantener el servicio en pie</td>
        <td>Reportes, bloqueos, registros técnicos</td>
        <td>Interés legítimo</td>
      </tr>
    </tbody>
  </table>
  </div>

  <h2 id="ia">Cómo se analiza lo que compartís</h2>
  <p>
    Cuando compartís una publicación con Reelmap, esto es exactamente lo que pasa: descargamos el contenido
    público de esa publicación, extraemos algunos fotogramas y el audio, transcribimos el audio a texto y
    le pasamos esos fotogramas y esa transcripción a un modelo de inteligencia artificial que identifica de
    qué lugar se está hablando. Con ese resultado buscamos el local en Google Places para obtener su
    dirección y coordenadas.
  </p>
  <div class="note">
    <p>
      El modelo que hace ese trabajo corre <strong>en nuestra propia infraestructura</strong> siempre que
      esté disponible. Si no lo está, o si elegiste un modelo específico en Ajustes, el análisis se hace a
      través de <strong>OpenRouter</strong>, un proveedor externo: en ese caso los fotogramas y la
      transcripción salen de nuestros servidores hacia ese proveedor. Podés ver y elegir el modelo en
      <em>Ajustes → Modelo de análisis</em>.
    </p>
  </div>
  <p>
    El video original se borra apenas termina el análisis y, en todo caso, dentro de las <strong>72 horas</strong>.
    Solo conservamos los fotogramas y miniaturas que sirven para identificar el lugar. Las publicaciones
    originales nunca se rehospedan: siempre se muestran enlazadas o embebidas desde la plataforma donde están.
  </p>

  <h2 id="permisos">Ubicación, cámara y notificaciones</h2>
  <p>
    Los tres permisos son opcionales y la aplicación funciona sin ellos.
  </p>
  <ul>
    <li>
      <strong>Ubicación.</strong> Solo mientras usás la aplicación (nunca en segundo plano), para centrar el
      mapa donde estás y para el botón de «ubicarme». <strong>Tu ubicación no se guarda asociada a tu
      cuenta</strong>: se usa en el momento y no queda registrada. Si rechazás el permiso, el mapa arranca
      en una región por defecto.
    </li>
    <li>
      <strong>Cámara.</strong> Únicamente para escanear el código QR de un canje en el local. No pedimos
      acceso a tu galería de fotos. Si preferís no darlo, el código siempre se puede ingresar a mano.
    </li>
    <li>
      <strong>Notificaciones.</strong> Para avisarte cuando termina el análisis de algo que compartiste o
      cuando hay actividad sobre tus aportes.
    </li>
  </ul>

  <h2 id="terceros">Con quién los compartimos</h2>
  <p>
    No vendemos tus datos ni los cedemos a terceros con fines comerciales. Los compartimos únicamente con
    los proveedores que hacen funcionar el servicio, y solo con lo mínimo que cada uno necesita:
  </p>
  <ul>
    <li><strong>Google Places</strong> — para resolver la dirección y las coordenadas de un local.</li>
    <li><strong>Instagram, YouTube, TikTok y X</strong> — para leer los datos públicos de la publicación que compartiste.</li>
    <li><strong>OpenRouter</strong> — cuando el análisis no corre en nuestra infraestructura (ver la sección anterior).</li>
    <li><strong>Stripe</strong> — verificación de identidad y pagos a creadores.</li>
    <li><strong>Expo, Apple y Google</strong> — para entregar las notificaciones push a tu dispositivo.</li>
    <li><strong>Sentry</strong> — reportes de error, sin datos personales.</li>
    <li><strong>Nuestro proveedor de correo</strong> — para los correos de verificación y avisos de cuenta.</li>
    <li><strong>Nuestro proveedor de almacenamiento y servidores</strong> — donde vive la base de datos y los archivos.</li>
  </ul>
  <p>
    También podemos entregar datos si nos lo exige una orden judicial o una obligación legal.
  </p>

  <h2 id="publicidad">Publicidad y seguimiento</h2>
  <div class="note">
    <p>
      Reelmap <strong>no hace seguimiento publicitario</strong>. No hay ningún SDK de publicidad, ninguna
      herramienta de analítica de terceros y ningún dato compartido con intermediarios de datos. No cruzamos
      tu actividad con la de otras aplicaciones ni sitios web.
    </p>
  </div>

  <h2 id="conservacion">Cuánto tiempo los conservamos</h2>
  <ul>
    <li><strong>Videos y audios originales:</strong> hasta 72 horas desde el análisis. Si un análisis queda trabado, como máximo 168 horas.</li>
    <li><strong>Datos crudos de la plataforma de origen:</strong> 90 días; después se borran y quedan solo los campos ya extraídos.</li>
    <li><strong>Copia de tus datos (exportación):</strong> el archivo se borra a los 7 días y el enlace de descarga vence a las 24 horas.</li>
    <li><strong>Cuenta y contenido:</strong> mientras tu cuenta exista. Al borrarla, se aplica lo que dice la sección siguiente.</li>
    <li><strong>Registros de canjes, pagos y asientos contables:</strong> se conservan aunque borres tu cuenta, por obligación legal y contable.</li>
  </ul>

  <h2 id="derechos">Tus derechos</h2>
  <p>
    Podés ejercer, sin costo, los derechos de <strong>acceso, rectificación, actualización, supresión,
    portabilidad y oposición</strong> sobre tus datos, y retirar en cualquier momento los permisos que
    hayas dado.
  </p>
  <p>
    La mayoría se resuelve dentro de la aplicación, sin escribirle a nadie:
  </p>
  <ul>
    <li><strong>Acceso y portabilidad:</strong> <em>Ajustes → Privacidad y datos → Obtener una copia de mis datos</em>. Te generamos un archivo con tu perfil, publicaciones compartidas, lugares, listas, etiquetas, reseñas, correcciones sugeridas, reportes, seguimientos, notificaciones, dispositivos, canjes y movimientos de tu cuenta.</li>
    <li><strong>Rectificación:</strong> editás tu perfil directamente en la aplicación.</li>
    <li><strong>Supresión:</strong> <em>Ajustes → Privacidad y datos → Borrar mi cuenta</em>.</li>
  </ul>
  <p>
    Para todo lo demás, escribinos a <a href="mailto:{{ $contact }}">{{ $contact }}</a>. Respondemos
    dentro de los plazos legales aplicables. Si considerás que no atendimos bien tu pedido, podés reclamar
    ante la Unidad Reguladora y de Control de Datos Personales (URCDP) de Uruguay o, si estás en la Unión
    Europea, ante la autoridad de control de tu país.
  </p>

  <h2 id="borrar">Borrar tu cuenta</h2>
  <p>
    Podés borrar tu cuenta desde la aplicación, en <em>Ajustes → Privacidad y datos</em>. No hace falta
    escribir un correo ni completar un formulario web.
  </p>
  <div class="note">
    <p>
      La sesión se cierra y todos tus tokens se revocan <strong>en el momento</strong>. El borrado definitivo
      ocurre <strong>14 días después</strong>: si volvés a iniciar sesión dentro de ese plazo, la eliminación
      se cancela. Pasado ese plazo es irreversible.
    </p>
  </div>
  <p>Cuando se ejecuta el borrado definitivo:</p>
  <ul>
    <li>
      <strong>Se elimina</strong> tu perfil, tu foto, tus tokens de redes vinculadas, tus dispositivos y
      sesiones, tus notificaciones, tus seguidores y seguidos, tus reseñas, tus listas, tus etiquetas
      privadas, tus reportes (y los que te mencionan), tus invitaciones, tus reclamos de perfil o local,
      tus publicaciones compartidas que no llegaron a publicarse junto con sus archivos, y tu copia de datos
      exportada. También se te quita del buscador de personas.
    </li>
    <li>
      <strong>Se anonimiza</strong> lo que ya es parte del registro de un lugar: las correcciones que
      sugeriste sobre un local siguen existiendo para ese local, pero dejan de estar asociadas a tu nombre,
      y el texto libre que hayas escrito se borra.
    </li>
    <li>
      <strong>Se conserva</strong> el registro de canjes, pagos y asientos contables, sin tu perfil asociado,
      porque la ley exige mantener esa contabilidad.
    </li>
  </ul>

  <h2 id="transferencias">Transferencias internacionales</h2>
  <p>
    Algunos de los proveedores mencionados operan fuera de Uruguay, principalmente en Estados Unidos y la
    Unión Europea. Cuando corresponde, esas transferencias se amparan en las cláusulas contractuales
    estándar u otros mecanismos previstos por la normativa aplicable.
  </p>

  <h2 id="menores">Menores de edad</h2>
  <p>
    Reelmap no está dirigida a menores de 13 años y no recogemos datos de forma consciente de personas de
    esa edad. Si detectamos una cuenta de alguien menor de 13, la eliminamos. Si sos madre, padre o tutor y
    creés que un menor a tu cargo creó una cuenta, escribinos a
    <a href="mailto:{{ $contact }}">{{ $contact }}</a> y la damos de baja.
  </p>

  <h2 id="seguridad">Seguridad</h2>
  <p>
    Todo el tráfico entre la aplicación y nuestros servidores viaja cifrado. Las contraseñas se almacenan
    hasheadas, los secretos de verificación en dos pasos se guardan cifrados, los archivos privados viven en
    almacenamiento no público con enlaces firmados y de vida corta, y los reportes de error no llevan datos
    personales. Ningún sistema es infalible, pero si ocurriera una brecha que afecte tus datos, te lo
    notificaremos y daremos aviso a la autoridad correspondiente en los plazos que exige la normativa.
  </p>

  <h2 id="cambios">Cambios en esta política</h2>
  <p>
    Si cambiamos algo relevante, actualizamos la fecha de vigencia que figura arriba y, cuando el cambio te
    afecte de forma significativa, te avisamos dentro de la aplicación antes de que entre en vigor.
  </p>
@endsection
