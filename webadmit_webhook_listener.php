<?php
ini_set('memory_limit', '-1');

//Get callback from WebAdmit
$key = 'f148bd717568fe2b2c8fbeec44c44b91';
$userId = '280465';

header('Content-Type: application/json');
$json = file_get_contents('php://input');
$request = json_decode($json, true);

//Create connection to Salesforce.com instance
define("USERNAME", "azuckermanre@usa.edu.redev");
define("PASSWORD", "OmnivoFall2018!");
define("SECURITY_TOKEN", "33u454gypb0g8K0bgm33s45W");

require_once ('soapclient/SforcePartnerClient.php');

$mySforceConnection = new SforcePartnerClient();
$mySforceConnection->createConnection("soapclient/partner_sandbox.wsdl.xml");
$mySforceConnection->login(USERNAME, PASSWORD.SECURITY_TOKEN);

$casIds = array();

//Initialize arrays for Applications
$casIdtoFile = array();
$casIdtoEncodedFile = array();

//Initialize arrays for Transcripts
$casIdDocIdtoFile = array();
$casIdDocIdtoEncodedFile = array();
$documentIdToCasId = array();

$pdfName = $request["pdf_manager_batch"]["pdf_manager_template"]["name"];

//Loop through download hrefs and get file
$i = 1;
foreach($request["pdf_manager_batch"]["download_hrefs"] as $zip_download){
    
    // Get cURL resource
    $curl = curl_init();
    
    $dateTimeIndex = date('YmdHis'). '_' . $i;
    $output_filename = "application_" . $dateTimeIndex . '.zip';
    $extract_path = "/myzips/" . $dateTimeIndex . '/';
    $fp = fopen($output_filename, 'w');
    
    // Set some options
    curl_setopt($curl, CURLOPT_URL, 'https://api.webadmit.org/' . $zip_download);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array('x-api-key:' . $key));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_AUTOREFERER, true);
    
    // Send the request
    $content = curl_exec($curl);
    
    // Close request to clear up some resources
    curl_close($curl);
    
    fwrite($fp, $content);
    fclose($fp);

    //unzip file
    $zip = new ZipArchive;
    $res = $zip->open($output_filename);
    if ($res === TRUE) {
      $zip->extractTo(dirname(__FILE__).$extract_path);
      $zip->close();
    }
    
    //Iterate through extracted files in the extract path
    $dir = dirname(__FILE__).$extract_path.'*';
    $dirNoStar = str_replace('*','',$dir);
    
  	//Get CAS Id and Document ID if applicable from filename
    foreach(glob($dir) as $file) {
        $fileOnly = str_replace($dirNoStar,'',$file);
        $fileParts = explode("_",$fileOnly);
        $casId = $fileParts[0];
        
        if(strpos($pdfName, 'Full_Application') !== false) {
            $casIdtoFile[$casId] = $file; 
            $casIdtoEncodedFile[$casId] = base64_encode(file_get_contents($file));
            array_push($casIds,$casId);
        }
        
        if(strpos($pdfName, 'Transcripts') !== false) {
            $documentId = $fileParts[1];
            $documentIdToCasId[$documentId] = $casId;
            $casIdDocIdtoFile[$casId.'~'.$documentId] = $file; 
            $casIdDocIdtoEncodedFile[$casId.'~'.$documentId] = base64_encode(file_get_contents($file)); 
            array_push($casIds,$casId);
        }    
    }
  	
    //Create CAS Id set for query string
    $casIdsCommaSeperated = implode("','",$casIds);
    
    $i++;
}

//Execute Opportunity query to get Salesforce Id and CAS Id
$query = "SELECT Id, Name, CAS_ID__c from Opportunity WHERE CAS_ID__c IN ('".$casIdsCommaSeperated."')";
$response = $mySforceConnection->query($query);
$sObjects = array();

//Create map of CAS Ids to Salesforce Ids
$casIdToSFId = array();
foreach ($response as $record) {
    $casIdToSFId[$record->fields->CAS_ID__c] = $record->Id;
}

//Iterate through response and create array of attachment sObjects to be sent to Salesforce.com
echo '<b>Processing the following files:</b><br/>';
echo 'pdfName---' . $pdfName;
if(strpos($pdfName, 'Full_Application') !== false) {
    foreach ($response as $record) {
        $filename = basename($casIdtoFile[$record->fields->CAS_ID__c]);
        echo $filename . '<br/>';
        $data = $casIdtoEncodedFile[$record->fields->CAS_ID__c];
        
        //The target Attachment Sobject
        $createFields = array(
            'Body' => $data,
            'Name' => $filename,
            'ParentId' => $record->Id,
            'isPrivate' => 'false'
        );
        $sObject = new stdClass();
        $sObject->fields = $createFields;
        $sObject->type = 'Attachment';
        
        array_push($sObjects,$sObject);
    }
}

if(strpos($pdfName, 'Transcripts') !== false) {
    foreach ($documentIdToCasId as $doc => $cas) {
        $filename = basename($casIdDocIdtoFile[$cas.'~'.$doc]);
        echo $filename . '<br/>';
        $data = $casIdDocIdtoEncodedFile[$cas.'~'.$doc];
            
        // the target Sobject
        $createFields = array(
            'Body' => $data,
            'Name' => $filename,
            'ParentId' => $casIdToSFId[$cas],
            'isPrivate' => 'false'
        );
        $sObject = new stdClass();
        $sObject->fields = $createFields;
        $sObject->type = 'Attachment';
        
        array_push($sObjects,$sObject);
    }
}

echo '<b>Creating Attachments for Salesforce:</b><br/>';
foreach ($sObjects as $attachment) {
    $createResponse = $mySforceConnection->create(array($attachment));
    print_r($createResponse);
    echo '<br/>';   
}
?>