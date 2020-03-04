<?php
include "vendor/autoload.php";
include "zendesk.php";

use Composer\Semver\Comparator;

$today = date('Y-m-d');
$components = array('php','redis','mariadb','rabbitmq','elasticsearch', 'ecetools','fastly');
$datamap = array('php'=>13,'redis'=>16,'mariadb'=>14,'rabbitmq'=>17,'elasticsearch'=>15,'ecetools'=>11,'fastly'=>12); 

$update_docs = array(
'magento'=>"https://devdocs.magento.com/cloud/project/project-upgrade.html",
'php'=>"https://devdocs.magento.com/cloud/project/project-conf-files_magento-app.html#type-and-build and https://devdocs.magento.com/cloud/project/project-upgrade.html#update-the-configuration-file",
'redis'=> "https://devdocs.magento.com/cloud/project/project-conf-files_services.html",
'mariadb'=> "https://devdocs.magento.com/cloud/project/project-conf-files_services.html",
'rabbitmq'=> "https://devdocs.magento.com/cloud/project/project-conf-files_services.html",
'elasticsearch'=> "https://devdocs.magento.com/cloud/project/project-conf-files_services.html",
'fastly'=>"https://devdocs.magento.com/cloud/cdn/configure-fastly.html#upgrade",
'ecetools'=>"https://devdocs.magento.com/cloud/project/ece-tools-update.html"
);

$notinstalled_docs = array(
'redis'=> "https://devdocs.magento.com/cloud/project/project-conf-files_services.html",
'elasticsearch'=>"https://devdocs.magento.com/cloud/project/project-conf-files_services-elastic.html",
'rabbitmq'=>" https://devdocs.magento.com/cloud/project/project-conf-files_services-rabbit.html",
'scd'=> "https://devdocs.magento.com/cloud/deploy/static-content-deployment.html");



$num_compl = 0;

