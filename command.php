<?

/*
En el caso que no muestre nada, es posible que no se tengan los permisos suficientes.
chmod 777 al archivo y al ttyUSBx, como root.

*/
ob_implicit_flush();
error_reporting(E_ALL);
ini_set('display_errors', '1');     //displays php errors




$CodigosDeRespuestas = array(
			'APROBADO' => '00',
			'RECHAZADO' => '01',
			'HOST_NO_RESPONDE' => '02',
			'CONEXION_FALLO' => '03',
			'TRANSACCION_YA_FUE_ANULADA' => '04',
			'NO_EXISTE_TRANSACCION_PARA_ANULAR' => '05',
			'TARJETA_NO_SOPORTADA' => '06',
			'TRANSACCION_CANCELADA_DESDE_EL_POS' => '07',
			'NO_PUEDE_ANULAR_TRANSACCION_DEBITO' => '08',
			'ERROR_LECTURA_TARJETA' => '09',
			'MONTO_MENOR_AL_MINIMO_PERMITIDO' => '10',
			'NO_EXISTE_VENTA' => '11',
			'TRANSACCION_NO_SOPORTADA' => '12',
			'DEBE_EJECUTAR_CIERRE' => '13',
			'PARA_ESTA_TARJETA_MODO_INVALIDO' => '18',#ADD
			'DIGITO_VERIFICADOR_MALO' => '57', #ADD
			'FECHA_EXPIRADA' => '54',#ADD
			'SOLICITANDO_CONFORMAR_MONTO' => '80',
			'SOLICITANDO_INGRESO_DE_CLAVE' => '81',
			'ENVIANDO_TARNSACCION_AL_HOST' => '82');


#Verifica el Entorno
function CheckEntorno(){

	try {
		if(!function_exists('exec')) {
		   	throw new Exception("La funcion exec esta desabilitada <br />"); 
		}
	}catch(Exception $e) {
		echo $e->getMessage(); 
		exit("Habilitar en php.ini <br />");	
	}


	//obtenemos la ultima salida de dmesg para conocer el ttyusb\d
	exec("dmesg | grep 'ttyUSB \| now attached to' | awk '{print \$NF}'",$salida);
	$ttyUSB = end($salida);unset($salida);

	//Obtengo los permisos
	exec("stat -c '%a' /dev/".$ttyUSB,$salida);


	$chmod = current($salida);


	if($chmod < '777'){
		exit("No Tiene los Permisos suficiente /dev/".$ttyUSB." <br /> Ejecutar : chmod 777 /dev/".$ttyUSB);
	}else{//Tiene los Permisos
		return $ttyUSB;
	}
}

#Funcion que obtiene la Respuesta del POS, opcion = Totales
function RespuestaPOS($fp){
	$x=1;
	$data = null;
	for(;;){
		sleep(1);
		$char		=	fread($fp,$x);
		$largochar 	= 	strlen($char);
			for($i=0;$i<$largochar;$i++){
				$caracter = $char[$i];
				$ord	  = ord($caracter);
					if($ord == 3){//fin
						break 2;
					}else{
						if($ord != 6 and $ord != 2 ){ //ACK & STX
							$data .= $caracter;
						}
					}			
			

			}
	$x++;
	}
return $data;
}

