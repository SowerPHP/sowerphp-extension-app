<?php

/**
 * SowerPHP
 * Copyright (C) SowerPHP (http://sowerphp.org)
 *
 * Este programa es software libre: usted puede redistribuirlo y/o
 * modificarlo bajo los términos de la Licencia Pública General Affero de GNU
 * publicada por la Fundación para el Software Libre, ya sea la versión
 * 3 de la Licencia, o (a su elección) cualquier versión posterior de la
 * misma.
 *
 * Este programa se distribuye con la esperanza de que sea útil, pero
 * SIN GARANTÍA ALGUNA; ni siquiera la garantía implícita
 * MERCANTIL o de APTITUD PARA UN PROPÓSITO DETERMINADO.
 * Consulte los detalles de la Licencia Pública General Affero de GNU para
 * obtener una información más detallada.
 *
 * Debería haber recibido una copia de la Licencia Pública General Affero de GNU
 * junto a este programa.
 * En caso contrario, consulte <http://www.gnu.org/licenses/agpl.html>.
 */

namespace sowerphp\app;

// autor y versión para el código que se está generando
define('AUTHOR', 'SowerPHP Code Generator');
define('VERSION', date(\sowerphp\core\Configure::read('time.format')));

/**
 * Comando para generar código de forma automática
 * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]delaf.cl)
 * @version 2014-04-25
 */
class Shell_Command_CodeGenerator extends \Shell_App
{

    public static $db; ///< Conexión a la base de datos
    public static $destination; ///< Destino de los archivos que se generan
    public static $module; ///< Módulo donde se encontrarán los archivos
    public static $module_url; ///< Url para acceder al módulo
    public static $namespace; /// Namespace en el que se generarán los archivos
    private static $tables; ///< Tablas de la base de datos que se estarán procesando
    private static $nTables; ///< Cantidad de tablas que se estarán procesando

    /**
     * Método principal del comando
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]delaf.cl)
     * @version 2014-04-03
     */
    public function main ()
    {
        // obtener nombre de la base de datos
        $database = $this->selectDatabase();
        if (!$database) {
            $this->out('<error>No se encontró configuración válida para la base de datos</error>');
            exit(1);
        }
        // obtener conexión a la base de datos
        self::$db = &\Model_Datasource_Database::get($database);
        // obtener tablas de la base de datos
        $aux = self::$db->getTables();
        $tables = array();
        foreach ($aux as &$t) {
            $tables[] = $t['name'];
        }
        // mostrar tablas disponibles para que usuario elija cual quiere procesar
        $this->out('Tablas disponibles: '.implode(', ', $tables));
        $procesarTablas = $this->in('Ingresar las tablas que desea procesar [*]: ');
        if (!empty($procesarTablas) && $procesarTablas != '*') {
            if (strpos($procesarTablas, ',')) {
                $procesarTablas = str_replace (' ', '', $procesarTablas);
                $tables = explode (',', $procesarTablas);
            } else {
                $tables = explode (' ', $procesarTablas);
            }
        }
        // obtener información de las tablas
        self::$nTables = count ($tables);
        $nTables = 0;
        $this->out('<info>Recuperando información de las tablas '.round(($nTables/self::$nTables)*100).'%</info>', 0);
        self::$tables = array();
        foreach ($tables as &$table) {
            self::$tables[$table] = self::$db->getInfoFromTable($table);
            $nTables++;
            $this->out("\r".'<info>Recuperando información de las tablas '.round(($nTables/self::$nTables)*100).'%</info>', 0);
        }
        unset($tables);
        unset($nTables);
        $this->out('');
        // obtener destino para los archivos
        self::$destination = $this->selectDestination();
        // determinar namespace
        self::$namespace = 'website'.(!empty(self::$module)?'\\'.str_replace('.', '\\', self::$module):'');
        // crear directorios para archivos que se crearán
        if (!file_exists(self::$destination.'/Model')) mkdir(self::$destination.'/Model');
        if (!file_exists(self::$destination.'/Controller')) mkdir(self::$destination.'/Controller');
        // generar archivos
        $this->generateModel($database);
        $this->generateController();
    }