if (($handle = fopen("source.csv", "r")) !== FALSE) {
    fgetcsv($handle, 10000, ","); // skip first line
    
    
    
    $cloud_pro_def = json_decode(file_get_contents('cloud/cloud-pro.json'),true);
    $cloud_starter_def = json_decode(file_get_contents('cloud/cloud-starter.json'),true);

    while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {

	$ticket_data = "";
	$is_compliant = true;
	$noncompliance_pts = 0;
	$prefix = $data[7]  . ': ';

	echo $prefix . ' : ' . $data[0] . "\n"; // project ID
	$mver = $data[10];
	if($mver == "N/A" || $mver == "NA" || $mver == "Error") {
	    echo $prefix . "W Missing version data\n";
	    $is_compliant = false; $noncompliance_pts++;
	} else {
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
	if($def['dateEnd']!='') {
	    // check if still ok
	    if($def['dateEnd']<$today) {
		echo $prefix . "X Version $mver is no longer supported\n";
		$ticket_data .= "X: Magento version $mver is no longer supported. Please update your Magento version. More information:" . $update_docs['magento'] ."\n";
		$is_compliant = false; $noncompliance_pts++;
	    }	    
	}
	}
	$ver = array();
	for($i=0;$i<count($components);$i++) {
	    $component_ver = $data[$datamap[$components[$i]]];
	if($component_ver  == "N/A" || $component_ver  == "NA" || $component_ver  == "Error") {
	    echo $prefix . "W Missing data for " . $components[$i] . "\n";
	    $is_compliant = false; $noncompliance_pts++;
	} else {
	    
	    $ver[$components[$i]] = $component_ver;
	        
	    echo $prefix . $components[$i] . " Version " . $component_ver . " ";
	    
	    $envtype = substr($prefix,8,3);
	    if($envtype == 'pro') {
		$cloud_def = $cloud_pro_def;
	    } else {
		$cloud_def = $cloud_starter_def;
	    }
	    $component_def = '';
	    $component_fail = false;
	    
	    $is_latest_on_cloud = false;
	    if(isset($cloud_def['latest'][$components[$i]]) && Comparator::greaterThanOrEqualTo($component_ver,$cloud_def['latest'][$components[$i]])) {
		$is_latest_on_cloud = true;
	    }
	    
	    
	    if(isset($def['require']) && Comparator::greaterThanOrEqualTo($component_ver,$def['require'][$components[$i]])) {
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
	    	if(isset($component_def['dateEnd']) && $component_def['dateEnd']!='') {
		    // if date is defined and we are after EOL and if version is earlier than latest on cloud
		    if($component_def['dateEnd']<$today && !$is_latest_on_cloud ) {
			$component_fail = true;
			echo $prefix . "X $component_ver is no longer actively supported by the vendor\n";
			$ticket_data .= "X: Component " . strtoupper($components[$i]) . " (Version " . $component_ver . ") is no longer actively supported by the vendor. Please update this component. More information: " . $update_docs[$components[$i]] . "\n";
			$is_compliant = false; $noncompliance_pts++;
		    }
		}
		if(isset($component_def['latest']) && Comparator::lessThan($component_ver, $component_def['latest']) && !$is_latest_on_cloud) {
		    // we are running not the latest version
       		    $arr_latest_ver = explode('.',$component_major_def['latest']);
		    $latest_minus_one = manipulateVersionString($arr_latest_ver, 3, -1);
		    
		    if(Comparator::notEqualTo($component_ver,$latest_minus_one)) {
			// if this is more than one version back - then X
			echo $prefix . "X $component_ver is no longer actively supported by the vendor\n";
			$ticket_data .= "X: Component " . strtoupper($components[$i]) . " (Version " . $component_ver . ") is no longer actively supported by the vendor. Please update this component. More information: " . $update_docs[$components[$i]] . "\n";
			$component_fail = true;
			$is_compliant = false; $noncompliance_pts++;
		    } else { 
			// we are on latest-1 version. check if the latest one was released more than 30 days ago
			if(isset($component_latest_dev['dateStart']) && $component_latest_dev['dateStart']!='') {
			    $date_today = date_create($today);
			    $data_start_of_latest = date_create($component_latest_dev['dateStart']);
			    $interval = date_diff($date_today, $date_start_of_latest);
			    
			    if(abs($interval->d)>30) {
				// we are past due for PCI
				echo $prefix . "X $component_ver is not the latest version and new version was released more than 30 days ago\n";
			        $ticket_data .= "X: Component " . strtoupper($components[$i]) . " (Version " . $component_ver . ") is not the latest version and a new version was released more than a month ago. Please update this component. More information: " .$update_docs[$components[$i]] . "\n";
				$is_compliant = false; $noncompliance_pts++;
			    } else {
				echo $prefix . "W $component_ver is not the latest version but might still be supported, since less than 30 days passed from new version release\n";
				$ticket_data .= "W: Component " . strtoupper($components[$i]) . " (Version " . $component_ver . ") is not the latest version but might still be supported, since less than 30 days passed from new version release. Please update this component. More information: " . $update_docs[$components[$i]] . "\n";
			    }
		        }  else {
			    echo $prefix . "W $component_ver is not the latest version but might still be supported\n";
		 	$ticket_data .= "W: Component " . strtoupper($components[$i]) . " (Version " . $component_ver . ") is not the latest version but might still be supported. Please update this component. More info: " .  $update_docs[$components[$i]] ."\n";
			    
			}
			$component_fail = true;
		    }
		}
	    } elseif($component_ver!='Not installed') {
		echo $prefix . "X $component_ver is not recommended for this version of Magento and might no longer be supported by the vendor\n";
		$ticket_data .= "X: Component " . strtoupper($components[$i]) . " (Version " . $component_ver . ") is not recommended for this version of Magento and might no longer be supported by the vendor. Please update this component. More information: " .$update_docs[$components[$i]] . "\n";
		
		$component_fail = true;
		$is_compliant = false; $noncompliance_pts++;
	    } elseif($component_ver=='Not installed') {
		$ticket_data .= "W: Component " . strtoupper($components[$i]) . " is not installed. It's recommended to use it. More information: " . $notinstalled_docs[$components[$i]] . "\n";
	    }
	    if (!$component_fail) echo "V\n";
	
	    }
	}
	// check MCC -  MCC is deprecated, Customers must not have this
	$mcc = $data[9];
	if($mcc!='N/A') {
		echo $prefix . "You are using a very old version of cloud tools. Please upgrade to ECE Tools\n";
		$ticket_data .= "X: You are using a very old version of cloud tools. Please upgrade to ECE Tools.\n";
		$is_compliant = false; $noncompliance_pts++;
	}
	// check SCD on build - We highly recommend to have this enabled
	$scd = $data[18];
	if($scd!='Yes') {
		echo $prefix . "You are not taking advantage of streamlined deployment process, which makes them much faster\n";		
		$ticket_data .= "W: You are not taking advantage of the streamlined deployment process, which makes deployments much faster. More information: " .$notinstalled_docs['scd'] . "\n";
		$is_compliant = false; $noncompliance_pts++;
	}

	// internal checks that don't need to be exposed initially
	// Branch conflict – Should not be considered after Wings migration
	// Integrations – Does not matter
	// Enabled environments – Should be Production for Pro and Master for Starter
	// Active integration - Does not matter
	// Auto crons – Depends on Wings migration state, ideally enabled
 



	if($is_compliant) {
	    echo $prefix . "V Site compliant\n";
	} else {
	    echo $prefix . "X Site NOT compliant\n";
	}
	echo $prefix . "Pts for non compliance: " . $noncompliance_pts . "\n";
	echo "\n";
	//echo $ticket_data . "\n\n";
	if($noncompliance_pts>0) {
	    sendTicket($data[0], $ticket_data, $mver, "");
	    exit(0);
	}
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


