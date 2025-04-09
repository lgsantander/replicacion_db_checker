# Scripts para verificar replica MySQL master to master
> Desarrollado en base a la replica mysql entre SERVER4 y SV7

Los scripts en este repositorio se crearon para encontrar diferencias en una replica de bases bases de datos mysql. Se hicieron teniendo en cuenta las convenciones usadas en las bases de datos de la organización.

## Como usar
Descargar los scripts con:
```
git clone https://github.com/lgsantander/replicacion_db_checker.git
```

Antes de usar los scripts, completar el archivo `config.ini`, con los datos de conexión de cada servidor MySQL.

Luego, para ejecutar una verificación general de la replica, ejecutar: 
```
php verificarReplica.php
```

Esto mostrará las entidades con diferencias, más algún detalle de la misma. Este detalle puede mostrar solo un tipo de diferencia, para ver analizar más una tabla, usar el siguiente script:

```
php verificarReplicaPorTabla.php <Nombre_de_Entidad>
```

Siempre contrastar los resultados revisando la base de datos. A veces puede dar diferencias en vistas o en casos raros en marcas de tiempo con diferente huso horario. 

<br>

---

<br>

# Cómo corregir diferencias
> Lineamientos básicos para resolver inconsistencias

En raras ocasiones, pueda ser necesario corregir diferencias especificas o la replicación completa.

Para tener más claro los pasos a realizar, lo primero que se puede hacer es verificar el estado de la replica en cada servidor, ejecutando:

``` 
SHOW SLAVE STATUS \G;     # En el CLI de MySQL es necesario usar \G
```
y
```
php verificarReplica.php
```
Cuando la replica entra en estado de error, por lo general se detiene la replicación. En nuestro caso es así, no hay configuraciones especificas para evitar la detenención. Los escenarios se podrían dividir según el resultado de este comando, es decir, cuando hay o no error en el estado de la replicación.

- ### La réplica se detuvo y muestra errores
En este caso, si es un error muy simple y la replica lleva poco timepo detenida, una opción es [saltear](#saltear) el error. Caso contrario, lo mejor es terminar de detener la replica en ambos servidores y consolidar las tablas con exactamente los mismos datos. Suponiendo que los servidores son SERVER A y SERVER B.

En `SERVER A`
```
STOP SLAVE;
```

***Igualar datos en ambos servidores según corresponda. Analizar diferencias con los scripts verificarReplica, también en HeidiSQL*** 

Luego volver a iniciar la replica actualizando el archivo de log y posición posición actual.


Después en `SERVER B` ejecutar 
```
SHOW MASTER STATUS; 
```

| File  | Position|
| ------------- | ------------- |
| mysql-bin.000088  | 552660603  |
>Salida de ejemplo

En `SERVER A` usar los datos recien obtenidos
```
CHANGE MASTER TO MASTER_LOG_FILE='binlog.000088', MASTER_LOG_POS=552660603;
START SLAVE;
SHOW MASTER STATUS; 
```

| File  | Position|
| ------------- | ------------- |
| mysql-bin.000034  | 234456093 |
> Salida de ejemplo

En `SERVER B` usar la última salida del obtenida del `SERVER A`
```
CHANGE MASTER TO MASTER_LOG_FILE='binlog.000034', MASTER_LOG_POS=234456093;
START SLAVE;
```

Verificar en ambos servidores con `SHOW SLAVE STATUS \G;` y el script **verificarReplica.php**

- ### La réplica no muestra errores que la hayan detenido pero hay diferencias
Si la replica sigue funcionando, pero el script verificarReplica.php dió diferencias en algunas tablas. En este caso, si ***está claro qué nodo tiene los datos actualizados*** se pueden consolidar los datos en caliente con la herramienta `pt-table-sync`

Esto fue útil para sincronizar las tablas de energía_*
1. Instalar el paquete ( host ubuntu/debian o windows (wsl) )
```
sudo apt install percona-toolkit
```

2. Ver operaciones que ejecutará
```
pt-table-sync \
  --sync-to-master h=[IP_nodo_desactualizado] \
  --user=[usuario] --password=[contraseña] \
  --database=[basedatos] --table=[tabla_desactualizada] \
  --verbose --print
```

3. Ejecutar sincronización desde el nodo desactualizado
```
pt-table-sync \
  --sync-to-master h=[IP_nodo_desactualizado] \
  --user=[usuario] --password=[contraseña] \
  --database=[basedatos] --table=[tabla_desactualizada] \
  --verbose --execute
```

4. Verificar la tabla luego
```
php verificarReplicaPorTabla [tabla_desactualizada]
```
- ### No hay un único server que tenga todos los datos actualizados
Si la replica sigue funcionando, hay diferencias y no hay un claro nodo "master" con los datos reales, en este caso hay que analizar las diferencias con:

```
php verificarReplicaPorTabla [tabla]
```
Hay que tratar de dejar ambos nodos iguales con sentencias como 
  - UPDATE
  - INSERT IGNORE
  - INSERT ... ON DUPLICATE KEY UPDATE

Copiando un subconjunto de filas con HeidiSQL de un lado a otro según corresponda.

Esto puede pasar en tablas en las se ejecuten muchas operaciones de escritura en ambos servidores, por ejemplo, veisolicitudes.


---
<a name="saltear" />

### En caso de un error muy simple que se puede solventar rápido
Se puede saltear el error del log
> ⚠ Solo usar en caso de estar seguro de que el evento salteado no causará inconsistencias críticas

> ⚠ No usar como solución permanente: Luego hay que investigar la causa raíz
```
STOP SLAVE;
SET GLOBAL sql_slave_skip_counter = 1;  -- Salta 1 evento del binlog
START SLAVE;
```
Utilizar esto con precaución, sólo para casos muy raros donde hubo un problema con una sola fila que puede ser consolidada facilmente después.

Esto permitirá que la replicación continue y solucionar la inconsistencia en caliente luego.