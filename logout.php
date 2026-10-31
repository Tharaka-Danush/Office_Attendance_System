<?php
declare(strict_types=1);
require __DIR__.'/app/core.php';
header('Cache-Control: no-store');
require_office_network($_SERVER);local_session();$_SESSION=[];
$params=session_get_cookie_params();setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>$params['path'],'secure'=>$params['secure'],'httponly'=>true,'samesite'=>'Strict']);session_destroy();
header('Location: login.php',true,303);exit;
