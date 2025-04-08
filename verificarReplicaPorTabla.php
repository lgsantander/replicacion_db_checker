<?php
	// esto es necesario por el tamaño de las bases 
	// ajustar si hace falta
	ini_set('memory_limit', '4G');
	date_default_timezone_set('America/Argentina/Buenos_Aires');

	$config = parse_ini_file('config.ini', true);
	$server1 = $config['SERVER4'];
	$server2 = $config['SV7'];

	
	if ($argc == 0) {
		print 'Error no se especificó nombre de tabla';
		exit;
	}

	$tablaObjetivo = $argv[1];

	$logFile = "logs/tabla_$tablaObjetivo.log";
	$logAlter = "registro.txt";

	$conn1 = crearConexion($server1);
	$conn2 = crearConexion($server2);
	
	// ordeno por primer columna
	$tabla_s1 = $conn1->query("SELECT * FROM $tablaObjetivo ORDER BY 1");
	$tabla_s2 = $conn2->query("SELECT * FROM $tablaObjetivo ORDER BY 1");

	debug('--- VERIFICACIÓN POR IGUALDAD EN CANTIDAD DE REGISTROS ---');	
	if ($tabla_s1->num_rows !== $tabla_s2->num_rows) {
		$server_mas_filas = ($tabla_s1->num_rows > $tabla_s2->num_rows)
						? 'Server1'
						: 'Server2'; 
		debug("La tabla $tablaObjetivo en $server_mas_filas tiene más registros!");
		
	} 

	debug('--- VERIFICACIÓN POR IGUALDAD DE CLAVES PRIMARIAS ---');	
	if (lasClavesPrimariasSonConsistentes($tabla_s1, $tabla_s2)) {
		debug('Las claves primarias son consistentes entre replicas!');	
	} else {
		exit;
	}

	debug('--- VERIFICACIÓN POR IGUALDAD DE REGISTROS ---');	
	$inconsistencias = existenValoresInconsistentes();

	if (!empty($inconsistencias)) {	
		foreach ($inconsistencias as $row) {
			existeEstaFilaPeroConOtroID($row);
		}
	} else {
		debug('No se encontraron Inconsistencias!');
	}
	debug('--- VERIFICACIÓN POR IGUALDAD DE REGISTROS 2 ---');	
	$diferencias = existenValoresInconsistentes2();

	$conn1->close();
	$conn2->close();



	function crearConexion($config)
	{
		extract($config);

		try {
			$mysqli = new mysqli($host, $user, $pass, $base);
			if ($mysqli->connect_error) {
				$error = "Error al conectar con $host con $user";
				$error .= "\n ERRNO: {$mysqli->connect_errno}";
				$error .= "\n ERROR: {$mysqli->connect_error}";
				throw new Exception($error);
			}
		} catch (Exception $e) {
			debug($e->getMessage());
			die($e->getMessage());
		}
		return $mysqli;
	}


	function lasClavesPrimariasSonConsistentes($tablas_s1, $tablas_s2)
	{
		$son_consistentes = true;
		$rows1 = $tablas_s1->fetch_all(MYSQLI_NUM);
		$rows2 = $tablas_s2->fetch_all(MYSQLI_NUM);
		
		foreach ($rows1 as $row1) {
			$id_1 = $row1[0];
			$claves_primarias_s1[$id_1] = true; 
		}

		foreach ($rows2 as $row2) {
			$id_2 = $row2[0];
			$claves_primarias_s2[$id_2] = true; 

			if (!isset($claves_primarias_s1[$id_2])) {
				$son_consistentes = false;
				debug("Fila con ID $id_2 existe en server2 pero no existe en server1");			
			}
		}

		foreach ($rows1 as $row1) {
			$id_1 = $row1[0]; 
			if (!isset($claves_primarias_s2[$id_1])) {
				$son_consistentes = false;
				debug("Fila con ID $id_1 existe en server1 pero no existe en server2");			
			}
		}

		return $son_consistentes;
	}

	function existenValoresInconsistentes2()
	{
		$diferencias = [];
		$mask = "%-5s %-15s %-10s\n";
		global $conn1, $conn2, $tablaObjetivo;
		$tabla_s1 = $conn1->query("SELECT * FROM $tablaObjetivo ORDER BY 1,2,3");
		$tabla_s2 = $conn2->query("SELECT * FROM $tablaObjetivo ORDER BY 1,2,3");

		$tabla1 = $tabla_s1->fetch_all(MYSQLI_NUM);
		$tabla2 = $tabla_s2->fetch_all(MYSQLI_NUM);
	

		foreach ($tabla1 as $i => $fila_s1) {
			if (isset($tabla2[$i])) {	
				if ($fila_s1 != $tabla2[$i]) {
					$diferencias[$i]['SERVER1'] = str_replace('"', '', json_encode($fila_s1));
				}
			} else {
				$diferencias[$i]['SERVER1'] = "NO EXISTE";
			}
		}

		foreach ($tabla2 as $j => $fila_s2) {
			if (isset($tabla1[$j])) {	
				if ($fila_s2 != $tabla1[$j]) {
					$diferencias[$j]['SERVER2'] = str_replace('"', '', json_encode($fila_s2));
				}
			} else {
				$diferencias[$i]['SERVER2'] = "NO EXISTE";
			}
		}
		// print_r($diferencias);
		printf($mask, "FILA", "SERVER1", "SERVER2");
		foreach ($diferencias as $k => $diff) {
			printf($mask, $k, 
				isset($diff['SERVER1']) 
					? $diff['SERVER1']
					:'NO EXISTE', 
				isset($diff['SERVER2'])
					? $diff['SERVER2'] 
					: 'NO EXISTE' );
		}

		return $diferencias;
	}

	function existenValoresInconsistentes() : array
	{
		$filadiff = '';
		$inconsistentes = [];

		// print "verificando valores inconsistentes";
		global $conn1, $conn2, $tablaObjetivo;
		$tabla_s1 = $conn1->query("SELECT * FROM $tablaObjetivo ORDER BY 1");
		$tabla_s2 = $conn2->query("SELECT * FROM $tablaObjetivo ORDER BY 1");
		
		$filas_s1 = $tabla_s1->fetch_all(MYSQLI_BOTH);
		$filas_s2 = $tabla_s2->fetch_all(MYSQLI_BOTH);
		// print_r($filas_s1);

		foreach ($filas_s1 as $i => $fila_s1) {
			$fila_s2 = $filas_s2[$i];

			if($fila_s1 != $fila_s2) {
				foreach ($fila_s1 as $columna => $valor) {
					if (is_string($columna) && $fila_s2[$columna] !== $valor) {
						$filadiff .= " $columna ";
						$inconsistentes[$i] = $fila_s1;
					}
				}
				debug("fila de ID {$fila_s1[0]} con datos diferentes: $filadiff");
				$filadiff = '';
			}
		}
			
		return $inconsistentes;
	}


	function existeEstaFilaPeroConOtroID($row_server1) 
	{
		global $tablaObjetivo, $conn2;
		$consulta = "select * from $tablaObjetivo where ";
		$cant_atributos = 0;
		$nombre_atributos = [];

		foreach ($row_server1 as $key => $valor) {
			if (is_string($key)) {
				$nombre_atributos[] = $key;
			}
			if (is_int($key)) {
				$cant_atributos++;
			}
		}

		$sql_where = '';
		foreach (range(1, ($cant_atributos-1) ) as $i) {
			$atributo = $nombre_atributos[$i];
			$valor = $row_server1[$i];
			
			if (!empty($valor)) {
				
				$sql_where .= ($i == 1) ? '' : ' and ';
						
				if (is_numeric($valor)) {
					$sql_where .= " $atributo = $valor ";
				} else {
					$sql_where .= $atributo . ' = "'. $valor .'"';	
				}
			}
		}

		$consulta .= $sql_where . " AND $nombre_atributos[0] != $row_server1[0]";

		guardar("$consulta;");
		
		if ($res = @$conn2->query($consulta)) {
			if ( $copiaSinPK = @$res->fetch_row()) {
				debug("FATAL: Existe registro igual a {$row_server1[0]} de server 1 en server2 pero con ID {$copiaSinPK[0]}");
			}
		}
		
		
	}

	function debug($message) {
		global $logFile;
		$timestamp = date('Y-m-d H:i:s');
		$formattedMessage = "[$timestamp] $message\n";
		print $formattedMessage;
	
		$fileHandle = fopen($logFile, 'a');

		if ($fileHandle) {
			fwrite($fileHandle, $formattedMessage);
			fclose($fileHandle);
		} else {
			error_log("No se puede abrir el archivo de log: $logFile");
		}
	}

	function guardar($message) {
		global $logAlter;
		$formattedMessage = "$message\n";
		// print $formattedMessage;
	
		$fileHandle = fopen($logAlter, 'a');

		if ($fileHandle) {
			fwrite($fileHandle, $formattedMessage);
			fclose($fileHandle);
		} else {
			error_log("No se puede abrir el archivo de log: $logAlter");
		}
	}

