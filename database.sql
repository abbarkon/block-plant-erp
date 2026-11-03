CREATE DATABASE IF NOT EXISTS block_plant CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE block_plant;
CREATE TABLE users(id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,username VARCHAR(50) UNIQUE NOT NULL,password VARCHAR(255) NOT NULL,role VARCHAR(30) DEFAULT 'admin',department VARCHAR(30) NULL,department_role ENUM('manager','user') NULL,status TINYINT DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE block_types(id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,size VARCHAR(50) NOT NULL,selling_price DECIMAL(12,2) DEFAULT 0,labor_cost_per_block DECIMAL(12,4) NOT NULL DEFAULT 0,status TINYINT DEFAULT 1,UNIQUE(name,size));
CREATE TABLE raw_materials(id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,unit VARCHAR(30) NOT NULL,current_stock DECIMAL(12,2) DEFAULT 0,unit_cost DECIMAL(12,2) DEFAULT 0,status TINYINT DEFAULT 1);
CREATE TABLE suppliers(id INT AUTO_INCREMENT PRIMARY KEY,supplier_code VARCHAR(40) UNIQUE NOT NULL,name VARCHAR(150) NOT NULL,phone VARCHAR(50),address VARCHAR(255),opening_balance DECIMAL(12,2) DEFAULT 0,status TINYINT DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE customer_categories(id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) UNIQUE NOT NULL,description TEXT,discount_percentage DECIMAL(5,2) DEFAULT 0,credit_days INT DEFAULT 0,status TINYINT DEFAULT 1);
CREATE TABLE customers(id INT AUTO_INCREMENT PRIMARY KEY,customer_code VARCHAR(40) UNIQUE NOT NULL,name VARCHAR(150) NOT NULL,phone VARCHAR(50),email VARCHAR(100),address VARCHAR(255),category_id INT,credit_limit DECIMAL(12,2) DEFAULT 0,opening_balance DECIMAL(12,2) DEFAULT 0,status TINYINT DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(category_id) REFERENCES customer_categories(id));
CREATE TABLE departments(id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL UNIQUE,status TINYINT DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE production(id INT AUTO_INCREMENT PRIMARY KEY,production_no VARCHAR(50) UNIQUE NOT NULL,production_date DATE NOT NULL,block_type_id INT NOT NULL,planned_quantity INT DEFAULT 0,produced_quantity INT DEFAULT 0,rejected_quantity INT DEFAULT 0,good_quantity INT DEFAULT 0,production_cost DECIMAL(14,2) DEFAULT 0,notes TEXT,created_by INT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(block_type_id) REFERENCES block_types(id),FOREIGN KEY(created_by) REFERENCES users(id));
CREATE TABLE production_materials(id INT AUTO_INCREMENT PRIMARY KEY,production_id INT NOT NULL,material_id INT NOT NULL,quantity DECIMAL(12,2) NOT NULL,unit_cost DECIMAL(12,2) NOT NULL,total_cost DECIMAL(14,2) NOT NULL,FOREIGN KEY(production_id) REFERENCES production(id) ON DELETE CASCADE,FOREIGN KEY(material_id) REFERENCES raw_materials(id));
CREATE TABLE block_bom(id INT AUTO_INCREMENT PRIMARY KEY,block_type_id INT NOT NULL,material_id INT NOT NULL,quantity_per_block DECIMAL(12,4) NOT NULL DEFAULT 0,notes VARCHAR(255) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(block_type_id) REFERENCES block_types(id) ON DELETE CASCADE,FOREIGN KEY(material_id) REFERENCES raw_materials(id),UNIQUE KEY unique_bom(block_type_id,material_id));
CREATE TABLE production_schedules(id INT AUTO_INCREMENT PRIMARY KEY,schedule_date DATE NOT NULL,block_type_id INT NOT NULL,target_quantity INT NOT NULL DEFAULT 0,status VARCHAR(20) DEFAULT 'planned',priority VARCHAR(20) DEFAULT 'normal',notes TEXT,production_id INT NULL,created_by INT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(block_type_id) REFERENCES block_types(id),FOREIGN KEY(production_id) REFERENCES production(id) ON DELETE SET NULL,FOREIGN KEY(created_by) REFERENCES users(id));
CREATE TABLE finished_stock(id INT AUTO_INCREMENT PRIMARY KEY,block_type_id INT UNIQUE NOT NULL,quantity INT DEFAULT 0,FOREIGN KEY(block_type_id) REFERENCES block_types(id));
CREATE TABLE stock_transactions(id INT AUTO_INCREMENT PRIMARY KEY,material_id INT NOT NULL,transaction_type VARCHAR(40) NOT NULL,quantity DECIMAL(12,2) NOT NULL,reference_no VARCHAR(60),notes VARCHAR(255),transaction_date DATE NOT NULL,created_by INT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(material_id) REFERENCES raw_materials(id),FOREIGN KEY(created_by) REFERENCES users(id));
CREATE TABLE sales(id INT AUTO_INCREMENT PRIMARY KEY,invoice_no VARCHAR(50) UNIQUE NOT NULL,customer_id INT NULL,sale_date DATE NOT NULL,subtotal DECIMAL(14,2) DEFAULT 0,discount DECIMAL(14,2) DEFAULT 0,total_amount DECIMAL(14,2) DEFAULT 0,paid_amount DECIMAL(14,2) DEFAULT 0,balance DECIMAL(14,2) DEFAULT 0,payment_status VARCHAR(20) DEFAULT 'paid',loading_cost DECIMAL(14,2) DEFAULT 0,unloading_cost DECIMAL(14,2) DEFAULT 0,vehicle_plate VARCHAR(50),driver_name VARCHAR(150),driver_phone VARCHAR(50),payment_method VARCHAR(30),payment_reference VARCHAR(100),salesperson_id INT,notes TEXT,created_by INT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE SET NULL,FOREIGN KEY(created_by) REFERENCES users(id));
CREATE TABLE sale_items(id INT AUTO_INCREMENT PRIMARY KEY,sale_id INT NOT NULL,block_type_id INT NOT NULL,quantity INT NOT NULL,unit_price DECIMAL(12,2) NOT NULL,total_price DECIMAL(14,2) NOT NULL,FOREIGN KEY(sale_id) REFERENCES sales(id) ON DELETE CASCADE,FOREIGN KEY(block_type_id) REFERENCES block_types(id));
CREATE TABLE payments(id INT AUTO_INCREMENT PRIMARY KEY,sale_id INT NULL,customer_id INT NULL,payment_date DATE NOT NULL,amount DECIMAL(14,2) NOT NULL,payment_method VARCHAR(30) NOT NULL,notes VARCHAR(255),created_by INT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(sale_id) REFERENCES sales(id) ON DELETE SET NULL,FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE SET NULL,FOREIGN KEY(created_by) REFERENCES users(id));
CREATE TABLE purchases(id INT AUTO_INCREMENT PRIMARY KEY,purchase_no VARCHAR(50) UNIQUE NOT NULL,supplier_id INT NULL,purchase_date DATE NOT NULL,subtotal DECIMAL(14,2) DEFAULT 0,total_amount DECIMAL(14,2) DEFAULT 0,paid_amount DECIMAL(14,2) DEFAULT 0,balance DECIMAL(14,2) DEFAULT 0,payment_status VARCHAR(20) DEFAULT 'paid',loading_cost DECIMAL(14,2) DEFAULT 0,unloading_cost DECIMAL(14,2) DEFAULT 0,notes TEXT,created_by INT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,FOREIGN KEY(created_by) REFERENCES users(id));
CREATE TABLE purchase_items(id INT AUTO_INCREMENT PRIMARY KEY,purchase_id INT NOT NULL,material_id INT NOT NULL,quantity DECIMAL(12,2) NOT NULL,unit_cost DECIMAL(12,2) NOT NULL,total_cost DECIMAL(14,2) NOT NULL,FOREIGN KEY(purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,FOREIGN KEY(material_id) REFERENCES raw_materials(id));
CREATE TABLE supplier_material_prices(id INT AUTO_INCREMENT PRIMARY KEY,supplier_id INT NOT NULL,material_id INT NOT NULL,unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,lead_time_days INT DEFAULT 7,is_preferred TINYINT DEFAULT 0,notes VARCHAR(255) NULL,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,FOREIGN KEY(supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,FOREIGN KEY(material_id) REFERENCES raw_materials(id) ON DELETE CASCADE,UNIQUE KEY unique_supplier_material(supplier_id,material_id));
CREATE TABLE purchase_schedules(id INT AUTO_INCREMENT PRIMARY KEY,schedule_date DATE NOT NULL,supplier_id INT NULL,material_id INT NOT NULL,target_quantity DECIMAL(12,2) NOT NULL DEFAULT 0,status VARCHAR(20) DEFAULT 'planned',priority VARCHAR(20) DEFAULT 'normal',notes TEXT,purchase_id INT NULL,created_by INT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,FOREIGN KEY(material_id) REFERENCES raw_materials(id),FOREIGN KEY(purchase_id) REFERENCES purchases(id) ON DELETE SET NULL,FOREIGN KEY(created_by) REFERENCES users(id));
CREATE TABLE supplier_payments(id INT AUTO_INCREMENT PRIMARY KEY,purchase_id INT NULL,supplier_id INT NULL,payment_date DATE NOT NULL,amount DECIMAL(14,2) NOT NULL,payment_method VARCHAR(30) NOT NULL,reference_no VARCHAR(60) NULL,notes VARCHAR(255) NULL,created_by INT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(purchase_id) REFERENCES purchases(id) ON DELETE SET NULL,FOREIGN KEY(supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,FOREIGN KEY(created_by) REFERENCES users(id));
CREATE TABLE expenses(id INT AUTO_INCREMENT PRIMARY KEY,voucher_no VARCHAR(50) UNIQUE NOT NULL,expense_date DATE NOT NULL,category VARCHAR(80) NOT NULL,description VARCHAR(255) NOT NULL,amount DECIMAL(14,2) NOT NULL,payment_method VARCHAR(30) NOT NULL,reference_no VARCHAR(80),notes TEXT,created_by INT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(created_by) REFERENCES users(id));
CREATE TABLE cashbook(id INT AUTO_INCREMENT PRIMARY KEY,transaction_date DATE NOT NULL,transaction_type VARCHAR(20) NOT NULL,reference_no VARCHAR(60),description VARCHAR(255),amount DECIMAL(14,2) NOT NULL,payment_method VARCHAR(30) NOT NULL,created_by INT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(created_by) REFERENCES users(id));
INSERT INTO users(name,username,password,role) VALUES('Plant Owner','admin','$2y$12$X6pGPAboNXNnSvmItKs4keRyjZKeJk2kM4YXF.O3iTINfbrDqBjIG','admin');
INSERT INTO block_types(name,size,selling_price) VALUES('Hollow Block','10 cm',0),('Hollow Block','15 cm',0),('Hollow Block','20 cm',0);
INSERT INTO raw_materials(name,unit) VALUES('Cement','bag'),('Sand','m3'),('Aggregate','m3');
INSERT INTO finished_stock(block_type_id,quantity) SELECT id,0 FROM block_types;

INSERT INTO customer_categories(name,description,discount_percentage,credit_days) VALUES
('Retail','Individual buyers',0,0),
('Wholesale','Bulk buyers',5,30),
('Contractor','Construction companies',10,45),
('VIP','Premium customers',15,60);

-- HR & PAYROLL MODULE
CREATE TABLE employees(id INT AUTO_INCREMENT PRIMARY KEY,employee_code VARCHAR(40) UNIQUE NOT NULL,name VARCHAR(150) NOT NULL,phone VARCHAR(50),email VARCHAR(100),address VARCHAR(255),designation VARCHAR(100),department VARCHAR(100),date_of_joining DATE,basic_salary DECIMAL(12,2) DEFAULT 0,bank_account VARCHAR(100),status TINYINT DEFAULT 1,shift VARCHAR(10) NOT NULL DEFAULT 'morning',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);

CREATE TABLE attendance(id INT AUTO_INCREMENT PRIMARY KEY,employee_id INT NOT NULL,attendance_date DATE NOT NULL,shift VARCHAR(10) NULL,clock_in TIME,clock_out TIME,working_hours DECIMAL(5,2) DEFAULT 0,status VARCHAR(20) DEFAULT 'present',notes TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(employee_id) REFERENCES employees(id),UNIQUE KEY unique_attendance(employee_id,attendance_date));

CREATE TABLE attendance_reminder_log(id INT AUTO_INCREMENT PRIMARY KEY,reminder_date DATE NOT NULL,active_count INT NOT NULL DEFAULT 0,missing_count INT NOT NULL DEFAULT 0,recipients INT NOT NULL DEFAULT 0,sent_at DATETIME NOT NULL,UNIQUE KEY unique_attendance_reminder_date(reminder_date));

CREATE TABLE attendance_warning_revocations(id INT AUTO_INCREMENT PRIMARY KEY,employee_id INT NOT NULL,period_from DATE NOT NULL,period_to DATE NOT NULL,reason TEXT NOT NULL,evidence_reference VARCHAR(255),revoked_by INT,revoked_at DATETIME NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(employee_id) REFERENCES employees(id),FOREIGN KEY(revoked_by) REFERENCES users(id),UNIQUE KEY unique_warning_revocation(employee_id,period_from,period_to));\n\nCREATE TABLE attendance_warning_revocation_files(id INT AUTO_INCREMENT PRIMARY KEY,revocation_id INT NOT NULL,original_name VARCHAR(255) NOT NULL,stored_name VARCHAR(255) NOT NULL,file_path VARCHAR(500) NOT NULL,file_size INT NOT NULL DEFAULT 0,mime_type VARCHAR(120) NOT NULL,uploaded_by INT,uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(revocation_id) REFERENCES attendance_warning_revocations(id) ON DELETE CASCADE,FOREIGN KEY(uploaded_by) REFERENCES users(id));

CREATE TABLE attendance_warning_issues(id INT AUTO_INCREMENT PRIMARY KEY,employee_id INT NOT NULL,period_from DATE NOT NULL,period_to DATE NOT NULL,absence_hash CHAR(32) NOT NULL,absence_days INT NOT NULL DEFAULT 0,reference VARCHAR(80) NOT NULL,issued_by INT,issued_at DATETIME NOT NULL,notified_count INT NOT NULL DEFAULT 0,UNIQUE KEY unique_warning_issue(employee_id,absence_hash),KEY idx_warning_issue_reference(reference),FOREIGN KEY(employee_id) REFERENCES employees(id),FOREIGN KEY(issued_by) REFERENCES users(id));

CREATE TABLE payroll(id INT AUTO_INCREMENT PRIMARY KEY,employee_id INT NOT NULL,month INT NOT NULL,year INT NOT NULL,basic_salary DECIMAL(12,2) DEFAULT 0,allowances DECIMAL(12,2) DEFAULT 0,deductions DECIMAL(12,2) DEFAULT 0,net_salary DECIMAL(12,2) DEFAULT 0,payment_date DATE,payment_method VARCHAR(30),payment_status VARCHAR(20) DEFAULT 'pending',notes TEXT,created_by INT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(employee_id) REFERENCES employees(id),FOREIGN KEY(created_by) REFERENCES users(id),UNIQUE KEY unique_payroll(employee_id,month,year));

CREATE TABLE leave_requests(id INT AUTO_INCREMENT PRIMARY KEY,employee_id INT NOT NULL,leave_type VARCHAR(50) NOT NULL,from_date DATE NOT NULL,to_date DATE NOT NULL,total_days INT NOT NULL,reason TEXT,status VARCHAR(20) DEFAULT 'pending',approved_by INT,approved_date DATE,notes TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(employee_id) REFERENCES employees(id),FOREIGN KEY(approved_by) REFERENCES users(id));

CREATE TABLE leave_balances(id INT AUTO_INCREMENT PRIMARY KEY,employee_id INT NOT NULL,year INT NOT NULL,leave_type VARCHAR(50) NOT NULL DEFAULT 'Annual Leave',entitlement_days DECIMAL(6,1) NOT NULL DEFAULT 0,adjustment_days DECIMAL(6,1) NOT NULL DEFAULT 0,notes VARCHAR(255),updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,FOREIGN KEY(employee_id) REFERENCES employees(id),UNIQUE KEY unique_leave_balance(employee_id,year,leave_type));

-- FIXED ASSETS MODULE
CREATE TABLE asset_categories(id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) UNIQUE NOT NULL,depreciation_rate DECIMAL(5,2) DEFAULT 0,status TINYINT DEFAULT 1);

CREATE TABLE assets(id INT AUTO_INCREMENT PRIMARY KEY,asset_code VARCHAR(40) UNIQUE NOT NULL,name VARCHAR(150) NOT NULL,category_id INT NOT NULL,purchase_date DATE NOT NULL,purchase_cost DECIMAL(14,2) DEFAULT 0,supplier_id INT,useful_life INT DEFAULT 0,salvage_value DECIMAL(14,2) DEFAULT 0,current_value DECIMAL(14,2) DEFAULT 0,location VARCHAR(150),condition_status VARCHAR(50) DEFAULT 'good',notes TEXT,status TINYINT DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(category_id) REFERENCES asset_categories(id),FOREIGN KEY(supplier_id) REFERENCES suppliers(id));

CREATE TABLE asset_maintenance(id INT AUTO_INCREMENT PRIMARY KEY,asset_id INT NOT NULL,maintenance_date DATE NOT NULL,maintenance_type VARCHAR(50) NOT NULL,description TEXT,cost DECIMAL(12,2) DEFAULT 0,performed_by VARCHAR(100),next_maintenance_date DATE,notes TEXT,created_by INT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(asset_id) REFERENCES assets(id),FOREIGN KEY(created_by) REFERENCES users(id));

-- ACCOUNTING MODULE
CREATE TABLE chart_of_accounts(id INT AUTO_INCREMENT PRIMARY KEY,account_code VARCHAR(40) UNIQUE NOT NULL,account_name VARCHAR(150) NOT NULL,account_type VARCHAR(50) NOT NULL,parent_id INT,balance DECIMAL(14,2) DEFAULT 0,status TINYINT DEFAULT 1,FOREIGN KEY(parent_id) REFERENCES chart_of_accounts(id));

CREATE TABLE journal_entries(id INT AUTO_INCREMENT PRIMARY KEY,entry_no VARCHAR(50) UNIQUE NOT NULL,entry_date DATE NOT NULL,description VARCHAR(255),reference_no VARCHAR(60),total_debit DECIMAL(14,2) DEFAULT 0,total_credit DECIMAL(14,2) DEFAULT 0,status VARCHAR(20) DEFAULT 'draft',posted_by INT,posted_at TIMESTAMP NULL,created_by INT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(created_by) REFERENCES users(id),FOREIGN KEY(posted_by) REFERENCES users(id));

CREATE TABLE journal_entry_lines(id INT AUTO_INCREMENT PRIMARY KEY,entry_id INT NOT NULL,account_id INT NOT NULL,debit DECIMAL(14,2) DEFAULT 0,credit DECIMAL(14,2) DEFAULT 0,description VARCHAR(255),FOREIGN KEY(entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,FOREIGN KEY(account_id) REFERENCES chart_of_accounts(id));

-- SETTINGS MODULE
CREATE TABLE company_settings(id INT AUTO_INCREMENT PRIMARY KEY,company_name VARCHAR(200) NOT NULL,company_name_am VARCHAR(200) NULL,tagline VARCHAR(255) NULL,tagline_am VARCHAR(255) NULL,address TEXT,phone VARCHAR(50),email VARCHAR(100),tax_id VARCHAR(50),logo_path VARCHAR(255),currency VARCHAR(10) DEFAULT 'ETB',currency_symbol VARCHAR(10) DEFAULT 'Br',date_format VARCHAR(20) DEFAULT 'Y-m-d',timezone VARCHAR(50) DEFAULT 'Africa/Addis_Ababa',locale_default VARCHAR(10) DEFAULT 'en',primary_color VARCHAR(20) DEFAULT '#3949ab',secondary_color VARCHAR(20) DEFAULT '#00897b',default_commission_rate DECIMAL(5,2) DEFAULT 5.00,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP);

CREATE TABLE user_permissions(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,module VARCHAR(50) NOT NULL,can_view TINYINT DEFAULT 0,can_create TINYINT DEFAULT 0,can_edit TINYINT DEFAULT 0,can_delete TINYINT DEFAULT 0,can_approve TINYINT DEFAULT 0,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,UNIQUE KEY unique_permission(user_id,module));

CREATE TABLE hr_permission_grants(user_id INT NOT NULL PRIMARY KEY,can_edit TINYINT NOT NULL DEFAULT 0,can_delete TINYINT NOT NULL DEFAULT 0,can_approve TINYINT NOT NULL DEFAULT 0,updated_by INT NULL,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP);

CREATE TABLE activity_log(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,action VARCHAR(100) NOT NULL,module VARCHAR(50) NOT NULL,record_id INT,description TEXT,ip_address VARCHAR(50),user_agent VARCHAR(255),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(user_id) REFERENCES users(id));

-- ADDITIONAL INVENTORY FEATURES
CREATE TABLE stock_adjustments(id INT AUTO_INCREMENT PRIMARY KEY,adjustment_no VARCHAR(50) UNIQUE NOT NULL,adjustment_date DATE NOT NULL,material_id INT NOT NULL,adjustment_type VARCHAR(20) NOT NULL,quantity DECIMAL(12,2) NOT NULL,reason VARCHAR(255),created_by INT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(material_id) REFERENCES raw_materials(id),FOREIGN KEY(created_by) REFERENCES users(id));

CREATE TABLE reorder_levels(id INT AUTO_INCREMENT PRIMARY KEY,material_id INT UNIQUE NOT NULL,min_level DECIMAL(12,2) DEFAULT 0,max_level DECIMAL(12,2) DEFAULT 0,reorder_point DECIMAL(12,2) DEFAULT 0,reorder_quantity DECIMAL(12,2) DEFAULT 0,FOREIGN KEY(material_id) REFERENCES raw_materials(id));

-- QUOTATIONS MODULE
CREATE TABLE quotations(id INT AUTO_INCREMENT PRIMARY KEY,quotation_no VARCHAR(50) UNIQUE NOT NULL,customer_id INT NULL,quotation_date DATE NOT NULL,expiry_date DATE,subtotal DECIMAL(14,2) DEFAULT 0,discount DECIMAL(14,2) DEFAULT 0,total_amount DECIMAL(14,2) DEFAULT 0,status VARCHAR(20) DEFAULT 'pending',notes TEXT,created_by INT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE SET NULL,FOREIGN KEY(created_by) REFERENCES users(id));

CREATE TABLE quotation_items(id INT AUTO_INCREMENT PRIMARY KEY,quotation_id INT NOT NULL,block_type_id INT NOT NULL,quantity INT NOT NULL,unit_price DECIMAL(12,2) NOT NULL,total_price DECIMAL(14,2) NOT NULL,FOREIGN KEY(quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,FOREIGN KEY(block_type_id) REFERENCES block_types(id));

-- ADVANCED CRM FEATURES
CREATE TABLE customer_contacts(id INT AUTO_INCREMENT PRIMARY KEY,customer_id INT NOT NULL,contact_name VARCHAR(150) NOT NULL,position VARCHAR(100),phone VARCHAR(50),email VARCHAR(100),is_primary TINYINT DEFAULT 0,notes TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE CASCADE);

CREATE TABLE customer_interactions(id INT AUTO_INCREMENT PRIMARY KEY,customer_id INT NOT NULL,interaction_type VARCHAR(50) NOT NULL,interaction_date DATE NOT NULL,subject VARCHAR(255),description TEXT,next_action VARCHAR(255),next_action_date DATE,status VARCHAR(20) DEFAULT 'pending',created_by INT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(customer_id) REFERENCES customers(id),FOREIGN KEY(created_by) REFERENCES users(id));

CREATE TABLE sales_targets(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,month INT NOT NULL,year INT NOT NULL,target_amount DECIMAL(14,2) NOT NULL,achieved_amount DECIMAL(14,2) DEFAULT 0,status VARCHAR(20) DEFAULT 'active',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(user_id) REFERENCES users(id),UNIQUE KEY unique_target(user_id,month,year));

CREATE TABLE sales_pipeline(id INT AUTO_INCREMENT PRIMARY KEY,customer_id INT,opportunity_name VARCHAR(255) NOT NULL,estimated_value DECIMAL(14,2) NOT NULL,probability INT DEFAULT 50,stage VARCHAR(50) DEFAULT 'lead',expected_close_date DATE,assigned_to INT,notes TEXT,status VARCHAR(20) DEFAULT 'active',created_by INT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(customer_id) REFERENCES customers(id),FOREIGN KEY(assigned_to) REFERENCES users(id),FOREIGN KEY(created_by) REFERENCES users(id));

CREATE TABLE customer_documents(id INT AUTO_INCREMENT PRIMARY KEY,customer_id INT NOT NULL,document_name VARCHAR(255) NOT NULL,document_type VARCHAR(50),file_path VARCHAR(255),file_size INT,uploaded_by INT,uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(customer_id) REFERENCES customers(id),FOREIGN KEY(uploaded_by) REFERENCES users(id));

CREATE TABLE sales_commissions(id INT AUTO_INCREMENT PRIMARY KEY,sale_id INT NOT NULL,user_id INT NOT NULL,commission_percentage DECIMAL(5,2) NOT NULL,commission_amount DECIMAL(12,2) NOT NULL,payment_status VARCHAR(20) DEFAULT 'pending',paid_date DATE,notes TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(sale_id) REFERENCES sales(id),FOREIGN KEY(user_id) REFERENCES users(id));

CREATE TABLE delivery_notes(id INT AUTO_INCREMENT PRIMARY KEY,delivery_no VARCHAR(50) UNIQUE NOT NULL,sale_id INT NOT NULL,delivery_date DATE NOT NULL,vehicle_no VARCHAR(50),driver_name VARCHAR(100),delivery_address TEXT,delivery_status VARCHAR(20) DEFAULT 'pending',received_by VARCHAR(150),received_date DATE,notes TEXT,created_by INT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(sale_id) REFERENCES sales(id),FOREIGN KEY(created_by) REFERENCES users(id));

CREATE TABLE customer_feedback(id INT AUTO_INCREMENT PRIMARY KEY,customer_id INT NOT NULL,sale_id INT,feedback_date DATE NOT NULL,rating INT,product_quality_rating INT,service_rating INT,delivery_rating INT,comments TEXT,action_taken TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(customer_id) REFERENCES customers(id),FOREIGN KEY(sale_id) REFERENCES sales(id));

-- Insert default data
INSERT INTO company_settings(company_name,company_name_am,tagline,tagline_am,address,phone,email,currency,currency_symbol,locale_default,primary_color,secondary_color,default_commission_rate) VALUES('Block Plant Company','ብሎክ 플랜ት','Quality blocks for modern construction','ለህንጻ ሥራ የጥራት ብሎክ','Main Street, Addis Ababa','+251-11-000-0000','info@blockplant.com','ETB','Br','en','#3949ab','#00897b',5.00);

INSERT INTO asset_categories(name,depreciation_rate) VALUES('Machinery',10),('Vehicles',20),('Equipment',15),('Building',5);

INSERT INTO chart_of_accounts(account_code,account_name,account_type,parent_id) VALUES
('1000','Assets','asset',NULL),
('1100','Current Assets','asset',1),
('1110','Cash','asset',2),
('1120','Accounts Receivable','asset',2),
('1130','Inventory','asset',2),
('1200','Fixed Assets','asset',1),
('2000','Liabilities','liability',NULL),
('2100','Current Liabilities','liability',7),
('2110','Accounts Payable','liability',8),
('3000','Equity','equity',NULL),
('3100','Capital','equity',10),
('4000','Revenue','revenue',NULL),
('4100','Sales Revenue','revenue',12),
('5000','Cost of Goods Sold','expense',NULL),
('5100','Direct Materials','expense',14),
('5200','Direct Labor','expense',14),
('6000','Operating Expenses','expense',NULL),
('6100','Administrative Expenses','expense',17),
('6200','Selling Expenses','expense',17);

-- Telegram daily report and alerts (also created automatically by config/telegram.php).
CREATE TABLE IF NOT EXISTS telegram_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bot_token VARCHAR(200) NULL,
    bot_username VARCHAR(100) NULL,
    chat_id VARCHAR(64) NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    send_time TIME NOT NULL DEFAULT '18:00:00',
    attach_csv TINYINT(1) NOT NULL DEFAULT 1,
    alert_missing_attendance TINYINT(1) NOT NULL DEFAULT 1,
    alert_absences TINYINT(1) NOT NULL DEFAULT 1,
    alert_late TINYINT(1) NOT NULL DEFAULT 1,
    alert_pending_approvals TINYINT(1) NOT NULL DEFAULT 1,
    alert_low_stock TINYINT(1) NOT NULL DEFAULT 1,
    alert_overdue_balances TINYINT(1) NOT NULL DEFAULT 1,
    alert_asset_maintenance TINYINT(1) NOT NULL DEFAULT 1,
    resolve_ip VARCHAR(45) NULL,
    last_sent_date DATE NULL,
    last_sent_at DATETIME NULL,
    last_error TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO telegram_settings (id, send_time) VALUES (1, '18:00:00')
ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS telegram_message_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_type VARCHAR(30) NOT NULL DEFAULT 'daily',
    report_date DATE NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'sent',
    chat_id VARCHAR(64) NULL,
    message MEDIUMTEXT NULL,
    response TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_telegram_log_date (report_date, message_type),
    INDEX idx_telegram_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
