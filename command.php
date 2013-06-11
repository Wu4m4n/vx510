<?
#header( 'Content-type: text/html; charset=utf-8' );
/*
En el caso que no muestre nada, es posible que no se tengan los permisos suficientes.
chmod 777 al archivo y al ttyUSBx, como root.

*/
#ini_set("zlib.output_compression", 0);  // off
#ini_set("implicit_flush", 1);  // on 

ob_implicit_flush(1);
#ignore_user_abort(true);
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
			'ULTIMOS_CUATRO_DIGITOS_INVALIDO' => '17',
			'PARA_ESTA_TARJETA_MODO_INVALIDO' => '18',
			'RECHAZADO' => '73', 
			'DIGITO_VERIFICADOR_MALO' => '57', 
			'FECHA_EXPIRADA' => '54',
			'SOLICITANDO_CONFORMAR_MONTO' => '80',
			'SOLICITANDO_INGRESO_DE_CLAVE' => '81',			#OK
			'ENVIANDO_TARNSACCION_AL_HOST' => '82',			#OK
			'DESLICE_LA_TARJETA' => '78',				#OK
			'CONFIRMAR_MONTO' => '79',				#OK
			'ENVIO_TX_A_TRANSBANK' => '82');			#OK






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
						"DATA"		=> array("0200","|",$monto,"|",rand(0,9) . rand(0,9) . rand(0,9). rand(0,9) . rand(0,9),"|","|","|",1), 
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


function force_flush(){ ob_start();ob_end_flush();ob_flush(); }


##################################################################################################################






?>


<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <title>Mirax</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="">
    <meta name="author" content="">
    <!-- Le styles -->
    <link href="bootstrap/css/bootstrap.css" rel="stylesheet">
    <script src="bootstrap/js/jquery.1.9.1.js"></script>
    <style type="text/css">
      body {
        padding-top: 60px;
        padding-bottom: 40px;
      }
	.control-label {
	margin-top: 10px;
	}
	.controls {
	margin-top: 10px;
	}
    </style>
    <link href="bootstrap/css/bootstrap-responsive.css" rel="stylesheet">
   <script type="text/javascript">

   $(function() {

	});
	</script>
  </head>

  <body>

<div class="container">

      <!-- Main hero unit for a primary marketing message or call to action -->
<h1>POS vx510</h1>
<!-- Example row of columns -->
<div class="row">
<div class="span4">
       

<form class="form-horizontal" action="command.php" method="POST" id="FormVenta">
	<fieldset>
		<legend>Venta</legend>
		<div class="control-group">
			<label class="control-label" for="inputEmail">Monto : </label>
			<div class="controls">
				<input name="monto" type="text" id="inputEmail" placeholder="$">
				<button id="ejecutar" style="margin-top:15px;" type="submit" class="btn pull-right">Ejecutar</button>
			</div>
		</div>
	</fieldset>
</form>

<p id="resultado" style="display:inline;">
<?php
if(!empty($_POST['monto']) && isset($_POST['monto'])){


	$monto = $_POST['monto'];

	$datosAenviar = ComandoHexadecimal('Venta',true,$monto);




	$html = null;
	$address = "192.168.101.9";
	$service_port = "5000";

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
	$contador = 0;
	$mensajes = '';

	while (true) {
		$out = socket_read($socket,1);
	   	$response .= $out;
		$mensajes .= $out;
	 	#print $response."\n";
		if(ord($out) == 3 && strlen($mensajes) > 1){
			list($mensaje,$valor,$end) = explode("|",$mensajes);
			$estado = array_search($valor, $CodigosDeRespuestas);


			#echo $estado." \t".$valor."\n";
			echo $estado."<br />";
			#VALIDO
			$validos = array("00");
			if (in_array($valor, $validos)) {
					while (true) {
						socket_write($socket,ParseHexChr("0x06"),strlen(ParseHexChr("0x06")));
						$out = socket_read($socket,1);
						#echo "\t".$out."\n";
						if(ord($out) == 3 || $out === false){
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
								$fin) = explode("|",$mensajes);

							$html = '';
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

							break 2;
						}
					}

			}else{
					#?
					$valores = array_values($CodigosDeRespuestas);
					$arr = array_diff($valores, array("00","78","79","81","82"));
					$invalidos = array_values($arr);
			
				
					if (in_array($valor, $invalidos)) {
						while (true) {
							socket_write($socket,ParseHexChr("0x06"),strlen(ParseHexChr("0x06")));
							$out = socket_read($socket,1);
							#echo $out."\n";
							if(!empty($out)){
								break 2;
							}
						}

					}else{
						#echo "···························\n";
						#print nl2br($estado."\n");
			 		#	print $response."\n";
				#		print $mensajes."\n";
						#echo "···························\n";
					}



			}

			$mensajes = ''; 
		}else{
			#$contador++;
		}
	force_flush();
	}
	socket_close($socket);

}
?>

</p>
</div>
</div>

<hr>

      <footer>
        <p>&copy; Mirax 2013</p>
      </footer>

</div> <!-- /container -->



<?






?>

  </body>
</html>
