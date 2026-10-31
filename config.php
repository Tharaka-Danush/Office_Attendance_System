<?php
return [
 'dsn'=>'mysql:host=127.0.0.1;port=3306;dbname=office_attendance;charset=utf8mb4',
 'db_user'=>'root','db_password'=>'',
 'company'=>'Office Attendance','timezone'=>'Asia/Colombo',
 // Add your office subnet here before accessing the server from office PCs.
 'allowed_networks'=>['127.0.0.1/32'],
];
