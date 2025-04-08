<?php
	// esto es necesario por el tamaño de las bases 
	// ajustar si hace falta
	ini_set('memory_limit', '4G');
	date_default_timezone_set('America/Argentina/Buenos_Aires');
	
	$config = parse_ini_file('config.ini', true);
	$server1 = $config['SERVER4'];
	$server2 = $config['SV7'];

	$logFile = 'logs/check_replica_'. date('h_i_s'). '.log';
	
	$conn1 = crearConexion($server1);
	$conn2 = crearConexion($server2);

	$tablas1 = obtenerTablas($conn1);
	$tablas2 = obtenerTablas($conn2);

	compararBasesPorTablas($tablas1, $tablas2);

	foreach ($tablas1 as $table) {
		// if(strpos($table, 'energia_') !== 0){
			compararTablas($conn1, $conn2, $table);
		// }
	}

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


	function obtenerTablas($conn)
	{
		$tables = [];
		$result = $conn->query("SHOW TABLES");
		while ($row = $result->fetch_array()) {
			$tables[] = $row[0];
		}
		return $tables;
	}

	function compararTablas($conn1, $conn2, $table)
	{
		
		// ordeno por primer columna
		$result1 = $conn1->query("SELECT * FROM $table ORDER BY 1");
		$result2 = $conn2->query("SELECT * FROM $table ORDER BY 1");

		if ($result1->num_rows !== $result2->num_rows) {
			$server_mas_filas = ($result1->num_rows > $result2->num_rows)
						? 'Server1'
						: 'Server2'; 

			debug("$table : CANTIDAD DE FILAS DIFERENTES ($server_mas_filas tiene más filas) ");

			// Si el nro de filas es diferente no comparo por filas
			return;	
		}

		$error_repetido = 0;
		while ($row1 = $result1->fetch_assoc()) {
			// Siempre va a existir $row2 por verificación anterior
			$row2 = $result2->fetch_assoc();

			// comparación de arreglos por valor 
			if ($row1 != $row2) {
				if ($error_repetido == 0) {
					debug("$table : EXISTEN FILAS DIFERENTES ");
				}
				$error_repetido++;
				
				// $fila1 = json_encode($row1, JSON_PRETTY_PRINT);
				// $fila2 = json_encode($row2, JSON_PRETTY_PRINT);
				// debug("server1 Id: {)} y en server2 Id: {$row2[0]}");
				// print_r(array_diff_assoc($row1, $row2));
			}
		}	
	}

	function compararBasesPorTablas($tablas_server1, $tablas_server2)
	{
		if ($tablas_server1 !== $tablas_server2) {
			debug('-- ERROR ENCONTRADO --');
			debug("Las tablas no coinciden entre las dos servidores.");

			$solo_en_server1 = array_diff($tablas_server1, $tablas_server2);
			$solo_en_server2 = array_diff($tablas_server2, $tablas_server1);

			debug("Estas tablas existen en server1 y no en el server2");
			debug(json_encode($solo_en_server1));
			debug("Estas tablas existen en server2 y no en el server1");
			debug(json_encode($solo_en_server2));

			exit;
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
