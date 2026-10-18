<?php
declare(strict_types=1);

require_once __DIR__ . '/../Applicants/php_files/auth-common.php';
require_once __DIR__ . '/http-regression-db.php';

function edit_hydration_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function edit_hydration_temporary_pdf_fixture(): string { $temporary=tempnam(sys_get_temp_dir(),'aics-edit-'); if($temporary===false)throw new RuntimeException('Unable to create temporary PDF fixture.'); $path=$temporary.'.pdf'; if(!rename($temporary,$path))throw new RuntimeException('Unable to name temporary PDF fixture.'); $pdf="%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n"; if(file_put_contents($path,$pdf)===false)throw new RuntimeException('Unable to write temporary PDF fixture.'); return $path; }
/** @return array{status:int,location:string,cookie:string,body:string,error:string} */
function edit_hydration_http(string $url, string $method = 'GET', array $fields = [], string $cookie = '', array $files = []): array {
    $headers = ['Connection: close']; $body = '';
    if ($files !== []) { $boundary='----AicsEdit'.bin2hex(random_bytes(8)); foreach($fields as $name=>$value){$body.="--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";} foreach($files as $name=>$path){$bytes=file_get_contents($path); if(!is_string($bytes))throw new RuntimeException('Fixture unavailable.'); $body.="--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"; filename=\"".basename($path)."\"\r\nContent-Type: application/pdf\r\n\r\n{$bytes}\r\n";} $body.="--{$boundary}--\r\n"; $headers[]='Content-Type: multipart/form-data; boundary='.$boundary; }
    elseif ($method === 'POST') { $body=http_build_query($fields,'','&',PHP_QUERY_RFC3986); $headers[]='Content-Type: application/x-www-form-urlencoded'; }
    if($body!=='')$headers[]='Content-Length: '.strlen($body); if($cookie!=='')$headers[]='Cookie: '.$cookie;
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$body,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>60]]);
    $response=file_get_contents($url,false,$context); $status=0;$location='';$responseCookie=$cookie; $error=is_string($response)?'':'HTTP client returned no response';
    foreach($http_response_header??[] as $i=>$header){if($i===0&&preg_match('/\s(\d{3})\s/',$header,$m)===1)$status=(int)$m[1]; elseif(stripos($header,'Location:')===0)$location=trim(substr($header,9)); elseif(stripos($header,'Set-Cookie:')===0)$responseCookie=trim(explode(';',substr($header,11))[0]??'');}
    return ['status'=>$status,'location'=>$location,'cookie'=>$responseCookie,'body'=>is_string($response)?$response:'','error'=>$error];
}
/** @return array<string,mixed> */
function edit_hydration_bootstrap(string $html): array {
    if(preg_match('/window\.AICS_EDIT_BOOTSTRAP\s*=\s*(\{.*?\});/s',$html,$m)!==1)throw new RuntimeException('Edit bootstrap missing.');
    $data=json_decode($m[1],true); if(!is_array($data))throw new RuntimeException('Edit bootstrap invalid.'); return $data;
}
function edit_hydration_grant(string $base, string $cookie, int $requestId): string {
    $cancel=edit_hydration_http($base.'/php_files/cancel-request.php','GET',[],$cookie);
    edit_hydration_assert($cancel['status']===200,'Could not open Cancel Request.');
    preg_match('/edit-request\.php\?request_id='.preg_quote((string)$requestId,'/').'&amp;token=([^"\']+)/',$cancel['body'],$m);
    edit_hydration_assert(isset($m[1]),'Edit token missing.');
    $grant=edit_hydration_http($base.'/php_files/edit-request.php?request_id='.$requestId.'&token='.html_entity_decode($m[1]),'GET',[],$cookie);
    edit_hydration_assert($grant['status']===302,'Edit grant was not issued.');
    return $cookie;
}

