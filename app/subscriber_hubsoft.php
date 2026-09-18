<?php

declare(strict_types=1);

require_once __DIR__ . '/hubsoft_api.php';

function fs_subscriber_hubsoft_value(array $data, array $keys): string
{
    foreach($keys as $key){if(array_key_exists($key,$data)&&is_scalar($data[$key])){$value=trim((string)$data[$key]);if($value!=='')return $value;}}return '';
}

function fs_subscriber_hubsoft_id(array $data, array $keys): string
{
    $value=fs_subscriber_hubsoft_value($data,$keys);
    return preg_match('/^[A-Za-z0-9_.:-]{1,128}$/',$value)?$value:'';
}

function fs_subscriber_hubsoft_status_active(array $data): bool
{
    foreach(['ativo','active','habilitado'] as $key)if(array_key_exists($key,$data))return fs_subscriber_bool($data[$key]);
    $status=strtolower(trim(fs_subscriber_hubsoft_value($data,['status','situacao','status_servico','descricao_status'])));
    return in_array($status,['serviço habilitado','servico habilitado','habilitado','ativo','active'],true);
}

function fs_subscriber_hubsoft_phone(array $customer): string
{
    $raw=fs_subscriber_hubsoft_value($customer,['telefone_primario','telefone','celular','telefone_secundario']);$digits=fs_subscriber_digits($raw);
    if(str_starts_with($digits,'55')&&strlen($digits)>11)$digits=substr($digits,2);if(strlen($digits)>11)$digits=substr($digits,-11);
    return strlen($digits)>=10?$digits:'';
}

function fs_subscriber_hubsoft_email(array $customer): string
{
    $email=strtolower(fs_subscriber_hubsoft_value($customer,['email','email_principal','email_financeiro','email_boleto','email_cadastro']));return filter_var($email,FILTER_VALIDATE_EMAIL)?$email:'';
}

function fs_subscriber_hubsoft_candidates(array $service): array
{
    $out=[];
    $sets=[
        'service'=>['id_servico','servico_id','id','codigo_servico','uuid_servico'],
        'plan'=>['id_plano','plano_id','id_plano_servico','codigo_plano'],
        'package'=>['id_pacote','pacote_id','id_combo','combo_id'],
    ];
    foreach($sets as $kind=>$keys){$id=fs_subscriber_hubsoft_id($service,$keys);if($id!=='')$out[]=['kind'=>$kind,'id'=>$id];}
    foreach(['plano'=>'plan','pacote'=>'package','servico'=>'service'] as $nested=>$kind){if(!empty($service[$nested])&&is_array($service[$nested])){$id=fs_subscriber_hubsoft_id($service[$nested],['id','codigo','uuid']);if($id!=='')$out[]=['kind'=>$kind,'id'=>$id];}}
    $unique=[];foreach($out as $candidate)$unique[$candidate['kind'].'\0'.$candidate['id']]=$candidate;return array_values($unique);
}

function fs_subscriber_hubsoft_service_label(array $service): string
{
    $parts=[];
    foreach(['nome','descricao','nome_servico','descricao_servico','nome_plano','descricao_plano','nome_pacote','descricao_pacote','tipo_servico','categoria'] as $key){
        if(isset($service[$key])&&is_scalar($service[$key])){$value=trim((string)$service[$key]);if($value!=='')$parts[]=$value;}
    }
    foreach(['plano','servico','produto','pacote'] as $nested){
        if(!isset($service[$nested]))continue;
        if(is_scalar($service[$nested])){$value=trim((string)$service[$nested]);if($value!=='')$parts[]=$value;continue;}
        if(!is_array($service[$nested]))continue;
        foreach(['nome','descricao','titulo','tipo','categoria'] as $key){
            if(isset($service[$nested][$key])&&is_scalar($service[$nested][$key])){$value=trim((string)$service[$nested][$key]);if($value!=='')$parts[]=$value;}
        }
    }
    $parts=array_values(array_unique(array_filter(array_map(static function(string $value):string{
        $value=preg_replace('/[\x00-\x1F\x7F]+/u',' ',$value)??'';
        $value=preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu','[contato removido]',$value)??'';
        $value=preg_replace('/(?<!\d)\d{10,14}(?!\d)/u','[identificador removido]',$value)??'';
        return trim(preg_replace('/\s+/u',' ',$value)??'');
    },$parts))));
    return substr(implode(' · ',$parts),0,180);
}

