<?php
	ini_set('memory_limit', '2G');

	$server1 = [
		'host' => 'fakeServer1',
		'username' => 'fakeUser1',
		'password' => 'fakePassword1',
		'database' => 'fakeBase1',
	];

	$server2 = [
		'host' => 'fakeServer2',
		'username' => 'fakeUser2',
		'password' => 'fakePassword2',
		'database' => 'fakeBase2',
	];

	function connect($config)
	{
		$mysqli = new mysqli($config['host'], $config['username'], $config['password'], $config['database']);
		if ($mysqli->connect_error) {
			die('Connect Error (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error);
		}
		return $mysqli;
	}


	function getTables($conn)
	{
		$tables = [];
		$result = $conn->query("SHOW TABLES");
		while ($row = $result->fetch_array()) {
			$tables[] = $row[0];
		}
		return $tables;
	}

	function compareTables($conn1, $conn2, $table)
	{
		$result1 = $conn1->query("SELECT * FROM $table");
		$result2 = $conn2->query("SELECT * FROM $table");

		if ($result1->num_rows !== $result2->num_rows) {
			writeLog("La tabla $table tiene un número diferente de filas.");
			return;
		}

		while ($row1 = $result1->fetch_assoc()) {
			$row2 = $result2->fetch_assoc();
			if ($row1 !== $row2) {
				writeLog("Diferencia encontrada en la tabla $table:");
				// print_r(array_diff_assoc($row1, $row2));
			}
		}
	}

	$conn1 = connect($server1);
	$conn2 = connect($server2);

	$tables1 = getTables($conn1);
	$tables2 = getTables($conn2);

	if ($tables1 !== $tables2) {
		writeLog("Las tablas no coinciden entre las dos bases de datos.");
		writeLog(json_encode(array_diff($tables1, $tables2)));
		writeLog(json_encode(array_diff($tables2, $tables1)));
		exit;
	}

	foreach ($tables1 as $table) {
		compareTables($conn1, $conn2, $table);
	}

	$conn1->close();
	$conn2->close();


	function writeLog($message, $logFile = './script.log') {
		print $message;
		$fileHandle = fopen($logFile, 'a');
	
		if ($fileHandle) {
			$timestamp = date('Y-m-d H:i:s');
			$formattedMessage = "[$timestamp] $message\n";
	
			fwrite($fileHandle, $formattedMessage);
			fclose($fileHandle);
		} else {
			error_log("No se puede abrir el archivo de log: $logFile");
		}
	}