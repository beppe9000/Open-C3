<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Elastix version 1.2-2                                               |
  | http://www.elastix.org                                               |
  +----------------------------------------------------------------------+
  | Copyright (c) 2006 Palosanto Solutions S. A.                         |
  +----------------------------------------------------------------------+
  | Cdla. Nueva Kennedy Calle E 222 y 9na. Este                          |
  | Telfs. 2283-268, 2294-440, 2284-356                                  |
  | Guayaquil - Ecuador                                                  |
  | http://www.palosanto.com                                             |
  +----------------------------------------------------------------------+
  | The contents of this file are subject to the General Public License  |
  | (GPL) Version 2 (the "License"); you may not use this file except in |
  | compliance with the License. You may obtain a copy of the License at |
  | http://www.opensource.org/licenses/gpl-license.php                   |
  |                                                                      |
  | Software distributed under the License is distributed on an "AS IS"  |
  | basis, WITHOUT WARRANTY OF ANY KIND, either express or implied. See  |
  | the License for the specific language governing rights and           |
  | limitations under the License.                                       |
  +----------------------------------------------------------------------+
  | The Original Code is: Elastix Open Source.                           |
  | The Initial Developer of the Original Code is PaloSanto Solutions    |
  +----------------------------------------------------------------------+
  $Id: MultiplexServer.class.php,v 1.48 2009/03/26 13:46:58 alex Exp $ */

abstract class MultiplexServer
{
	protected $_oLog;		// Objeto log para reportar problemas
	private $_hEscucha;		// Socket de escucha para nuevas conexiones
	private $_conexiones;	// Lista de conexiones atendidas con clientes
	private $_uniqueid;
	
    /**
     * Constructor del objeto. Se esperan los siguientes parámetros:
     * @param	string	$sUrlSocket		Especificación del socket de escucha
     * @param	object	$oLog			Referencia a objeto de log
     * 
     * El constructor abre el socket de escucha (p.ej. tcp://127.0.0.1:20005
     * o unix:///tmp/dialer.sock) y desactiva el bloqueo para poder usar
     * stream_select() sobre el socket. 
     */
    function MultiplexServer($sUrlSocket, &$oLog)
    {
    	$this->_oLog =& $oLog;
    	$this->_conexiones = array();
    	$this->_uniqueid = 0;
    	$errno = $errstr = NULL;    	
    	$this->_hEscucha = stream_socket_server($sUrlSocket, $errno, $errstr);
    	if (!$this->_hEscucha) {
            $this->_oLog->output("ERR: no se puede iniciar socket de escucha $sUrlSocket: ($errno) $errstr");
        } else {
            // No bloquearse en escucha de conexiones
            stream_set_blocking($this->_hEscucha, 0);
            $this->_oLog->output("INFO: escuchando peticiones en $sUrlSocket ...");
        }
    }
    
    /**
     * Función que verifica si la escucha está activa.
     * 
     * @return	bool	VERDADERO si escucha está activa, FALSO si no.
     */
    function escuchaActiva()
    {
    	return ($this->_hEscucha !== FALSE);
    }
    