$db=aics_db_connect();
if (strtolower(AICS_DB_NAME) === 'aicsdb') throw new RuntimeException('Refusing fixture-producing HTTP regression against the development database. Configure an isolated HTTP runtime database.');
aics_http_regression_require_seed_data($db);
aics_ensure_auth_schema($db);
$base=rtrim((string)(getenv('AICS_HTTP_BASE_URL')?:'http://localhost/AICS/AICS/Applicants'),'/');
$phone='09'.random_int(100000000,999999999); $password='EditHydration2026'; $accountId=0; $requestId=0; $reference=''; $authoritativeDate='';
$fixture=edit_hydration_temporary_pdf_fixture();
try {
    $signup=edit_hydration_http($base.'/php_files/auth-signup.php','POST',['first_name'=>'Legacy','last_name'=>'Hydration','contact_number'=>$phone,'password'=>$password,'confirm_password'=>$password]);
    edit_hydration_assert($signup['status']===302,'Signup failed: HTTP '.$signup['status'].' '.$signup['error']);
    $lookup=$db->prepare('SELECT account_id FROM aics_applicant_profiles WHERE contact_number=?');$lookup->bind_param('s',$phone);$lookup->execute();$accountId=(int)($lookup->get_result()?->fetch_assoc()['account_id']??0);$lookup->close();
    $db->query("UPDATE aics_applicant_profiles SET middle_name=NULL, extension_name=NULL, date_of_birth='1986-02-03', civil_status='married', highest_educational_attainment='College Graduate', occupation='Self-Employed', estimated_monthly_income='₱20,000 – ₱29,999', province='Antique', municipality='San Jose de Buenavista', barangay='Atabay', address_line='Purok 9', complete_address='Purok 9, Atabay, San Jose de Buenavista, Antique' WHERE account_id=".(int)$accountId);
    $login=edit_hydration_http($base.'/php_files/auth-login.php','POST',['login_identifier'=>$phone,'password'=>$password]); edit_hydration_assert($login['status']===302&&$login['cookie']!=='','Login failed.');
    $fields=['date_applied'=>date('Y-m-d'),'beneficiary_first_name'=>'Beneficiary','beneficiary_last_name'=>'Distinct','beneficiary_birth_date'=>'1950-01-01','beneficiary_age'=>'76','beneficiary_complete_name'=>'Beneficiary Distinct','contact_number'=>'09999999999','gender'=>'female','employment'=>'Pensioner','estimated_income'=>'Below ₱10,000','problem_presented'=>'Edit hydration regression.','client_category'=>'senior_citizen','assistance_type'=>'special_cases','assistance_type_other'=>'Hydration regression case','urgency_response'=>'no','claim_status'=>'unclaimed','amount'=>'0'];
    // The stale fixture used to include a client date; applicants no longer
    // submit one on either create or edit.
    unset($fields['date_applied']);
    $fields['family_members_not_applicable'] = '1';
    $fields += ['beneficiary_barangay'=>'Poblacion','beneficiary_sitio_purok'=>'Purok 4','beneficiary_municipality'=>'Hamtic','beneficiary_province'=>'Antique'];
    $saved=edit_hydration_http($base.'/php_files/save-request.php','POST',$fields,$login['cookie'],['doc_valid_id'=>$fixture,'doc_report_police_med'=>$fixture]); $savedData=json_decode($saved['body'],true); edit_hydration_assert($saved['status']===200&&($savedData['ok']??false),'Initial submission failed: '.$saved['body']); $reference=(string)$savedData['reference_no'];
    $find=$db->prepare('SELECT r.id, r.date_applied FROM aics_requests r JOIN aics_account_requests ar ON ar.request_id=r.id WHERE ar.account_id=? AND r.reference_no=?');$find->bind_param('is',$accountId,$reference);$find->execute();$created=$find->get_result()?->fetch_assoc();$requestId=(int)($created['id']??0);$authoritativeDate=(string)($created['date_applied']??'');$find->close();
    edit_hydration_assert($requestId>0&&$authoritativeDate!=='','New submission did not receive a server-authoritative application date.');
    $db->query("UPDATE aics_requests SET applicant_first_name='', applicant_middle_name='', applicant_last_name='', applicant_extension='', applicant_birth_date=NULL, applicant_age=0, civil_status=NULL, civil_status_other=NULL, highest_educational_attainment=NULL, applicant_contact_number=NULL, applicant_email=NULL, applicant_occupation=NULL, applicant_estimated_monthly_income=NULL, barangay='', sitio_purok='', municipality='', province='' WHERE id=".(int)$requestId);
    edit_hydration_grant($base,$login['cookie'],$requestId); $legacyPage=edit_hydration_http($base.'/php_files/submit-request.php?edit_request_id='.$requestId,'GET',[],$login['cookie']); $legacy=edit_hydration_bootstrap($legacyPage['body'])['form_values'];
    edit_hydration_assert(($legacy['civil_status']??'')==='married'&&($legacy['highest_educational_attainment']??'')==='College Graduate'&&($legacy['applicant_occupation']??'')==='Self-Employed'&&($legacy['applicant_estimated_monthly_income']??'')==='₱20,000 – ₱29,999','A: legacy Applicant fallback failed.');
    edit_hydration_assert(($legacy['beneficiary_first_name']??'')==='Beneficiary'&&($legacy['employment']??'')==='Pensioner'&&($legacy['estimated_income']??'')==='Below ₱10,000','F: beneficiary data was contaminated by profile values.');
    edit_hydration_assert(($legacy['applicant_middle_name']??'')===''&&($legacy['applicant_email']??'')==='','E: blank optional Applicant values were replaced.');
    $fields['edit_mode']='1';$fields['edit_request_id']=(string)$requestId; $edited=edit_hydration_http($base.'/php_files/save-request.php','POST',$fields,$login['cookie']);$editedData=json_decode($edited['body'],true);edit_hydration_assert($edited['status']===200&&($editedData['ok']??false),'B: edit save failed: '.$edited['body']);
    $row=$db->query('SELECT date_applied,civil_status,highest_educational_attainment,applicant_occupation,applicant_estimated_monthly_income,applicant_contact_number,applicant_email FROM aics_requests WHERE id='.(int)$requestId)?->fetch_assoc();edit_hydration_assert(($row['date_applied']??'')===$authoritativeDate&&($row['civil_status']??'')==='married'&&($row['applicant_occupation']??'')==='Self-Employed'&&($row['applicant_contact_number']??'')===$phone,'B: edit did not preserve the date or populate the Applicant snapshot.');
    edit_hydration_grant($base,$login['cookie'],$requestId);
    $staleDateFields=$fields; $staleDateFields['date_applied']='1999-01-01';
    $staleDateSave=edit_hydration_http($base.'/php_files/save-request.php','POST',$staleDateFields,$login['cookie']);$staleDateData=json_decode($staleDateSave['body'],true);edit_hydration_assert($staleDateSave['status']===200&&($staleDateData['ok']??false),'B: stale client date edit failed: '.$staleDateSave['body']);
    $preservedDate=(string)($db->query('SELECT date_applied FROM aics_requests WHERE id='.(int)$requestId)?->fetch_assoc()['date_applied']??'');edit_hydration_assert($preservedDate===$authoritativeDate,'B: stale client date overwrote the historical application date.');
    $db->query("UPDATE aics_applicant_profiles SET civil_status='single', highest_educational_attainment='Elementary Graduate', occupation='Student', estimated_monthly_income='No Income', contact_number='09123456789' WHERE account_id=".(int)$accountId);
    edit_hydration_grant($base,$login['cookie'],$requestId); $modernPage=edit_hydration_http($base.'/php_files/submit-request.php?edit_request_id='.$requestId,'GET',[],$login['cookie']);$modern=edit_hydration_bootstrap($modernPage['body'])['form_values'];
    edit_hydration_assert(($modern['civil_status']??'')==='married'&&($modern['highest_educational_attainment']??'')==='College Graduate'&&($modern['applicant_occupation']??'')==='Self-Employed'&&($modern['applicant_estimated_monthly_income']??'')==='₱20,000 – ₱29,999'&&($modern['applicant_contact_number']??'')===$phone,'C/D: modern request did not retain its saved Applicant snapshot.');
    echo "Applicant edit hydration regression checks A-F passed.\n";
} finally {
    if($requestId>0){$delete=$db->prepare('DELETE FROM aics_requests WHERE id=?');$delete->bind_param('i',$requestId);$delete->execute();$delete->close();}
    if($accountId>0){$delete=$db->prepare('DELETE FROM aics_accounts WHERE id=?');$delete->bind_param('i',$accountId);$delete->execute();$delete->close();}
    aics_http_regression_remove_upload_directory($reference);
    if(is_file($fixture))@unlink($fixture);
    $db->close();
}
