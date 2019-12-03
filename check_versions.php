<?php
include "vendor/autoload.php";

use Composer\Semver\Comparator;

$today = date('Y-m-d');
$components = array('php','redis','mariadb','rabbitmq','elasticsearch');
$datamap = array('php'=>13,'redis'=>16,'mariadb'=>14,'rabbitmq'=>17,'elasticsearch'=>15); 

if (($handle = fopen("source.csv", "r")) !== FALSE) {
    fgetcsv($handle, 10000, ","); // skip first line
    while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
	$mver = $data[10];
	$major = substr($mver,0,strrpos($mver,'.'));
	$major_def_json = @file_get_contents($major . '.json');
	$mver_def_json = @file_get_contents($mver . '.json');
	$major_def = json_decode($major_def_json,true);
	$mver_def = json_decode($mver_def_json,true);
	if($major_def_json!=='' && $mver_def_json!=='') {
	    $def = @array_replace_recursive($major_def,$mver_def); // allow mver def to overwrite major ver definitions
	} elseif($major_def_json!=='') {
	    $def = $major_def;
	} else {
	    $def = $mver_def;
	}
	echo $data[0] . ':' . "\n";
	if($def['dateEnd']!=='') {
	    // check if still ok
	    if($def['dateEnd']<$today) {
		echo "Version $mver is no longer supported\n";
	    }	    
	}
	$ver = array();
	$ver[$components[0]] = $data[$datamap[$components[0]]];
	echo $components[0] . " Version " . $ver[$components[0]] . " ";
	if(Comparator::greaterThanOrEqualTo($ver[$components[0]],$def['require'][$components[0]])) {
	    echo "V\n";
	} else {
	    echo "X\n";
	}
	
	echo "\n";    
    }
    fclose($handle);
}