    /**
     * Procedimiento que revisa los sockets para llenar los búferes de lectura
     * y vaciar los búferes de escritura según sea necesario. También se 
     * verifica si hay nuevas conexiones para preparar.
     * 
     * @return	VERDADERO si alguna conexión tuvo actividad
     */
    function procesarActividad()
    {
        $bNuevosDatos = FALSE;
        $listoLeer = array();
        $listoEscribir = array();
        $listoErr = NULL;

        // Recolectar todos los descriptores que se monitorean
        $listoLeer[] = $this->_hEscucha;        // Escucha de nuevas conexiones
        foreach ($this->_conexiones as &$conexion) {
            if (!$conexion['exit_request']) $listoLeer[] = $conexion['socket'];
            if (strlen($conexion['pendiente_escribir']) > 0) {
                $listoEscribir[] = $conexion['socket'];                
            }
        }
        $iNumCambio = stream_select($listoLeer, $listoEscribir, $listoErr, 1);
        if ($iNumCambio === false) {
            // Interrupción, tal vez una señal
            $this->_oLog->output("INFO: select() finaliza con fallo - señal pendiente?");
        } elseif ($iNumCambio > 0 || count($listoLeer) > 0 || count($listoEscribir) > 0) {
            if (in_array($this->_hEscucha, $listoLeer)) {
                // Entra una conexión nueva
                $this->_procesarConexionNueva();
                $bNuevosDatos = TRUE;
            }
            foreach ($this->_conexiones as $sKey => &$conexion) {
                if (in_array($conexion['socket'], $listoEscribir)) {
                    // Escribir lo más que se puede de los datos pendientes por mostrar
                    $iBytesEscritos = fwrite($conexion['socket'], $conexion['pendiente_escribir']);
                    if ($iBytesEscritos === FALSE) {
                        $this->oMainLog->output("ERR: error al escribir datos a ".$conexion['socket']);
                        $this->_cerrarConexion($sKey);
                    } else {
                        $conexion['pendiente_escribir'] = substr($conexion['pendiente_escribir'], $iBytesEscritos);
                        $bNuevosDatos = TRUE;                        
                    }
                }
                if (in_array($conexion['socket'], $listoLeer)) {
					// Leer datos de la conexión lista para leer
                    $this->_procesarEntradaConexion($sKey);
                    $bNuevosDatos = TRUE;
                }
            }

            // Cerrar todas las conexiones que no tienen más datos que mostrar
            // y que han marcado que deben terminarse
            foreach ($this->_conexiones as $sKey => &$conexion) {
                if (is_array($conexion) && $conexion['exit_request'] && strlen($conexion['pendiente_escribir']) <= 0) {
                    $this->_cerrarConexion($sKey);
                }
            }

            // Remover todos los elementos seteados a FALSE
            $this->_conexiones = array_filter($this->_conexiones);
        }
        
        return $bNuevosDatos;
    }

	// Procesar una nueva conexión que ingresa al servidor
    private function _procesarConexionNueva()
    {
        $hConexion = stream_socket_accept($this->_hEscucha);
        $sKey = $this->agregarConexion($hConexion);
        $this->procesarInicial($sKey); 
    }

    /**
     * Procedimiento que agrega una conexión socket arbitraria a la lista de los
     * sockets que hay que monitorear para escucha. 
     * 
     * @param mixed $hConexion Conexión socket a agregar a la lista
     * 
     * @return Clave a usar para identificar la conexión
     */
    protected function agregarConexion($hConexion)
    {
        $nuevaConn = array(
            'socket'                =>  $hConexion,
            'pendiente_leer'        =>  '',
            'pendiente_escribir'    =>  '',
            'exit_request'          =>  FALSE,
        );
        stream_set_blocking($nuevaConn['socket'], 0);                

        $sKey = "K_{$this->_uniqueid}";
        $this->_uniqueid++;
        $this->_conexiones[$sKey] =& $nuevaConn;
        return $sKey;
    }

    // Procesar datos nuevos recibidos en una conexión existente
    private function _procesarEntradaConexion($sKey)
    {
        $sNuevaEntrada = fread($this->_conexiones[$sKey]['socket'], 128 * 1024);
        if ($sNuevaEntrada == '') {
            // Lectura de cadena vacía indica que se ha cerrado la conexión remotamente
	        $this->_cerrarConexion($sKey);
	        return ;
        }
        $this->_conexiones[$sKey]['pendiente_leer'] .= $sNuevaEntrada;
		$this->procesarNuevosDatos($sKey);
    }

