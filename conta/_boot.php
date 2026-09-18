<?php
declare(strict_types=1);
require_once __DIR__.'/../app/session_boot.php';
require_once __DIR__.'/../app/csrf.php';
require_once __DIR__.'/../app/db.php';
require_once __DIR__.'/../app/subscriber_accounts.php';
require_once __DIR__.'/../app/subscriber_radius.php';
require_once __DIR__.'/../app/public_url.php';
function conta_h($value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function conta_db():PDO{$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);return $pdo;}
function conta_flash(string $type,string $message):void{$_SESSION['subscriber_flash']=['type'=>$type,'message'=>$message];}
function conta_take_flash():?array{$flash=$_SESSION['subscriber_flash']??null;unset($_SESSION['subscriber_flash']);return is_array($flash)?$flash:null;}
function conta_redirect(string $path='/conta/'):void{header('Location: '.$path,true,303);exit;}
function conta_error(Throwable $e):string{if($e instanceof InvalidArgumentException||$e instanceof RuntimeException){$message=trim($e->getMessage());return $message!==''?substr($message,0,260):'Não foi possível continuar.';}error_log('[minha conta] '.get_class($e).': '.$e->getMessage());return 'Não foi possível continuar agora. Tente novamente.';}
function conta_layout_start(string $title='Minha Conta FIRENETWORK'):void{?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#071225"><title><?=conta_h($title)?></title><link rel="icon" href="/favicon.ico"><link rel="stylesheet" href="/conta/assets/account.css?v=<?=is_file(__DIR__.'/assets/account.css')?filemtime(__DIR__.'/assets/account.css'):1?>"></head><body><header class="account-top"><a href="/conta/" class="account-brand"><span>🔥</span><div><strong>FIRENETWORK</strong><small>Minha Conta · FireSpot</small></div></a></header><main class="account-shell"><?php }
function conta_layout_end():void{?></main><footer>FIRENETWORK · Benefício Wi-Fi FireSpot</footer></body></html><?php }

