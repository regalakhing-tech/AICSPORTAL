<?php
declare(strict_types=1);

require_once __DIR__ . '/../Applicants/php_files/auth-common.php';
require_once __DIR__ . '/../Applicants/php_files/draft-lib.php';
require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';
require_once __DIR__ . '/http-regression-db.php';

/** @return array{status:int,location:string,cookie:string,body:string,content_type:string} */
function flow_http(string $url, string $method = 'GET', array $fields = [], string $cookie = '', array $files = [], bool $json = false): array
{
    $headers = ['Connection: close']; $content = '';
    if ($files !== []) {
        $boundary = '----AicsDataFlow' . bin2hex(random_bytes(10));
        foreach ($fields as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $fieldName = is_array($value) ? $name . '[]' : $name;
                $content .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$fieldName}\"\r\n\r\n{$item}\r\n";
            }
        }
        foreach ($files as $name => $path) {
            $bytes = file_get_contents($path); if (!is_string($bytes)) throw new RuntimeException('Fixture unavailable.');
            $content .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"; filename=\"" . basename($path) . "\"\r\nContent-Type: application/pdf\r\n\r\n{$bytes}\r\n";
        }
        $content .= "--{$boundary}--\r\n"; $headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
    } elseif ($method === 'POST') {
        $content = $json ? json_encode($fields, JSON_THROW_ON_ERROR) : http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $headers[] = 'Content-Type: ' . ($json ? 'application/json' : 'application/x-www-form-urlencoded');
    }
    if ($content !== '') $headers[] = 'Content-Length: ' . strlen($content);
    if ($cookie !== '') $headers[] = 'Cookie: ' . $cookie;
    $context = stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$content,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0,'timeout'=>60]]);
    $body = file_get_contents($url, false, $context); $status=0; $location=''; $responseCookie=$cookie; $contentType='';
    foreach ($http_response_header ?? [] as $index => $header) {
        if ($index===0 && preg_match('/\s(\d{3})\s/',$header,$m)===1) $status=(int)$m[1];
        elseif (stripos($header,'Location:')===0) $location=trim(substr($header,9));
        elseif (stripos($header,'Content-Type:')===0) $contentType=trim(substr($header,13));
        elseif (stripos($header,'Set-Cookie:')===0) $responseCookie=trim(explode(';',substr($header,11))[0]??'');
    }
    return ['status'=>$status,'location'=>$location,'cookie'=>$responseCookie,'body'=>is_string($body)?$body:'','content_type'=>$contentType];
}

function flow_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

/** Creates a disposable PDF upload fixture; no applicant upload is reused. */
function flow_temporary_pdf_fixture(): string
{
    $temporary = tempnam(sys_get_temp_dir(), 'aics-flow-');
    if ($temporary === false) throw new RuntimeException('Unable to create temporary PDF fixture.');
    $path = $temporary . '.pdf';
    if (!rename($temporary, $path)) throw new RuntimeException('Unable to name temporary PDF fixture.');
    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    if (file_put_contents($path, $pdf) === false) throw new RuntimeException('Unable to write temporary PDF fixture.');
    return $path;
}

/** @return array<string,mixed> */
function flow_fields(string $beneficiaryPhone): array
{
    return [
        'date_applied'=>date('Y-m-d'),'beneficiary_first_name'=>'Elena','beneficiary_middle_name'=>'Santos','beneficiary_last_name'=>'Reyes',
        'beneficiary_birth_date'=>'1950-04-12','beneficiary_age'=>'76','beneficiary_complete_name'=>'Elena Santos Reyes','contact_number'=>$beneficiaryPhone,
        'beneficiary_barangay'=>'Atabay','beneficiary_sitio_purok'=>'Purok 7','beneficiary_municipality'=>'San Jose de Buenavista','beneficiary_province'=>'Antique',
        'employment'=>'Pensioner','estimated_income'=>'Below ₱10,000','gender'=>'female','client_category'=>'senior_citizen',
        'family_name'=>['Marco Reyes','Liza Reyes'],'family_age'=>['48','42'],'family_relationship'=>['Child','Child'],
        'family_occupation'=>['Driver','Employed – Private'],'family_occupation_other'=>['',''],
        'family_education'=>['College Undergraduate','College Graduate'],'family_estimated_income'=>['₱10,000 – ₱19,999','₱20,000 – ₱29,999'],
        'assistance_type'=>'medical','urgency_response'=>'yes','problem_presented'=>'Complete Medical application data-flow regression.','claim_status'=>'unclaimed','amount'=>'0',
    ];
}