	/**
	 * Recuperar los primeros $iMaxBytes del búfer de lectura. Por omisión se
	 * devuelve la totalidad del búfer.
	 * @param	string	$sKey		Clave de la conexión pasada a procesarNuevosDatos()
	 * @param	int		$iMaxBytes	Longitud máxima en bytes a devolver (por omisión todo)
	 * 
	 * @return	string	Cadena con los datos del bufer 
	 */ 
	protected function obtenerDatosLeidos($sKey, $iMaxBytes = 0)
	{
		$iMaxBytes = (int)$iMaxBytes;
		if (!isset($this->_conexiones[$sKey])) return NULL;
		return ($iMaxBytes > 0) 
			? substr($this->_conexiones[$sKey]['pendiente_leer'], 0, $iMaxBytes) 
			: $this->_conexiones[$sKey]['pendiente_leer'];
	}

	/**
	 * Descartar los $iMaxBytes primeros bytes de datos del búfer de lectura
	 * pendiente, bajo la suposición de que ya han sido procesados.
	 * @param	string	$sKey		Clave de la conexión pasada a procesarNuevosDatos()
	 * @param	int		$iMaxBytes	Número de bytes a descartar del inicio del búfer.
	 * 
	 * @return	void
	 */
	protected function descartarDatosLeidos($sKey, $iMaxBytes)
	{
		$iMaxBytes = (int)$iMaxBytes;
		if (!isset($this->_conexiones[$sKey])) return;
		if ($iMaxBytes < 0) return;		
		$this->_conexiones[$sKey]['pendiente_leer'] = 
			(strlen($this->_conexiones[$sKey]['pendiente_leer']) > $iMaxBytes)
			? substr($this->_conexiones[$sKey]['pendiente_leer'], $iMaxBytes)
			: '';
	}

	/**
	 * Agregar datos al búfer de escritura pendiente, los cuales serán escritos
	 * al cliente durante la siguiente llamada a procesarActividad()
	 * @param	string	$sKey	Clave de la conexión pasada a procesarNuevosDatos()
	 * @param	string	$s		Búfer de datos a agregar a los datos a escribir.
	 * 
	 * @return	void
	 */
	public function encolarDatosEscribir($sKey, &$s)
	{
		if (!isset($this->_conexiones[$sKey])) return;
        $this->_conexiones[$sKey]['pendiente_escribir'] .= $s;
	}

	/**
	 * Marcar que el socket indicado debe de cerrarse. Ya no se procesarán más
	 * datos de entrada del socket indicado, desde el punto de vista de la
	 * aplicación. Todos los datos pendientes por escribir se escribirán antes
	 * de cerrar el socket.
	 * @param	string	$sKey	Clave de la conexión pasada a procesarNuevosDatos()
	 * 
	 * @return	void
	 */
	public function marcarCerrado($sKey)
	{
		if (!isset($this->_conexiones[$sKey])) return;
		$this->_conexiones[$sKey]['exit_request'] = TRUE;
	}

	// Procesar realmente una conexión que debe cerrarse
    private function _cerrarConexion($sKey)
    {
        fclose($this->_conexiones[$sKey]['socket']);
        $this->_conexiones[$sKey] = FALSE;  // Será removido por array_map()
        $this->procesarCierre($sKey);
    }

    /**
     * Procedimiento que se debe implementar en la subclase, para manejar la
     * apertura inicial del socket, para poder escribir datos antes de recibir
     * peticiones del cliente. En este punto no hay hay datos leidos del 
     * cliente.
	 * @param	string	$sKey	Clave de la conexión recién creada.
	 * 
	 * @return	void
     */
	abstract protected function procesarInicial($sKey);

	/**
	 * Procedimiento que se debe implementar en la subclase, para manejar datos
	 * nuevos enviados desde el cliente.
	 * @param	string	$sKey	Clave de la conexión con datos nuevos
	 * 
	 * @return	void
	 */
	abstract protected function procesarNuevosDatos($sKey);

    /**
     * Procedimiento que se debe implementar en la subclase, para manejar el 
     * cierre de la conexión.
     * @param   string  $sKey   Clave de la conexión cerrada
     * 
     * @return  void
     */
    abstract protected function procesarCierre($sKey);
}
?>