<?php

$mondayAPIKey = getenv("MONDAY_API_KEY");
$boardID = getenv("MONDAY_BOARD_ID");
$groupName = getenv("MONDAY_GROUP_NAME");

$logFile = fopen("logs/mondayService.log", "a") or die("Unable to open file!");

if ($mondayAPIKey == null || $boardID == null || $groupName == null) {
  fwrite($logFile, "Missing mandatory input parameters: MONDAY_API_KEY and / or MONDAY_BOARD_ID and / or MONDAY_GROUP_NAME\n");
  exit("Missing mandatory input parameters: MONDAY_API_KEY and / or MONDAY_BOARD_ID and / or MONDAY_GROUP_NAME");
}

fwrite($logFile, "*********************************************");
fwrite($logFile, "\nBoard ID: " . $boardID . "\nGroup Name: " . $groupName);

$entityBody = file_get_contents('php://input');
if ($entityBody == null) {
  fwrite($logFile,"\nMissing data input from Keptn. Exiting.");
  exit("Missing data input from Keptn. Exiting.");
}

// Write the raw input to the log file...
fwrite($logFile, "\nEntity Body: " . $entityBody);

//Decode the incoming JSON event
$cloudEvent = json_decode($entityBody);

$keptnResult = strtoupper($cloudEvent->{'data'}->{'result'});
$keptnProject = $cloudEvent->{'data'}->{'project'};
$keptnService = $cloudEvent->{'data'}->{'service'};
$keptnStage = $cloudEvent->{'data'}->{'stage'};

fwrite($logFile, "Keptn Result: " . $keptnResult . "\n");
fwrite($logFile, "Keptn Project: " . $keptnProject . "\n");
fwrite($logFile, "Keptn Service: " . $keptnService . "\n");
fwrite($logFile, "Keptn Stage: " . $keptnStage . "\n");

/****************************************
     Lookup Group ID using group name
****************************************/

$curl = curl_init();

curl_setopt_array($curl, array(
  CURLOPT_URL => "https://api.monday.com/v2",
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => "",
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => "GET",
  CURLOPT_POSTFIELDS =>"{\"query\":\"query {\\r\\nboards (ids: $boardID) {\\r\\n  groups {\\r\\n    id\\r\\n    title\\r\\n  }\\r\\n}\\r\\n}\",\"variables\":{}}",
  CURLOPT_HTTPHEADER => array(
    "Authorization: $mondayAPIKey",
    "Content-Type: application/json"
  ),
));

$response = curl_exec($curl);

curl_close($curl);

$jsonData = json_decode($response, true);

$groupID = "";

// Match the human readable group name to the group ID.
foreach ($jsonData["data"]["boards"] as $board) {
  foreach ($board["groups"] as $group) {
      if ($group["title"] == $groupName) $groupID = $group["id"];
  }
}

fwrite($logFile, "\nGroup ID: " . $groupID);

/*************************************
      Create Item on Monday.com
*************************************/

$curl = curl_init();

curl_setopt_array($curl, array(
  CURLOPT_URL => "https://api.monday.com/v2",
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => "",
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => "GET",
  CURLOPT_POSTFIELDS =>"{\"query\":\"mutation {\\r\\ncreate_item (board_id: $boardID, group_id: $groupID, item_name: \\\"Keptn Result: $keptnResult ($keptnProject / $keptnService / $keptnStage)\\\") {\\r\\nid\\r\\n}\\r\\n}\",\"variables\":{}}",
  CURLOPT_HTTPHEADER => array(
    "Authorization: $mondayAPIKey",
    "Content-Type: application/json"
  ),
));

$response = curl_exec($curl);

curl_close($curl);

$jsonData = json_decode($response, true);

$createdItemID = $jsonData["data"]["create_item"]["id"];

fwrite($logFile, "\nCreated Item Id: " . $createdItemID);

/************************************************
          Push update as a comment to that item
************************************************/

$updateBody = "";
$updateBody .= "<h3>Keptn Test Run Completed Result: " . $keptnResult . "</h3>";
$updateBody .= "Project: <strong>" . $keptnProject . "</strong><br />Service: <strong>" . $keptnService . "</strong><br />Stage: <strong>" . $keptnStage . "</strong>";

// For loop through indicatorResults
$updateBody .= "<br /><br /><h4>SLI Results</h4>";

foreach ($cloudEvent->{'data'}->{'evaluationdetails'}->{'indicatorResults'} as &$value) {
  $updateBody .= "Metric: <strong>" . $value->{'value'}->{'metric'} . "</strong><br />";
  $updateBody .= "Status: <strong>" . $value->{'status'} . "</strong><br />";
  $updateBody .= "Value: <strong>" . $value->{'value'}->{'value'} . "</strong><br />";

  $updateBody .= "<br /><br /><h4>Targets</strong></h4>";
  foreach ($value->{'targets'} as $target) {
      $updateBody .= "Criteria: <strong>" . $target->{'criteria'} . "</strong><br />";
      $updateBody .= "Target Value: <strong>" . $target->{'targetValue'} . "</strong><br />";
      $updateBody .= "Violated: <strong>" . ($target->{'violated'} ? 'true' : 'false') . "</strong><br />";
  }

  if ($value->{'value'}->{'message'} != "") {
    $updateBody .= "Message: <strong>" . $value->{'value'}->{'message'} . "</strong><br />";
  }
}

$updateBody .= "<br /><br />Keptn Context: " . $cloudEvent->{'shkeptncontext'};

$curl = curl_init();

curl_setopt_array($curl, array(
  CURLOPT_URL => "https://api.monday.com/v2",
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => "",
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => "GET",
  CURLOPT_POSTFIELDS =>"{\"query\":\"mutation {\\r\\ncreate_update (item_id: $createdItemID, body: \\\" $updateBody \\\") {\\r\\nid\\r\\n}\\r\\n}\",\"variables\":{}}",
  CURLOPT_HTTPHEADER => array(
    "Authorization: $mondayAPIKey",
    "Content-Type: application/json"
  ),
));

$response = curl_exec($curl);

curl_close($curl);

fwrite($logFile, "\nCreate Item Response: $response");
fwrite($logFile, "\n------- END LOG ENTRY -----------");
fclose($logFile);
?>