#Funcion Principal, entregar la opcion y devuelve el codigo hex para enviar al serial.
function ComandoHexadecimal($opcion,$venta = false,$monto=false){

	$comando = null;
	$valores = array(
	'<ACK>' => '\x06',
	'<STX>' => '\x02',
	'<ETX>' => '\x03',
	'|'	=> '\x7c');

	$comandos = array(
		'DetalleDeVenta' => array(
			"INICIO" 	=> "<STX>", 		
			"DATA"		=> array("0260","|","1","|"),
			"FINAL"		=> "<ETX>",
			"CHECKSUM"	=> "<LCR>"),
		'UltimaVenta' => array(
			"INICIO" 	=> "<STX>", 		
			"DATA"		=> array("0250","|"), 
			"FINAL"		=> "<ETX>",
			"CHECKSUM"	=> "<LCR>"),
		'Totales' => array(
			"INICIO" 	=> "<STX>", 		
			"DATA"		=> array("0700"), 
			"FINAL"		=> "<ETX>",
			"CHECKSUM"	=> "<LCR>") ,
		'CargaDeLlaves' => array(
			"INICIO" 	=> "<STX>", 		
			"DATA"		=> array("0800"), 
			"FINAL"		=> "<ETX>",
			"CHECKSUM"	=> "<LCR>"),
		'CambioAPosNormal' => array(
			"INICIO" 	=> "<STX>", 		
			"DATA"		=> array("0300"), 
			"FINAL"		=> "<ETX>",
			"CHECKSUM"	=> "<LCR>"),
		'Pooling' => array(
			"INICIO" 	=> "<STX>", 		
			"DATA"		=> array("0100"),
			"FINAL"		=> "<ETX>",
			"CHECKSUM"	=> "<LCR>"),
		'Cierre' => array(
			"INICIO" 	=> "<STX>", 		
			"DATA"		=> array("0500"), 
			"FINAL"		=> "<ETX>",
			"CHECKSUM"	=> "<LCR>"));        

		if($venta){
			$comandos = array(
					'Venta' => array(
						"INICIO" 	=> "<STX>", 		
						"DATA"		=> array("0200","|",$monto,"|", rand(0,9) . rand(0,9) . rand(0,9). rand(0,9) . rand(0,9),"1"), 
						"FINAL"		=> "<ETX>",
						"CHECKSUM"	=> "<LCR>")
			);			
		}


 	

	$valor = $comandos[$opcion]['DATA'];
	$comando .= $valores[$comandos[$opcion]['INICIO']]; //STX

	$LCR = array();
	foreach($valor as $id => $value){

		list($HexData,$binarios) = GetBinarios((string)$value);
		foreach($binarios as $bin){
			$LCR[] = $bin;
		}
		$comando .=	$HexData; //Agrego DATA
	}

	$comando .= $valores[$comandos[$opcion]['FINAL']]; //ETX
	$LCR[] =  hexbin8bits($valores[$comandos[$opcion]['FINAL']]);


	$CHECKSUM = LCR($LCR);
	$comando .= $CHECKSUM;

	$datosAenviar = ParseHexChr($comando);

return $datosAenviar;
}

#Formatear Valores en pesos
function Pesos($var){
	return "$".number_format($var,0,',','.');
}
#Obtiene la secuencia de comandos en hex \x02, y los prepara para enviar.
function ParseHexChr($comando){
	$parsehexa = str_replace("\x", "0x", $comando);
	$hexchunk  = chunk_split($parsehexa,4);
	$chr = explode("\r\n",$hexchunk);
	array_pop($chr);

	$comandobyte = null;
	foreach($chr as $value){
		$comandobyte .= chr($value);
	}
	return $comandobyte;
}

#Obtiene un Array con los valores en Binario de DATA y ETX, returna CheckSum LCR (\x00)
function LCR($LCR){
	$largo = count($LCR);
	$resto = array();
	for($x=0;$x<$largo;$x++){
		if($x==0){
			$resto[$x] = _xor($LCR[$x],$LCR[$x+1]);
			$x = 2;
		}
		$resto[$x-1] =  _xor($resto[$x-2],$LCR[$x]);
	}



	$hex	=	dechex(bindec(end($resto)));
	$field	=	chunk_split($hex,2,"\\x");
	return 	"\\x" . substr($field,0,-2);
}



#Genera Formato hexadecimal /xHEX , /x00.
function FormatHex($hex){
	return "\\x" . substr(chunk_split($hex,2,"\\x"),0,-2);
}

#Obtiene un decimal y conviene su valores en binarios , return Array();
function GetBinarios($var){
	$largo = strlen($var);
	$binarios = array();
	$hex = null;
	for($x=0;$x<$largo;$x++){
		$caracter 	= $var[$x];
		#echo $caracter."\t";
		$ascii 		= ord($caracter);
		$hexadecimal 	= dechex($ascii);
		$hex		.= $hexadecimal;
		$bin 		= hexbin8bits($hexadecimal);
		$binarios[]	= $bin;
	}

	return array(FormatHex($hex),$binarios);



}