    /**
     * Método para seleccionar una base de datos en caso de existir múltiples configuraciones
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]delaf.cl)
     * @version 2012-11-09
     */
    private function selectDatabase ()
    {
        $this->out('<info>Seleccionando base de datos para generar código</info>');
        // obtener bases de datos disponibles
        $databases = \sowerphp\core\Configure::read('database');
        $keys = array_keys($databases);
        $encontradas = count($keys);
        // si no hay
        if(!$encontradas) return false;
        // si solo hay una
        else if($encontradas==1) return $keys[0];
        // si hay más de una se debe elegir una
        else if($encontradas>1) {
            // mostrar bases disponibles
            $this->out('Bases de datos disponibles:');
            $i = 1;
            foreach($databases as $name => &$config) {
                $this->out($i.'.- '.$name);
                ++$i;
            }
            // solicitar opción hasta tener una válida
            do {
                $opcion = $this->in('Seleccionar una base de datos: ');
            } while($opcion<1 || $opcion>$encontradas);
            // retornar nombre de la conexión
            return $keys[$opcion-1];
        }
        // no se encontró configuración válida
        return false;
    }

    /**
     * Seleccionar destino donde se guardarán los archivos generados
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]delaf.cl)
     * @version 2012-11-20
     */
    private function selectDestination ()
    {
        // buscar directorios de módulos
        $modulos = $this->getModules();
        $encontradas = count($modulos) + 1;
        // mostrar directorio principal
        $this->out('Directorios de destino disponibles:');
        $this->out('1.- Directorio base de la aplicación');
        // mostrar directorios de módulos disponibles
        $i = 2;
        foreach($modulos as &$modulo) {
            $this->out($i.'.- Módulo '.$modulo);
            ++$i;
        }
        // solicitar opción hasta tener una válida
        do {
            $opcion = $this->in('Seleccionar un directorio para guardar archivos generados: ');
        } while($opcion<1 || $opcion>$encontradas);
        // retornar la ubicación
        if ($opcion == 1) {
            $this->setModuleUrl();
            return DIR_WEBSITE;
        } else {
            $modulo = $modulos[$opcion-2];
            $this->setModuleUrl($modulo);
            $modulo = str_replace('.', '/Module/', $modulo);
            return DIR_WEBSITE.'/Module/'.$modulo;
        }
    }

    /**
     * Buscar recursivamente todos los módulos de la aplicación
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]delaf.cl)
     * @version 2014-03-16
     */
    private function getModules ($dir = null, $parentModule = '')
    {
        // si no se indicó directorio es el principal
        if (!$dir) $dir = DIR_WEBSITE.'/Module';
        // si no existe el directorio terminar de procesar
        if (!is_dir($dir)) return array();
        // buscar módulos en el directorio
        $modulesAux = array_values(array_diff(
            scandir($dir),
            array('..', '.')
        ));
        // agregar módulos encontrados
        $modules = array();
        // por cada módulo procesar el subdirectorio
        foreach ($modulesAux as &$module) {
            // crear padre
            $padre = $parentModule;
            $padre = empty($padre) ? $module : $padre.'.'.$module;
            // agregar módulo
            $modules[] = $padre;
            // buscar submodulos
            $modules = array_merge(
                $modules,
                $this->getModules(
                    $dir.'/'.$module.'/Module',
                    $padre
                )
            );
        }
        // entregar módulos
        return $modules;
    }

    /**
     * Método que asigna el nombre del módulo y su url
     * @param modulo Nombre del módulo donde se generarán los archivos
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]delaf.cl)
     * @version 2014-04-03
     */
    private function setModuleUrl ($modulo = '')
    {
        if (empty($modulo)) {
            self::$module = '';
            self::$module_url = '';
        } else {
            self::$module = $modulo;
            $partes = explode('.', $modulo);
            $module_url = '';
            foreach ($partes as &$p) {
                $module_url .= \sowerphp\core\Utility_Inflector::underscore($p).'/';
            }
            self::$module_url = $module_url;
        }
    }

