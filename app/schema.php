<?php
declare(strict_types=1);
function table_exists(string $table): bool {return (bool)query('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[$table])->fetchColumn();}
function column_exists(string $table,string $column): bool {return (bool)query('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',[$table,$column])->fetchColumn();}
function installation_state(): string {
 try {
  $employees=table_exists('employees')?(int)query('SELECT COUNT(*) FROM employees')->fetchColumn():0;
  $accounts=table_exists('accounts')?(int)query('SELECT COUNT(*) FROM accounts')->fetchColumn():0;
  $old=table_exists('local_accounts')?(int)query('SELECT COUNT(*) FROM local_accounts')->fetchColumn():0;
  if($accounts||$old) {
   if(!table_exists('salary_months')||!column_exists('salary_months','travelling_allowance')||!column_exists('salary_months','attendance_bonus')||!column_exists('salary_months','epf_rate')||!column_exists('salary_months','night_allowance')||!column_exists('salary_months','meal_allowance')||!column_exists('salary_months','ll_day_payment')||!column_exists('salary_months','stamp_duty')||!column_exists('attendance','auto_closed')||!table_exists('weekly_schedules')||!column_exists('leave_requests','leave_part')||!table_exists('holidays')||!table_exists('leave_requests')||!$accounts||column_exists('employees','domain_user')||column_exists('devices','cert_hash')||!column_exists('devices','ip_address')||!table_exists('payroll_locks')||!table_exists('leave_allocations')||!table_exists('salary_snapshots')||!table_exists('salary_reconciliations'))return 'upgrade';
   return 'ready';
  }
  return $employees?'blocked':'setup';
 }catch(PDOException $x){if((int)($x->errorInfo[1]??0)===1049)return 'setup';throw $x;}
}
function upgrade_schema(): void {
 if(table_exists('employees')) {
  query('CREATE TABLE IF NOT EXISTS payroll_locks (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,employee_id BIGINT UNSIGNED NOT NULL,period_start DATE NOT NULL,period_end DATE NOT NULL,locked_by BIGINT UNSIGNED NOT NULL,locked_at DATETIME NOT NULL,note VARCHAR(500) NOT NULL,INDEX emp_period(employee_id,period_start,period_end),FOREIGN KEY(employee_id) REFERENCES employees(id),FOREIGN KEY(locked_by) REFERENCES employees(id)) ENGINE=InnoDB');
  query('CREATE TABLE IF NOT EXISTS salary_snapshots (employee_id BIGINT UNSIGNED NOT NULL,salary_month DATE NOT NULL,period_start DATE NOT NULL,period_end DATE NOT NULL,net_cents BIGINT NOT NULL,gross_cents BIGINT NOT NULL,deductions_cents BIGINT NOT NULL,details_json LONGTEXT NOT NULL,finalized_at DATETIME NOT NULL,finalized_by BIGINT UNSIGNED NOT NULL,PRIMARY KEY(employee_id,salary_month),FOREIGN KEY(employee_id) REFERENCES employees(id),FOREIGN KEY(finalized_by) REFERENCES employees(id)) ENGINE=InnoDB');
  query('CREATE TABLE IF NOT EXISTS salary_reconciliations (employee_id BIGINT UNSIGNED NOT NULL,salary_month DATE NOT NULL,actual_gross DECIMAL(12,2) NOT NULL,actual_net DECIMAL(12,2) NOT NULL,note VARCHAR(500) NOT NULL,updated_at DATETIME NOT NULL,PRIMARY KEY(employee_id,salary_month),FOREIGN KEY(employee_id) REFERENCES employees(id)) ENGINE=InnoDB');
  query('CREATE TABLE IF NOT EXISTS leave_allocations (employee_id BIGINT UNSIGNED NOT NULL,leave_year SMALLINT UNSIGNED NOT NULL,leave_type VARCHAR(20) NOT NULL,days_allocated DECIMAL(5,1) NOT NULL,updated_by BIGINT UNSIGNED NOT NULL,updated_at DATETIME NOT NULL,PRIMARY KEY(employee_id,leave_year,leave_type),FOREIGN KEY(employee_id) REFERENCES employees(id),FOREIGN KEY(updated_by) REFERENCES employees(id)) ENGINE=InnoDB');
 }
 if(table_exists('attendance')&&!column_exists('attendance','auto_closed'))query('ALTER TABLE attendance ADD COLUMN auto_closed TINYINT(1) NOT NULL DEFAULT 0');
 if(table_exists('local_accounts')) {
  if(!table_exists('accounts'))query('RENAME TABLE local_accounts TO accounts');
  else {
   $conflict=query('SELECT l.employee_id FROM local_accounts l JOIN accounts a ON a.employee_id=l.employee_id OR a.username=l.username WHERE a.employee_id<>l.employee_id OR a.username<>l.username OR a.password_hash<>l.password_hash LIMIT 1')->fetch();
   if($conflict)throw new RuntimeException('Conflicting old and new accounts exist. No accounts were removed. Ask IT to reconcile them.');
   query('INSERT INTO accounts(employee_id,username,password_hash) SELECT l.employee_id,l.username,l.password_hash FROM local_accounts l LEFT JOIN accounts a ON a.employee_id=l.employee_id WHERE a.employee_id IS NULL');
   query('DROP TABLE local_accounts');
  }
 }
 if(table_exists('devices')) {
  if(!column_exists('devices','ip_address'))query('ALTER TABLE devices ADD ip_address VARCHAR(45) NULL');
  if(column_exists('devices','cert_hash')) {
   query("UPDATE devices SET ip_address='127.0.0.1',label='XAMPP server localhost' WHERE label LIKE 'Simulated local test%' AND ip_address IS NULL");
   query("UPDATE devices SET status='revoked' WHERE ip_address IS NULL");
   query('ALTER TABLE devices DROP COLUMN cert_hash');
  }
  if(!query("SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='devices' AND index_name='employee_ip'")->fetchColumn())query('ALTER TABLE devices ADD UNIQUE KEY employee_ip(employee_id,ip_address)');
 }
 if(table_exists('employees')&&column_exists('employees','domain_user'))query('ALTER TABLE employees DROP COLUMN domain_user');
 if(table_exists('leave_requests')&&!column_exists('leave_requests','leave_part'))query("ALTER TABLE leave_requests ADD leave_part ENUM('full','first_half','second_half') NOT NULL DEFAULT 'full'");
 if(table_exists('employees')) {
  $sql=<<<'FEATURE_SCHEMA'
CREATE TABLE IF NOT EXISTS holidays (holiday_date DATE PRIMARY KEY, title VARCHAR(120) NOT NULL) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS leave_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, employee_id BIGINT UNSIGNED NOT NULL,
 start_date DATE NOT NULL, end_date DATE NOT NULL, leave_type VARCHAR(20) NOT NULL,
 reason VARCHAR(500) NOT NULL, leave_part ENUM('full','first_half','second_half') NOT NULL DEFAULT 'full', status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
 reviewed_by BIGINT UNSIGNED NULL, review_note VARCHAR(500) NULL, reviewed_at DATETIME NULL, created_at DATETIME NOT NULL,
 FOREIGN KEY(employee_id) REFERENCES employees(id), FOREIGN KEY(reviewed_by) REFERENCES employees(id),
 INDEX employee_dates(employee_id,start_date,end_date), INDEX queue(status)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS weekly_schedules (
 employee_id BIGINT UNSIGNED NOT NULL, effective_date DATE NOT NULL,
 weekdays VARCHAR(13) NOT NULL, PRIMARY KEY(employee_id,effective_date),
 FOREIGN KEY(employee_id) REFERENCES employees(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS salary_months (
 employee_id BIGINT UNSIGNED NOT NULL, salary_month DATE NOT NULL,
 basic_salary DECIMAL(12,2) NOT NULL, allowances DECIMAL(12,2) NOT NULL DEFAULT 0,
 travelling_allowance DECIMAL(12,2) NOT NULL DEFAULT 0, attendance_bonus DECIMAL(12,2) NOT NULL DEFAULT 0,
 night_allowance DECIMAL(12,2) NOT NULL DEFAULT 0, meal_allowance DECIMAL(12,2) NOT NULL DEFAULT 0, epf_rate TINYINT UNSIGNED NOT NULL DEFAULT 0,
 deductions DECIMAL(12,2) NOT NULL DEFAULT 0, note VARCHAR(500) NOT NULL DEFAULT '',
 updated_by BIGINT UNSIGNED NOT NULL, updated_at DATETIME NOT NULL,
 PRIMARY KEY(employee_id,salary_month), FOREIGN KEY(employee_id) REFERENCES employees(id),
 FOREIGN KEY(updated_by) REFERENCES employees(id)
) ENGINE=InnoDB;
FEATURE_SCHEMA;
  foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')query($statement);
  foreach(['travelling_allowance','attendance_bonus','night_allowance','meal_allowance'] as $column)if(!column_exists('salary_months',$column))query('ALTER TABLE salary_months ADD COLUMN `'.$column.'` DECIMAL(12,2) NOT NULL DEFAULT 0');
  foreach(['ll_day_payment','days_payment','epf_other_earnings','stamp_duty','insurance_deduction','donation_deduction'] as $column)if(!column_exists('salary_months',$column))query('ALTER TABLE salary_months ADD COLUMN `'.$column.'` DECIMAL(12,2) NOT NULL DEFAULT 0');
  if(!column_exists('salary_months','epf_rate'))query('ALTER TABLE salary_months ADD COLUMN epf_rate TINYINT UNSIGNED NOT NULL DEFAULT 0');
 }
}