#XOR entre dos binarios
function _xor($text,$key){
    for($i=0; $i<strlen($text); $i++){
        $text[$i] = intval($text[$i])^intval($key[$i]);
    }
    return $text;
}


#Funcion que convierte de hexadecimal a binario (8 bits);

function hexbin8bits($hexadecimal){
	$decimal	= hexdec($hexadecimal);
	$binario	= decbin($decimal);
	return substr("00000000",0,8 - strlen($binario)) . $binario;
}

##################################################################################################################

#$monto = '125632';

$monto = $_POST['monto'];
#$monto = '50000';

$datosAenviar = ComandoHexadecimal('Venta',true,$monto);













#$ttyUSB = CheckEntorno();
$html = null;
$address = "192.168.101.9";
$service_port = "5000";

/* Create a TCP/IP socket. */
$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($socket === false) {
    echo "socket_create() failed: reason: " . 
         socket_strerror(socket_last_error()) . "\n";
}

$result = socket_connect($socket, $address, $service_port);
if ($result === false) {
    echo "socket_connect() failed.\nReason: ($result) " . 
          socket_strerror(socket_last_error($socket)) . "\n";
}
$response = '';
socket_write($socket, $datosAenviar, strlen($datosAenviar));
while (true) {
	$out = socket_read($socket,1);
   	$response .= $out;
	#usleep(100000);
	if(ord($out) == 3){
		break;
	}
}





list(	$comando,
	$codigorespuesta,
	$codigocomercio,
	$terminalid,
	$numerodeboleta,
	$codigoautorizacion,
	$monto,
	$numerocuotas,
	$montocuotas,
	$ultimos4ddigitostarjeta,
	$numerooperacion,
	$tipotarjeta,
	$fechacontable,
	$numerocuenta,
	$abreviaciontarjeta,
	$fechatransaccion,
	$horatransaccion,
	$empleado,
	$propina,
	$fin) = explode("|",$response);


$estado = array_search($codigorespuesta, $CodigosDeRespuestas);


if($estado != 'APROBADO'){
	while (true) {
		socket_write($socket,ParseHexChr("0x06"),strlen(ParseHexChr("0x06")));
		$out = socket_read($socket,1);
		if(!empty($out)){
			break;
		}
	}	
	socket_close($socket);
	echo $estado;
	exit();
}



while (true) {
	socket_write($socket,ParseHexChr("0x06"),strlen(ParseHexChr("0x06")));
	$out = socket_read($socket,1);
	if(ord($out) == 3){
		break;
	}
}
socket_close($socket);

list(	$comando,
	$codigorespuesta,
	$codigocomercio,
	$terminalid,
	$numerodeboleta,
	$codigoautorizacion,
	$monto,
	$numerocuotas,
	$montocuotas,
	$ultimos4ddigitostarjeta,
	$numerooperacion,
	$tipotarjeta,
	$fechacontable,
	$numerocuenta,
	$abreviaciontarjeta,
	$fechatransaccion,
	$horatransaccion,
	$empleado,
	$propina,
	$fin) = explode("|",$response);


$html .= 'Codigo Respuesta : '.$codigorespuesta."<br />";
$html .= 'Codigo Comercio : '.$codigocomercio."<br />";
$html .= 'Terminal ID : '.$terminalid."<br />";
$html .= 'Numero Ticket/Boleta : '.$numerodeboleta."<br />";
$html .= 'Codigo Autorizacion : '.$codigoautorizacion."<br />";
$html .= 'Valor Venta : '.$monto."<br />";
$html .= 'Cantidad De Cuotas :'.$numerocuotas."<br />";
$html .= 'Valor Cuota : '.$montocuotas."<br />";
$html .= 'Ultimo Cuadro Digitos : '.$ultimos4ddigitostarjeta."<br />";
$html .= 'Numero Operacion : '.$numerooperacion."<br />";
$html .= 'Tipo Tarjeta : '.$tipotarjeta."<br />";

echo $html;


?>