function fs_subscriber_hubsoft_service_internet_evidence(array $service): ?string
{
    $parts=[];
    foreach(['tipo_servico','categoria','descricao','nome','nome_servico','descricao_servico','nome_plano','descricao_plano','nome_pacote','descricao_pacote','produto','servico','plano','tecnologia'] as $key){
        if(isset($service[$key])&&is_scalar($service[$key]))$parts[]=(string)$service[$key];
    }
    foreach(['plano','servico','produto','pacote'] as $nested){
        if(!empty($service[$nested])&&is_array($service[$nested]))foreach(['nome','descricao','tipo','categoria','tecnologia'] as $key)if(isset($service[$nested][$key])&&is_scalar($service[$nested][$key]))$parts[]=(string)$service[$nested][$key];
    }
    $text=function_exists('mb_strtolower')?mb_strtolower(implode(' ',$parts),'UTF-8'):strtolower(implode(' ',$parts));
    if($text!==''&&preg_match('/sem\s+internet|somente\s+(voz|tv)|telefonia\s+fixa|antiv[ií]rus|suporte\s+t[eé]cnico/iu',$text))return null;
    if($text!==''&&preg_match('/internet|banda\s*larga|fibra|ftth|wireless|wi[ -]?fi|link\s+de\s+dados|acesso\s+dedicado|ip\s+dedicado/iu',$text))return 'service_text';
    foreach(['login_radius','usuario_radius','login_pppoe','pppoe_login','ipv4','ipv6','phy_addr','mac_autenticacao','interface_conexao'] as $key){
        if(isset($service[$key])&&is_scalar($service[$key])&&trim((string)$service[$key])!=='')return 'network_auth';
    }
    foreach(['autenticacao','dados_autenticacao','interface','interface_conexao'] as $nested){
        if(!empty($service[$nested])&&is_array($service[$nested]))return 'network_auth';
    }
    return null;
}

function fs_subscriber_hubsoft_service_is_internet(array $service): bool
{
    foreach(['internet','is_internet','servico_internet','possui_internet'] as $key)if(array_key_exists($key,$service))return fs_subscriber_bool($service[$key]);
    return fs_subscriber_hubsoft_service_internet_evidence($service)!==null;
}

/**
 * Executa a autenticação da Central do Assinante sem persistir nem registrar a
 * senha informada. O endpoint é diferente do OAuth técnico usado pelo FireSpot:
 * o Bearer identifica a integração e `usuario`/`senha` identificam o cliente.
 *
 * @return array{status:int,json:?array}
 */