    /**
     * Método que genera el código para la clase final de modelos
     * @param database Nombre de la conexión a la base de datos
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]delaf.cl)
     * @version 2014-04-25
     */
    private function generateModel ($database)
    {
        $this->out('<info>Generando modelos</info>');
        foreach (self::$tables as $table => &$info) {
            $class = \sowerphp\core\Utility_Inflector::camelize($table);
            $fkNamespace = array();
            $columns = array();
            $columnsInfo = array();
            foreach ($info['columns'] as &$column) {
                // procesar si es FK
                if (is_array($column['fk'])) {
                    $fk_class = \sowerphp\core\Utility_Inflector::camelize($column['fk']['table']);
                    $fkNamespace[] = "'Model_$fk_class' => '".self::$namespace."'";
                }
                // generar atributo
                $columns[] = $this->src('public ${column}; ///< {comment}{type}({length}){null}{default}{auto}{pk}{fk}', array(
                    'column'    => $column['name'],
                    'comment'    => $column['comment']!=''?$column['comment'].': ':'',
                    'type'        => $column['type'],
                    'length'    => $column['length'],
                    'null'        => ($column['null']==='YES'||$column['null']==1)?' NULL':' NOT NULL',
                    'default'    => " DEFAULT '".$column['default']."' ",
                    'auto'        => ($column['auto']==='YES'||$column['auto']==1)?'AUTO ':'',
                    'pk'        => in_array($column['name'], $info['pk'])?'PK ':'',
                    'fk'        => is_array($column['fk'])?'FK:'.$column['fk']['table'].'.'.$column['fk']['column']:'',
                ));
                // generar información de la columna
                $columnsInfo[] = $this->src('Model/columnInfo.phps', array(
                    'column'    => $column['name'],
                    'name'        => \sowerphp\core\Utility_Inflector::humanize($column['name']),
                    'comment'    => $column['comment'],
                    'type'        => $column['type'],
                    'length'    => !empty($column['length'])?$column['length']:'null',
                    'null'        => ($column['null']==='YES'||$column['null']==1)?'true':'false',
                    'default'    => str_replace("'", "\'", $column['default']),
                    'auto'        => ($column['auto']==='YES'||$column['auto']==1)?'true':'false',
                    'pk'        => in_array($column['name'], $info['pk'])?'true':'false',
                    'fk'        => is_array($column['fk']) ? 'array(\'table\' => \''.$column['fk']['table'].'\', \'column\' => \''.$column['fk']['column'].'\')':'null',
                ));
            }
            if (count($fkNamespace)) {
                $fkNamespace = "\n        ".implode(",\n        ", $fkNamespace)."\n    ";
            } else $fkNamespace = '';
            $columns = implode("\n    ", $columns);
            $columnsInfo = implode('', $columnsInfo);
            // nombres de clases
            $class = \sowerphp\core\Utility_Inflector::camelize($table);
            $classs = \sowerphp\core\Utility_Inflector::camelize(\sowerphp\core\Utility_Inflector::pluralize($table));
            // generar modelo singular
            $file = $this->src('Model.phps', array(
                'database' => $database,
                'table' => $table,
                'comment' => $info['comment'],
                'author' => AUTHOR,
                'version' => VERSION,
                'class' => $class,
                'fkNamespace' => $fkNamespace,
                'namespace' => self::$namespace,
                'columns' => $columns,
                'columnsInfo' => $columnsInfo,
            ));
            $filename = self::$destination.'/Model/'.$class.'.php';
            if (!file_exists($filename)) {
                file_put_contents($filename, $file);
            }
            // generar modelo plural
            $file = $this->src('Model/Models.phps', array(
                'database' => $database,
                'table' => $table,
                'comment' => $info['comment'],
                'author' => AUTHOR,
                'version' => VERSION,
                'classs' => $classs,
                'namespace' => self::$namespace,
            ));
            $filename = self::$destination.'/Model/'.$classs.'.php';
            if (!file_exists($filename)) {
                file_put_contents($filename, $file);
            }
        }
    }

    /**
     * Método que genera el código para la clase final del controlador
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]delaf.cl)
     * @version 2014-04-03
     */
    private function generateController ()
    {
        $this->out('<info>Generando controladores</info>');
        foreach (self::$tables as $table => &$info) {
            // generar datos
            $class = \sowerphp\core\Utility_Inflector::camelize($table);
            $classs = \sowerphp\core\Utility_Inflector::camelize(\sowerphp\core\Utility_Inflector::pluralize($table));
            $file = $this->src('Controller.phps', array(
                'table' => $table,
                'comment' => $info['comment'],
                'author' => AUTHOR,
                'version' => VERSION,
                'class' => $class,
                'classs' => $classs,
                'namespace' => self::$namespace,
            ));
            // guardar archivo en el directorio de clases (si no existe)
            $filename = self::$destination.'/Controller/'.$classs.'.php';
            if (!file_exists($filename)) {
                file_put_contents($filename, $file);
            }
        }
    }

    /**
     * Método que renderiza las plantillas para el código
     * @param plantilla Archivo con la plantilla que se debe renderizar
     * @param variables Variables que se deben reemplazar al renderizar
     * @return String Plantilla ya renderizada
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]delaf.cl)
     * @version 2012-11-09
     */
    private function src ($plantilla, $variables = array())
    {
        // location
        $archivo = \sowerphp\core\App::location('Shell/Command/CodeGenerator/'.$plantilla);
        // cargar plantilla
        if($archivo)
            $plantilla = file_get_contents($archivo);
        // reemplazar variables en la plantilla
        foreach($variables as $key => $valor) {
            $plantilla = str_replace('{'.$key.'}', $valor, $plantilla);
        }
        // retornar plantilla ya procesada
        return $plantilla;
    }

}
