<?php
include "vendor/autoload.php";

use Composer\Semver\Comparator;

$today = date('Y-m-d');
$components = array('php','redis','mariadb','rabbitmq','elasticsearch', 'ecetools','fastly');
$datamap = array('php'=>13,'redis'=>16,'mariadb'=>14,'rabbitmq'=>17,'elasticsearch'=>15,'ecetools'=>11,'fastly'=>12); 

if (($handle = fopen("source.csv", "r")) !== FALSE) {
    fgetcsv($handle, 10000, ","); // skip first line
    while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
	$mver = $data[10];
	$major = substr($mver,0,strrpos($mver,'.'));
	$major_def_json = @file_get_contents('magento/' . $major . '.json');
	$mver_def_json = @file_get_contents('magento/' . $mver . '.json');
	$major_def = json_decode($major_def_json,true);
	$mver_def = json_decode($mver_def_json,true);
	if($major_def_json!='' && $mver_def_json!='') {
	    $def = @array_replace_recursive($major_def,$mver_def); // allow mver def to overwrite major ver definitions
	} elseif($major_def_json!=='') {
	    $def = $major_def;
	} else {
	    $def = $mver_def;
	}
	echo $data[0] . ':' . "\n";
	if($def['dateEnd']!='') {
	    // check if still ok
	    if($def['dateEnd']<$today) {
		echo "Version $mver is no longer supported\n";
	    }	    
	}
	$ver = array();
	for($i=0;$i<count($components);$i++) {
	    $component_ver = $data[$datamap[$components[$i]]];
	    $ver[$components[$i]] = $component_ver;
	    echo $components[$i] . " Version " . $component_ver . " ";
	    $component_def = '';
	    $component_fail = false;
	    if(Comparator::greaterThanOrEqualTo($component_ver,$def['require'][$components[$i]])) {
		// minor version
	    	$component_major = substr($component_ver,0,strrpos($component_ver,'.'));
		$component_major_def_json = @file_get_contents('components/' . $components[$i] . '-' . $component_major . '.json');
		$component_major_def = json_decode($component_major_def_json,true);
		// patch version
		$component_ver_def_json = @file_get_contents('components/' . $components[$i] . '-' . $component_ver . '.json');
	    	$component_ver_def = json_decode($component_ver_def_json,true);
		// latest possible version
		$component_latest_def_json = @file_get_contents('components/' . $components[$i] . '-' . $component_major_def['latest'] . '.json');
	    	$component_latest_def = json_decode($component_latest_def_json,true);
		if($component_major_def_json!='' && $component_ver_def_json!='') {
		    $component_def = @array_replace_recursive($component_major_def,$component_ver_def); // allow mver def to overwrite major ver definitions
		} elseif($component_major_def_json!=='') {
		    $component_def = $component_major_def;
		} else {
		    $component_def = $component_ver_def;
		}
	    	$component_fail = false;
	    	if($component_def['dateEnd']!='') {
		    // if date is defined and we are after EOL
		    if($component_def['dateEnd']<$today) {
			$component_fail = true;
			echo "X $component_ver is no longer actively supported by the vendor\n";
		    }
		}
		if(Comparator::lessThan($component_ver, $component_def['latest'])) {
		    // we are running not the latest version
       		    $arr_latest_ver = explode('.',$component_major_def['latest']);
		    $latest_minus_one = manipulateVersionString($arr_latest_ver, 3, -1);
		    
		    if(Comparator::notEqualTo($component_ver,$latest_minus_one)) {
			// if this is more than one version back - then X
			echo "X $component_ver is no longer actively supported by the vendor\n";
			$component_fail = true;
		    } else { 
			// we are on latest-1 version. check if the latest one was released more than 30 days ago
			if(isset($component_latest_dev['dateStart']) && $component_latest_dev['dateStart']!='') {
			    $date_today = date_create($today);
			    $data_start_of_latest = date_create($component_latest_dev['dateStart']);
			    $interval = date_diff($date_today, $date_start_of_latest);
			    
			    if(abs($interval->d)>30) {
				// we are past due for PCI
				echo "X $component_ver is not the latest version and new version was released more than 30 days ago\n";
			    } else {
				echo "W $component_ver is not the latest version but might still be supported, since less than 30 days passed from new version release\n";
			    }
		        }  else {
			    echo "W $component_ver is not the latest version but might still be supported\n";
			}
			$component_fail = true;
		    }
		}
	    } else {
		echo "X $component_ver is not recommended for this version of Magento\n";
		$component_fail = true;
	    }
	    if (!$component_fail) echo "V\n";
	
	}
	// check MCC -  MCC is deprecated, Customers must not have this
	$mcc = $data[9];
	if($mcc!='N/A') {
		echo "You are using a very old version of cloud tools. Please upgrade to ECE Tools\n";
	}
	// check SCD on build - We highly recommend to have this enabled
	$scd = $data[18];
	if($scd!='Yes') {
		echo "You are not taking advantage of streamlined deployment process, which makes them much faster\n";		
	}

	// internal checks that don't need to be exposed initially
	// Branch conflict – Should not be considered after Wings migration
	// Integrations – Does not matter
	// Enabled environments – Should be Production for Pro and Master for Starter
	// Active integration - Does not matter
	// Auto crons – Depends on Wings migration state, ideally enabled
 


	echo "\n";    
    }
    fclose($handle);
}

function manipulateVersionString($matches, $position, $increment = 0, $pad = '0')
    {
        for ($i = 4; $i > 0; --$i) {
            if ($i > $position) {
                $matches[$i-1] = $pad;
            } elseif ($i === $position && $increment) {
                $matches[$i-1] += $increment;
                // If $matches[$i] was 0, carry the decrement
                if ($matches[$i-1] < 0) {
                    $matches[$i-1] = $pad;
                    --$position;
                    // Return null on a carry overflow
                    if ($i === 1) {
                        return null;
                    }
                }
            }
        }
        return $matches[0] . '.' . $matches[1] . '.' . $matches[2];
    }