$connection = aics_db_connect();
if (strtolower(AICS_DB_NAME) === 'aicsdb') throw new RuntimeException('Refusing fixture-producing HTTP regression against the development database. Configure an isolated HTTP runtime database.');
aics_http_regression_require_seed_data($connection);
aics_ensure_auth_schema($connection); aics_ensure_draft_schema($connection);
$applicantBase = rtrim((string)(getenv('AICS_HTTP_BASE_URL') ?: 'http://localhost/AICS/AICS/Applicants'),'/');
$pswdoBase = dirname($applicantBase) . '/Pswdo'; $password='DataFlow2026'; $applicantId=0; $secondaryApplicantId=0; $staffId=0; $requestId=0; $referenceNo=''; $draftId='';
$phone=''; $secondaryPhone=''; $beneficiaryPhone=''; $staffEmail='flow.pswdo.'.bin2hex(random_bytes(4)).'@example.com';
$fixture = flow_temporary_pdf_fixture(); $secondaryRequestId = 0; $obsoleteSubmissionFiles = [];

try {
    do $phone='09'.random_int(100000000,999999999); while(aics_mobile_number_in_use($connection,$phone));
    do $beneficiaryPhone='09'.random_int(100000000,999999999); while($beneficiaryPhone===$phone);
    $signup=flow_http($applicantBase.'/php_files/auth-signup.php','POST',['first_name'=>'Ramon','last_name'=>'Dela Cruz','contact_number'=>$phone,'password'=>$password,'confirm_password'=>$password]);
    flow_assert($signup['status']===302,'Temporary Applicant signup failed.');
    $stmt=$connection->prepare('SELECT account_id FROM aics_applicant_profiles WHERE contact_number=?'); $stmt->bind_param('s',$phone); $stmt->execute(); $applicantId=(int)($stmt->get_result()?->fetch_assoc()['account_id']??0); $stmt->close();
    flow_assert($applicantId>0,'Temporary Applicant missing.');
    $stmt=$connection->prepare("UPDATE aics_applicant_profiles SET date_of_birth='1985-05-05',civil_status='married',highest_educational_attainment='College Graduate',occupation='Self-Employed',estimated_monthly_income='₱20,000 – ₱29,999',province='Antique',municipality='San Jose de Buenavista',barangay='Atabay',address_line='Purok 7',complete_address='Purok 7, Atabay, San Jose de Buenavista, Antique' WHERE account_id=?");
    $stmt->bind_param('i',$applicantId); $stmt->execute(); $stmt->close();
    $login=flow_http($applicantBase.'/php_files/auth-login.php','POST',['login_identifier'=>$phone,'password'=>$password]); $cookie=$login['cookie'];
    flow_assert($login['status']===302 && str_starts_with($cookie,'AICSAPPLICANTSESSID='),'Phone-only Applicant login failed.');

    $fields=flow_fields($beneficiaryPhone);
    $draft=flow_http($applicantBase.'/php_files/draft-save.php','POST',['version'=>0,'current_step'=>3,'data'=>$fields],$cookie,[],true);
    $draftPayload=json_decode($draft['body'],true); $draftId=(string)($draftPayload['draft']['id']??'');
    flow_assert($draft['status']===200 && $draftId!=='','Draft persistence failed: '.$draft['body']);
    foreach (['valid_id','med_cert','billing_statement'] as $type) {
        $upload=flow_http($applicantBase.'/php_files/draft-upload.php','POST',['draft_id'=>$draftId,'document_type'=>$type],$cookie,['document'=>$fixture]);
        flow_assert($upload['status']===200,"Draft upload failed for {$type}: {$upload['body']}");
    }
    $documents=aics_draft_documents($connection,$applicantId,$draftId);
    flow_assert(array_keys($documents)===['valid_id','med_cert','billing_statement'] || count($documents)===3,'Canonical draft aliases were not stored.');

    $validDraftId=(string)$documents['valid_id']['id'];
    $preview=flow_http($applicantBase.'/php_files/draft-document-view.php?document_id='.urlencode($validDraftId),'GET',[],$cookie);
    flow_assert($preview['status']===200 && str_contains($preview['content_type'],'application/pdf'),'Authenticated draft preview failed.');
    $unauthPreview=flow_http($applicantBase.'/php_files/draft-document-view.php?document_id='.urlencode($validDraftId));
    flow_assert($unauthPreview['status']===401,'Unauthenticated draft preview was accepted.');

    // A different authenticated Applicant cannot preview this draft document.
    do $secondaryPhone='09'.random_int(100000000,999999999); while($secondaryPhone===$phone || aics_mobile_number_in_use($connection,$secondaryPhone));
    flow_assert(flow_http($applicantBase.'/php_files/auth-signup.php','POST',['first_name'=>'Other','last_name'=>'Applicant','contact_number'=>$secondaryPhone,'password'=>$password,'confirm_password'=>$password])['status']===302,'Secondary Applicant signup failed.');
    $stmt=$connection->prepare('SELECT account_id FROM aics_applicant_profiles WHERE contact_number=?'); $stmt->bind_param('s',$secondaryPhone); $stmt->execute(); $secondaryApplicantId=(int)($stmt->get_result()?->fetch_assoc()['account_id']??0); $stmt->close();
    $stmt=$connection->prepare("UPDATE aics_applicant_profiles SET date_of_birth='1988-01-01',civil_status='single',highest_educational_attainment='College Graduate',occupation='Employed – Private',estimated_monthly_income='₱20,000 – ₱29,999',province='Antique',municipality='San Jose de Buenavista',barangay='Atabay',address_line='Purok 8',complete_address='Purok 8, Atabay, San Jose de Buenavista, Antique' WHERE account_id=?"); $stmt->bind_param('i',$secondaryApplicantId); $stmt->execute(); $stmt->close();
    $secondaryLogin=flow_http($applicantBase.'/php_files/auth-login.php','POST',['login_identifier'=>$secondaryPhone,'password'=>$password]);
    $crossPreview=flow_http($applicantBase.'/php_files/draft-document-view.php?document_id='.urlencode($validDraftId),'GET',[],$secondaryLogin['cookie']);
    flow_assert($crossPreview['status']===404,'Another Applicant accessed the draft document.');

    // An isolated second request provides the ownership-denial target; never
    // rely on a retained request ID or application reference.
    $secondarySubmit=flow_http($applicantBase.'/php_files/save-request.php','POST',flow_fields($beneficiaryPhone),$secondaryLogin['cookie'],['doc_valid_id'=>$fixture,'doc_med_cert'=>$fixture,'doc_billing_statement'=>$fixture]);
    $secondaryPayload=json_decode($secondarySubmit['body'],true); $secondaryReferenceNo=(string)($secondaryPayload['reference_no']??'');
    flow_assert($secondarySubmit['status']===200 && $secondaryReferenceNo!=='','Secondary isolated submission failed: '.$secondarySubmit['body']);
    $stmt=$connection->prepare('SELECT r.id FROM aics_requests r JOIN aics_account_requests ar ON ar.request_id=r.id WHERE ar.account_id=? AND r.reference_no=?'); $stmt->bind_param('is',$secondaryApplicantId,$secondaryReferenceNo); $stmt->execute(); $secondaryRequestId=(int)($stmt->get_result()?->fetch_assoc()['id']??0); $stmt->close();
    flow_assert($secondaryRequestId>0,'Secondary isolated request was not created.');

    // Removing one slot cannot remove another; restore the selected slot afterward.
    $remove=flow_http($applicantBase.'/php_files/draft-document-remove.php','POST',['draft_id'=>$draftId,'document_type'=>'med_cert'],$cookie,[],true);
    flow_assert($remove['status']===200,'Selected draft document removal failed.');
    $afterRemove=aics_draft_documents($connection,$applicantId,$draftId);
    flow_assert(!isset($afterRemove['med_cert']) && isset($afterRemove['valid_id'],$afterRemove['billing_statement']),'Draft removal affected the wrong slot.');
    $restore=flow_http($applicantBase.'/php_files/draft-upload.php','POST',['draft_id'=>$draftId,'document_type'=>'medical_certificate'],$cookie,['document'=>$fixture]);
    flow_assert($restore['status']===200,'Canonical alias replacement upload failed.');
    $afterRestore=aics_draft_documents($connection,$applicantId,$draftId);
    flow_assert(isset($afterRestore['med_cert']) && count($afterRestore)===3,'Legacy alias did not normalize to med_cert.');

    // A failed submission leaves the draft rows and all physical source files intact.
    $invalid=$fields; unset($invalid['beneficiary_first_name']); $invalid['draft_id']=$draftId;
    $failed=flow_http($applicantBase.'/php_files/save-request.php','POST',$invalid,$cookie);
    flow_assert($failed['status']===400,'Expected validation failure did not occur.');
    $remaining=aics_draft_documents($connection,$applicantId,$draftId);
    flow_assert(count($remaining)===3,'Failed submission consumed draft metadata.');
    foreach($remaining as $document) {
        $retainedPreview=flow_http($applicantBase.'/php_files/draft-document-view.php?document_id='.urlencode((string)$document['id']),'GET',[],$cookie);
        flow_assert($retainedPreview['status']===200,'Failed submission lost a draft file.');
    }

    $fields['draft_id']=$draftId;
    $submit=flow_http($applicantBase.'/php_files/save-request.php','POST',$fields,$cookie); $submitted=json_decode($submit['body'],true); $referenceNo=(string)($submitted['reference_no']??'');
    flow_assert($submit['status']===200 && $referenceNo!=='','Final draft submission failed: '.$submit['body']);
    $stmt=$connection->prepare('SELECT r.* FROM aics_requests r JOIN aics_account_requests ar ON ar.request_id=r.id WHERE ar.account_id=? AND r.reference_no=?'); $stmt->bind_param('is',$applicantId,$referenceNo); $stmt->execute(); $request=$stmt->get_result()?->fetch_assoc(); $stmt->close();
    $requestId=(int)($request['id']??0); flow_assert($requestId>0,'Authenticated Applicant does not own submitted request.');
    flow_assert((string)$request['employment']==='Pensioner' && (string)$request['estimated_income']==='Below ₱10,000','Beneficiary financial snapshot missing.');
    flow_assert((string)$request['applicant_occupation']==='Self-Employed' && (string)$request['applicant_estimated_monthly_income']==='₱20,000 – ₱29,999','Applicant financial snapshot missing.');
    flow_assert((string)$request['applicant_contact_number']===$phone && ($request['applicant_email']===null || $request['applicant_email']===''),'Phone-only Applicant snapshot is invalid.');
    $family=json_decode((string)$request['family_composition_json'],true); flow_assert(is_array($family)&&count($family)===2&&$family[0]['name']==='Marco Reyes'&&$family[1]['relationship']==='Child','Family Composition snapshot missing.');
    flow_assert(json_decode((string)$request['client_categories_json'],true)===['senior_citizen'],'Beneficiary classification changed.');
    flow_assert(aics_get_active_draft($connection,$applicantId)===null,'Successful submission did not consume draft.');

    $stmt=$connection->prepare('SELECT id,document_type,stored_name FROM aics_request_documents WHERE request_id=? ORDER BY document_type'); $stmt->bind_param('i',$requestId); $stmt->execute(); $submittedDocs=$stmt->get_result()?->fetch_all(MYSQLI_ASSOC)?:[]; $stmt->close();
    $types=array_column($submittedDocs,'document_type'); sort($types); flow_assert($types===['billing_statement','med_cert','valid_id'],'Draft documents were not claimed with canonical aliases.');
    foreach($submittedDocs as $document) flow_assert(is_file(__DIR__.'/../Applicants/uploads/'.$referenceNo.'/'.$document['stored_name']),'Submitted physical document is missing.');
    $ai=(int)($connection->query('SELECT COUNT(*) AS total FROM document_ai_reviews air JOIN aics_request_documents d ON d.id=air.request_document_id WHERE d.request_id='.(int)$requestId)?->fetch_assoc()['total']??0);
    $leases=(int)($connection->query('SELECT COUNT(*) AS total FROM document_ai_request_worker_leases WHERE request_id='.(int)$requestId)?->fetch_assoc()['total']??0);
    flow_assert($ai===3 && $leases<=1,'Committed draft documents were not queued exactly once or duplicate request workers were created.');

    $roleId=(int)($connection->query("SELECT id FROM aics_roles WHERE role_code='pswdo'")?->fetch_assoc()['id']??0); $statusId=(int)($connection->query("SELECT id FROM aics_account_statuses WHERE status_code='active'")?->fetch_assoc()['id']??0); $hash=password_hash($password,PASSWORD_DEFAULT);
    $stmt=$connection->prepare('INSERT INTO aics_accounts(role_id,account_status_id,email,password_hash) VALUES(?,?,?,?)'); $stmt->bind_param('iiss',$roleId,$statusId,$staffEmail,$hash); $stmt->execute(); $staffId=(int)$connection->insert_id; $stmt->close();
    pswdo_ensure_schema($connection); $queue=pswdo_fetch_request_queue($connection,['search'=>$referenceNo]); flow_assert(count($queue)===1,'PSWDO request details query did not return fixture.'); $detail=$queue[0];
    $storedPriorityLevel = (string) ($detail['priority']['priority_level'] ?? '');
    flow_assert(aics_smart_priority_analysis_enabled()
        ? in_array($storedPriorityLevel, ['HIGH', 'URGENT'], true)
        : $storedPriorityLevel === 'HIGH', 'Senior Medical priority must be HIGH or URGENT according to the active mode.');
    flow_assert(($request['urgency_response']??null)===null,'Applicant self-reported urgency must not be persisted as priority evidence.');
    flow_assert(count($detail['family_composition']??[])===2 && ($detail['beneficiary_occupation']??'')==='Pensioner' && ($detail['applicant_occupation']??'')==='Self-Employed','PSWDO socioeconomic detail data is incomplete.');
    flow_assert(($detail['required_total']??0)===3 && ($detail['uploaded_total']??0)===3,'PSWDO did not resolve all required Medical documents.');

    $staffLogin=flow_http($pswdoBase.'/php_files/auth-login.php','POST',['email'=>$staffEmail,'password'=>$password]); $staffCookie=$staffLogin['cookie'];
    $anonymousAiStatus=flow_http($pswdoBase.'/php_files/ai-request-status.php?request_id='.$requestId,'GET');
    flow_assert($anonymousAiStatus['status']!==200,'Unauthenticated AI request progress was exposed.');
    $aiStatusResponse=flow_http($pswdoBase.'/php_files/ai-request-status.php?request_id='.$requestId,'GET',[],$staffCookie);
    $aiStatusPayload=json_decode($aiStatusResponse['body'],true);
    flow_assert($aiStatusResponse['status']===200 && is_array($aiStatusPayload) && ($aiStatusPayload['ok']??false)===true,'Authenticated AI request progress endpoint failed.');
    flow_assert(($aiStatusPayload['request_id']??0)===$requestId && ($aiStatusPayload['total']??0)===3,'AI request progress returned incorrect request totals.');
    flow_assert((($aiStatusPayload['queued']??0)+($aiStatusPayload['active']??0)+($aiStatusPayload['terminal']??0))===3,'AI request progress states do not cover every verifiable document.');
    foreach(($aiStatusPayload['documents']??[]) as $aiStatusDocument){
        flow_assert(!array_key_exists('file_path',$aiStatusDocument) && !array_key_exists('extracted_fields',$aiStatusDocument),'AI request progress leaked private document data.');
    }
    $detailsPage=flow_http($pswdoBase.'/php_files/request-management.php?reopen_request='.$requestId,'GET',[],$staffCookie);
    flow_assert($detailsPage['status']===200 && str_contains($detailsPage['body'],'Marco Reyes') && str_contains($detailsPage['body'],'Pensioner') && str_contains($detailsPage['body'],'Self-Employed'),'PSWDO Full Application Details did not render submitted socioeconomic data.');
    flow_assert(str_contains($detailsPage['body'],'data-ai-request-row') && str_contains($detailsPage['body'],'data-request-id="'.$requestId.'"'),'PSWDO request row is not a live verification target.');
    flow_assert(str_contains($detailsPage['body'],'class="request-ai-progress"') && str_contains($detailsPage['body'],'role="progressbar"'),'PSWDO request row does not render verification progress.');
    flow_assert(str_contains($detailsPage['body'],'aria-live="polite"'),'PSWDO verification progress is not announced accessibly.');
    $firstDocId=(int)$submittedDocs[0]['id']; $staffView=flow_http($pswdoBase.'/php_files/document-view.php?request_id='.$requestId.'&document_id='.$firstDocId,'GET',[],$staffCookie);
    flow_assert($staffView['status']===200 && str_contains($staffView['content_type'],'application/pdf'),'PSWDO secure document view failed.');
    flow_assert(flow_http($pswdoBase.'/php_files/document-view.php?request_id='.$secondaryRequestId.'&document_id='.$firstDocId,'GET',[],$staffCookie)['status']===404,'PSWDO document/request ownership mismatch was accepted.');
    $applicantView=flow_http($applicantBase.'/php_files/document-view.php?request_id='.$requestId.'&document_id='.$firstDocId,'GET',[],$cookie);
    flow_assert($applicantView['status']===200,'Applicant submitted document view failed.');
    $otherDoc=(int)($connection->query('SELECT id FROM aics_request_documents WHERE request_id='.(int)$secondaryRequestId.' ORDER BY id LIMIT 1')?->fetch_assoc()['id']??0);
    flow_assert($otherDoc>0 && flow_http($applicantBase.'/php_files/document-view.php?request_id='.$secondaryRequestId.'&document_id='.$otherDoc,'GET',[],$cookie)['status']===404,'Applicant accessed another account document.');

    echo "Application data-flow regression checks A-AG passed.\n";
} finally {
    // Collect every request owned by temporary accounts immediately before
    // cleanup, including any fixture created after an earlier assertion.
    $temporaryRequestIds = [];
    foreach ([$applicantId, $secondaryApplicantId] as $temporaryAccountId) {
        if ($temporaryAccountId <= 0) continue;
        $owned = $connection->prepare('SELECT r.id, r.reference_no FROM aics_requests r INNER JOIN aics_account_requests ar ON ar.request_id = r.id WHERE ar.account_id = ?');
        $owned->bind_param('i', $temporaryAccountId); $owned->execute(); $rows = $owned->get_result();
        while ($row = $rows?->fetch_assoc()) { $temporaryRequestIds[] = (int) $row['id']; if ((string) $row['reference_no'] !== '') $obsoleteSubmissionFiles[] = (string) $row['reference_no']; }
        $owned->close();
    }
    foreach (array_unique($temporaryRequestIds) as $temporaryRequestId) { $stmt=$connection->prepare('DELETE FROM aics_requests WHERE id=?');$stmt->bind_param('i',$temporaryRequestId);$stmt->execute();$stmt->close(); }
    if($applicantId>0){$stmt=$connection->prepare('DELETE FROM aics_accounts WHERE id=?');$stmt->bind_param('i',$applicantId);$stmt->execute();$stmt->close();}
    if($secondaryApplicantId>0){$stmt=$connection->prepare('DELETE FROM aics_accounts WHERE id=?');$stmt->bind_param('i',$secondaryApplicantId);$stmt->execute();$stmt->close();}
    if($staffId>0){$stmt=$connection->prepare('DELETE FROM aics_accounts WHERE id=?');$stmt->bind_param('i',$staffId);$stmt->execute();$stmt->close();}
    foreach([$phone,$secondaryPhone,$staffEmail] as $identifier){if($identifier==='')continue;$stmt=$connection->prepare('DELETE FROM aics_admin_login_attempts WHERE email=?');$stmt->bind_param('s',$identifier);$stmt->execute();$stmt->close();}
    if($draftId!=='') aics_delete_draft_files($draftId);
    foreach (array_unique($obsoleteSubmissionFiles) as $fixtureReferenceNo) aics_http_regression_remove_upload_directory($fixtureReferenceNo);
    if (is_file($fixture)) @unlink($fixture);
    aics_http_regression_remove_upload_directory($referenceNo);
    $connection->close();
}