function fs_subscriber_hubsoft_auth_http(string $username, string $password): array
{
    $configuration=fs_hubsoft_configuration();
    $payload=json_encode(['usuario'=>$username,'senha'=>$password],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($payload))throw new RuntimeException('Não foi possível preparar a autenticação do assinante.');
    // O Bearer acaba de ser obtido para esta requisição. Repetir um 401 aqui
    // reenviaria a mesma senha e poderia consumir duas tentativas do assinante.
    $token=getAccessToken(false,$configuration);
    $result=fs_hubsoft_http(fs_hubsoft_base_url($configuration).'/api/v1/integracao/cliente/autenticacao/',[
        CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>['Accept: application/json','Authorization: Bearer '.$token,'Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>$payload,
    ]);
    $json=json_decode($result['body'],true);
    return ['status'=>(int)$result['status'],'json'=>is_array($json)?$json:null];
}

function fs_subscriber_hubsoft_auth_entity(array $response): array
{
    if(array_is_list($response)){
        $value=$response[0]??[];
        return is_array($value)?$value:[];
    }
    foreach(['cliente','customer','dados','data'] as $key){
        if(!isset($response[$key])||!is_array($response[$key]))continue;
        $value=$response[$key];
        if(array_is_list($value))$value=$value[0]??[];
        if(is_array($value)&&$value)return $value;
    }
    foreach(['clientes','customers'] as $key){
        if(!isset($response[$key])||!is_array($response[$key]))continue;
        $value=array_is_list($response[$key])?($response[$key][0]??[]):$response[$key];
        if(is_array($value)&&$value)return $value;
    }
    foreach(['id_cliente','cliente_id','codigo_cliente','uuid_cliente'] as $key)if(array_key_exists($key,$response))return $response;
    return [];
}

/**
 * Normaliza somente a decisão de autenticação. A resposta bruta do HubSoft não
 * sai deste adaptador e a senha nunca integra o retorno, cache ou auditoria.
 *
 * O callable opcional existe apenas para testes de contrato e recebe
 * `(string $username, string $password)`.
 *
 * @return array{authenticated:bool,result_code:string,customer_id:?string}
 */
function fs_subscriber_hubsoft_authenticate(string $username, string $password, ?callable $requester=null): array
{
    $username=fs_subscriber_digits($username);
    if(!fs_subscriber_document_valid($username))throw new InvalidArgumentException('CPF ou CNPJ inválido.');
    $passwordLength=strlen($password);
    if($passwordLength<1||$passwordLength>255)throw new InvalidArgumentException('Informe a senha da Central do Assinante.');

    $result=$requester!==null?$requester($username,$password):fs_subscriber_hubsoft_auth_http($username,$password);
    if(!is_array($result)||!isset($result['status'])||!is_numeric($result['status']))throw new RuntimeException('O HubSoft retornou uma autenticação inválida.');
    $status=(int)$result['status'];$response=is_array($result['json']??null)?$result['json']:[];
    if(in_array($status,[400,401,422],true))return ['authenticated'=>false,'result_code'=>'INVALID_CREDENTIALS','customer_id'=>null];
    if($status===403)return ['authenticated'=>false,'result_code'=>'REMOTE_FORBIDDEN','customer_id'=>null];
    if($status===423)return ['authenticated'=>false,'result_code'=>'REMOTE_LOCKED','customer_id'=>null];
    if($status===429)return ['authenticated'=>false,'result_code'=>'REMOTE_RATE_LIMITED','customer_id'=>null];
    if($status<200||$status>=300)throw new RuntimeException('O HubSoft não pôde validar a senha neste momento.');
    if(!$response)throw new RuntimeException('O HubSoft retornou uma autenticação vazia.');

    $explicitFailure=false;$explicitSuccess=false;
    foreach(['authenticated','autenticado','success','sucesso'] as $key){
        if(!array_key_exists($key,$response))continue;
        $value=$response[$key];
        if($value===false||$value===0||$value==='0')$explicitFailure=true;
        elseif(fs_subscriber_bool($value))$explicitSuccess=true;
    }
    $statusText=strtolower(trim((string)($response['status']??'')));
    if(array_key_exists('status',$response)&&is_bool($response['status'])){$explicitSuccess=$response['status'];$explicitFailure=!$response['status'];}
    if(in_array($statusText,['error','erro','fail','failed','failure','falha','invalid','inválido','invalido','unauthorized','não autorizado','nao autorizado'],true))$explicitFailure=true;
    if(in_array($statusText,['success','sucesso','ok','authenticated','autenticado'],true))$explicitSuccess=true;
    if($explicitFailure)return ['authenticated'=>false,'result_code'=>'INVALID_CREDENTIALS','customer_id'=>null];

    $customer=fs_subscriber_hubsoft_auth_entity($response);
    $customerId=$customer?fs_subscriber_hubsoft_id($customer,['id_cliente','cliente_id','id','codigo_cliente','uuid_cliente','codigo']):'';
    $returnedDocument=$customer?fs_subscriber_digits(fs_subscriber_hubsoft_value($customer,['cpf_cnpj','cpf','cnpj','usuario'])):'';
    if($returnedDocument!==''&&!hash_equals($username,$returnedDocument))return ['authenticated'=>false,'result_code'=>'IDENTITY_MISMATCH','customer_id'=>null];
    if(!$explicitSuccess&&!$customer)throw new RuntimeException('O HubSoft retornou uma autenticação fora do formato esperado.');
    return ['authenticated'=>true,'result_code'=>'AUTHENTICATED','customer_id'=>$customerId!==''?$customerId:null];
}

function fs_subscriber_hubsoft_lookup(string $document, ?callable $fetcher=null): array
{
    $digits=fs_subscriber_digits($document);if(!fs_subscriber_document_valid($digits))throw new InvalidArgumentException('Documento inválido.');
    if($fetcher===null){$fetcher=static function(string $doc):array{$endpoint='/api/v1/integracao/cliente?busca=cpf_cnpj&termo_busca='.rawurlencode($doc);$rows=hubsoftRequest($endpoint,'GET',null);return is_array($rows)?$rows:[];};}
    $rows=$fetcher($digits);if(isset($rows['clientes'])&&is_array($rows['clientes']))$rows=$rows['clientes'];if(!is_array($rows)||!$rows)return ['found'=>false];
    $customer=array_is_list($rows)?($rows[0]??null):$rows;if(!is_array($customer))return ['found'=>false];
    $customerId=fs_subscriber_hubsoft_id($customer,['id_cliente','cliente_id','id','codigo_cliente','uuid_cliente','codigo']);
    $services=[];foreach(['servicos','services','contratos'] as $key){if(!empty($customer[$key])&&is_array($customer[$key])){$services=$customer[$key];break;}}
    $activeServices=[];$internetServices=[];$candidates=[];$primaryService=null;$primaryPlan=null;
    foreach($services as $service){
        if(!is_array($service)||!fs_subscriber_hubsoft_status_active($service))continue;
        $activeServices[]=$service;$evidence=fs_subscriber_hubsoft_service_internet_evidence($service);$internetDetected=$evidence!==null;if($internetDetected)$internetServices[]=$service;$label=fs_subscriber_hubsoft_service_label($service);
        foreach(fs_subscriber_hubsoft_candidates($service) as $candidate){
            $candidate['label']=$label;$candidate['internet_detected']=$internetDetected;$candidate['evidence']=$evidence;
            $key=$candidate['kind'].'\0'.$candidate['id'];
            if(!isset($candidates[$key])||(!$candidates[$key]['internet_detected']&&$internetDetected)||($candidates[$key]['label']===''&&$label!==''))$candidates[$key]=$candidate;
        }
        if($primaryService===null)$primaryService=fs_subscriber_hubsoft_id($service,['id_servico','servico_id','id','codigo_servico']);if($primaryPlan===null)$primaryPlan=fs_subscriber_hubsoft_id($service,['id_plano','plano_id','id_plano_servico','codigo_plano']);
    }
    $customerHasStatus=false;foreach(['ativo','active','habilitado','status','situacao','descricao_status'] as $key)if(array_key_exists($key,$customer)){$customerHasStatus=true;break;}$customerActive=$customerHasStatus?fs_subscriber_hubsoft_status_active($customer):!empty($activeServices);
    return [
        'found'=>true,'customer_id'=>$customerId,'customer_active'=>$customerActive,'internet_active'=>!empty($internetServices),
        'eligible_basic'=>!empty($internetServices),'mapping_candidates'=>array_values($candidates),
        'active_service_count'=>count($activeServices),'internet_service_count'=>count($internetServices),
        'primary_service_id'=>$primaryService?:null,'primary_plan_id'=>$primaryPlan?:null,
        'phone'=>fs_subscriber_hubsoft_phone($customer),'email'=>fs_subscriber_hubsoft_email($customer),
        'display_name'=>substr(fs_subscriber_hubsoft_value($customer,['nome','nome_razaosocial','nome_razao_social','nome_cliente','nome_fantasia','razao_social']),0,150),
    ];
}
