*--------------------------------------------------------------------------*
* PROCEDIMIENTO: RegistrarEmpresaCreada
* OBJETIVO: REGISTRAR EN EL SERVIDOR WEB DE LICENCIAS LA EMPRESA CON
*           LA QUE SE TRABAJA (APARTE DEL CONTROL DE EMPRESAS DUEÑA DE LICENCIAS)
*           Muestra mensajes directamente al usuario
*--------------------------------------------------------------------------*
function RegistrarEmpresaCreada
lparameters tcNombreRespaldo, tcGeneral

if pcount() < 2
   tcGeneral = ''
endif

*-- Salida, si se está en desarrollo y no se quiere probar validar licencias ---*
IF _vfp.StartMode = 0 AND NOT gl_ProbarValidarLicencias
    RETURN
ENDIF

*-- Preparar JSON con datos de la petición ---------------------------*
LOCAL lcJson, lcRespaldoBDGeneral
* Convertir tcGeneral a booleano (puede venir como 'true', 'false', '1', '0', etc.)
lcRespaldoBDGeneral = IIF(INLIST(UPPER(ALLTRIM(tcGeneral)), 'TRUE', '1', 'T', 'Y', 'YES', 'SI'), 'true', 'false')

lcJson = '{' + ;
    '"RUC":"'           + gc_RucLic      + '",' + ;
    '"RUC_Creado":"'    + gc_ParRuc      + '",' + ;
    '"Sistema":"FSOFT",'+;
    '"Nombre_Respaldo":"'+ alltrim(tcNombreRespaldo)+ '",' + ;
    '"Usuario":"'       + gc_UsuAct      + '",'  + ;
    '"Respaldo_BD_General":' + lcRespaldoBDGeneral + ;
'}'

*-- Enviar request firmado
LOCAL loRequest
loRequest = fsEnviarRequestFirmado(lcJson, "/apis/Actualizar_Respaldo_Empresa_Creada.php", ;
    gc_ServerLics, gn_PortServerLics, "fsFirmarJSon")

*-- Procesar resultado
IF NOT loRequest.exito
   DO l3msg WITH "Error al tratar de registrar el respaldo."
   RETURN
ENDIF

LOCAL loRespuesta
loRespuesta = loRequest.respuesta

*-- Error reportado por el servicio remoto
IF loRespuesta.Fin != 'OK'
    LOCAL lcMensajeError
    lcMensajeError = IIF(TYPE('loRespuesta.Mensaje') = 'C', ;
        loRespuesta.Mensaje, "Error no identificado.")
    DO l3msg WITH "Error registrando el respaldo:|| " + lcMensajeError
    RETURN
ENDIF

*-- Mostrar mensaje solo si contiene advertencia
LOCAL lcMensaje
lcMensaje = IIF(TYPE('loRespuesta.Mensaje') = 'C', ;
    loRespuesta.Mensaje, 'Información de respaldo actualizada exitosamente')

*-- Solo mostrar si contiene "Advertencia"
IF "Advertencia" $ lcMensaje
    DO l3msg WITH lcMensaje
ENDIF

RETURN

