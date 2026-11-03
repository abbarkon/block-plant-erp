# Block Plant ERP System

A comprehensive Enterprise Resource Planning (ERP) system specifically designed for concrete block manufacturing plants. This full-featured system manages all aspects of your business from production to accounting.

## 🌟 Complete Feature Set

### 📊 Core Modules

#### 1. **Dashboard & Analytics**
- Real-time business metrics and KPIs
- Today's overview (production, sales, expenses)
- Month-to-date performance tracking
- Financial position summary
- Recent activities feed
- Quick action shortcuts

#### 2. **Sales & CRM**
- **Quotations Management**
  - Create and manage price quotations
  - Convert quotations to sales orders
  - Track quotation status (pending/accepted/rejected)
  - Set expiry dates

- **Sales Invoicing**
  - Create sales invoices
  - Multiple payment options
  - Partial payment support
  - Customer credit management
  - Automatic stock deduction

- **Customer Management**
  - Customer database with contact information
  - Credit limit tracking
  - Opening balance support
  - Customer statement of accounts
  - Aging analysis

- **Payment Tracking**
  - Record customer payments
  - Payment history
  - Outstanding balance tracking

#### 3. **Purchasing**
- **Purchase Orders**
  - Create purchase orders for raw materials
  - Supplier selection
  - Automatic stock updates
  - Payment status tracking

- **Supplier Management**
  - Supplier database
  - Contact information
  - Payment history
  - Outstanding payables

- **Supplier Payments**
  - Record payments to suppliers
  - Payment allocation
  - Balance management

#### 4. **Production Management**
- **Production Orders**
  - Plan and execute production runs
  - Material consumption tracking
  - Quality control (good/rejected quantities)
  - Production costing
  - Automatic finished goods stock updates

- **Production Planning**
  - Production scheduling
  - Material requirements planning
  - Capacity planning

- **Bill of Materials**
  - Define material requirements per block type
  - Automatic material allocation
  - Cost calculation

#### 5. **Inventory Control**
- **Raw Materials Management**
  - Track cement, sand, aggregate, and other materials
  - Current stock levels
  - Unit cost tracking
  - Stock valuation

- **Finished Goods Stock**
  - Track different block types and sizes
  - Real-time stock quantities
  - Stock value calculation

- **Stock Movement Tracking**
  - Complete transaction history
  - Material receipts and issues
  - Audit trail

- **Stock Adjustments**
  - Increase/decrease stock levels
  - Reason tracking
  - Automatic transaction recording

- **Reorder Level Management**
  - Set minimum, maximum, and reorder points
  - Automatic low stock alerts
  - Reorder quantity recommendations
  - Visual status indicators

#### 6. **HR & Payroll**
- **Employee Management**
  - Employee database
  - Personal information
  - Designation and department
  - Bank account details
  - Employment history

- **Attendance System**
  - Daily attendance marking
  - Clock in/out times
  - Working hours calculation
  - Attendance status (present/absent/half-day/leave)

- **Leave Management**
  - Leave request submission
  - Multiple leave types
  - Approval workflow
  - Leave balance tracking

- **Payroll Processing**
  - Monthly payroll generation
  - Basic salary + allowances - deductions
  - Net salary calculation
  - Payment status tracking
  - Payroll reports

#### 7. **Fixed Assets**
- **Asset Management**
  - Asset registration and tracking
  - Purchase details and costs
  - Asset categorization
  - Current value tracking
  - Location tracking
  - Condition status

- **Asset Categories**
  - Predefined categories (Machinery, Vehicles, Equipment, Buildings)
  - Configurable depreciation rates
  - Category-wise reporting

- **Maintenance Tracking**
  - Schedule and record maintenance
  - Maintenance types (preventive/corrective/emergency)
  - Cost tracking
  - Next maintenance date reminders
  - Maintenance history

- **Depreciation Calculation**
  - Automatic depreciation calculation
  - Multiple depreciation methods
  - Book value tracking
  - Depreciation reports

#### 8. **Financial Management**
- **Expense Management**
  - Record business expenses
  - Expense categorization
  - Payment method tracking
  - Expense approval workflow

- **Cash Book**
  - Daily cash receipts and payments
  - Running balance
  - Opening and closing balance
  - Payment method breakdown

- **Chart of Accounts**
  - Complete account hierarchy
  - Asset, Liability, Equity, Revenue, Expense accounts
  - Account balance tracking
  - Financial statement preparation

- **Journal Entries**
  - Manual journal entry creation
  - Double-entry bookkeeping
  - Entry approval workflow
  - Audit trail

#### 9. **Comprehensive Reports**
- **Sales Reports**
  - Sales by period
  - Sales by customer
  - Product-wise sales analysis
  - Sales trends

- **Purchase Reports**
  - Purchase history
  - Supplier-wise purchases
  - Material consumption analysis

- **Production Reports**
  - Production efficiency
  - Quality metrics
  - Production costs
  - Unit cost analysis

- **Inventory Reports**
  - Stock valuation
  - Stock movement
  - Slow-moving items
  - Stock age analysis

- **Financial Reports**
  - Income Statement (P&L)
  - Balance Sheet
  - Cash Flow Statement
  - Expense analysis
  - Profit margins

- **Aging Reports**
  - Customer receivables aging (Current, 31-60, 61-90, 90+ days)
  - Supplier payables aging
  - Collection priority analysis

#### 10. **System Settings**
- **Company Settings**
  - Company information
  - Contact details
  - Tax registration
  - Currency settings
  - Logo upload

- **User Management**
  - Create user accounts
  - Role-based access (Admin, Manager, Operator, Accountant)
  - User activation/deactivation
  - Password management

- **Block Types Configuration**
  - Define block types
  - Set sizes and specifications
  - Configure selling prices
  - Active/inactive status

- **Backup & Restore**
  - One-click database backup
  - Download backup files
  - Restore instructions
  - Backup schedule recommendations

## 🔐 Security Features

- Password hashing with bcrypt
- Session-based authentication
- SQL injection prevention with prepared statements
- XSS protection with htmlspecialchars
- User role management
- Activity logging (ready for implementation)

## 📱 User Interface

- **Responsive Design**: Works on desktop, tablet, and mobile
- **Modern Navigation**: Dropdown menus with organized modules
- **Visual Status Indicators**: Color-coded status badges
- **Quick Actions**: Shortcuts to frequently used features
- **Print Support**: Print-friendly reports
- **Clean Layout**: Professional, easy-to-use interface

## 🚀 Installation

### Prerequisites
- XAMPP (Apache + MySQL + PHP 7.4+)
- Web browser (Chrome, Firefox, Edge recommended)

### Step-by-Step Installation

1. **Download & Extract**
   - Extract the `block_plant` folder to `C:\xampp\htdocs\`

2. **Start XAMPP**
   - Open XAMPP Control Panel
   - Start Apache
   - Start MySQL

3. **Create Database**
   - Open http://localhost/phpmyadmin
   - Click "Import" tab
   - Select `database.sql` from the block_plant folder
   - Click "Go" button

4. **Configure Database Connection** (if needed)
   - Edit `config/database.php`
   - Update MySQL credentials if your root user has a password

5. **Access System**
   - Open http://localhost/block_plant/
   - Login with default credentials:
     - **Username**: admin
     - **Password**: password

6. **Change Default Password** (Important!)
   - Go to Settings > User Management
   - Change the admin password immediately

## 📖 User Guide

### Getting Started

1. **Setup Company Information**
   - Go to Settings > Company Settings
   - Fill in your company details

2. **Configure Block Types**
   - Go to Settings > Block Types
   - Add your block types and sizes
   - Set selling prices

3. **Add Raw Materials** (if not present)
   - Go to Inventory > Materials
   - Add materials with opening stock

4. **Add Initial Data**
   - Add customers (Sales & CRM > Customers)
   - Add suppliers (Purchasing > Suppliers)
   - Add employees (HR & Payroll > Employees)

### Daily Operations

#### Morning Routine
1. Mark employee attendance (HR > Attendance)
2. Check dashboard for alerts and metrics
3. Review low stock items
4. Check pending quotations

#### Production Process
1. Create production order (Production > Production Orders)
2. Select block type and quantity
3. Add material consumption
4. Record good and rejected quantities
5. System automatically updates stock

#### Sales Process
1. Create quotation (optional)
2. Create sales invoice
3. Select customer and items
4. Record payment
5. System updates stock and accounts

#### Purchase Process
1. Create purchase order
2. Select supplier and materials
3. Enter quantities and prices
4. Record payment
5. System updates material stock

### Month-End Procedures
1. Process payroll (HR > Payroll)
2. Review aging reports
3. Generate financial reports
4. Create database backup
5. Review inventory levels

## 🔧 Technical Details

### Technology Stack
- **Backend**: PHP 7.4+ with PDO
- **Database**: MySQL 5.7+ with InnoDB
- **Frontend**: HTML5, CSS3, Vanilla JavaScript
- **Architecture**: MVC-inspired structure
- **Security**: Prepared statements, password hashing, session management

### Database Schema
- 30+ tables covering all modules
- Foreign key constraints for data integrity
- Indexed fields for performance
- Transaction support for data consistency

### File Structure
```
block_plant/
├── assets/          # CSS and static assets
├── auth/            # Login/logout functionality
├── config/          # Database configuration
├── customers/       # Customer management
├── dashboard/       # Main dashboard
├── expenses/        # Expense management
├── finance/         # Financial modules
├── hr/              # HR & Payroll
├── inventory/       # Inventory management
├── partials/        # Reusable header/footer
├── production/      # Production management
├── purchases/       # Purchase management
├── quotations/      # Quotation management
├── reports/         # All reports
├── sales/           # Sales management
├── settings/        # System settings
├── suppliers/       # Supplier management
├── assets/          # Fixed assets
├── database.sql     # Database schema
└── README.md        # This file
```

## 💡 Tips & Best Practices

### Data Management
- **Regular Backups**: Backup daily (Settings > Backup)
- **Data Entry**: Enter opening balances for customers/suppliers
- **Stock Verification**: Conduct regular physical stock counts
- **Price Updates**: Keep block prices current

### Financial Management
- **Daily Reconciliation**: Match physical cash with system
- **Month-End Close**: Generate and review reports
- **Expense Categorization**: Use consistent categories
- **Approval Workflow**: Implement multi-level approvals

### Security
- **Strong Passwords**: Use complex passwords
- **User Roles**: Assign appropriate roles
- **Regular Updates**: Keep system updated
- **Access Control**: Limit admin access

## 🆘 Troubleshooting

### Common Issues

**Cannot login**
- Verify username/password
- Check if user is active
- Clear browser cache

**Database connection error**
- Verify MySQL is running
- Check database credentials in config/database.php
- Ensure database exists

**Stock not updating**
- Check transaction logs
- Verify data entry
- Review inventory movement report

**Reports showing wrong data**
- Check date filters
- Verify data entry dates
- Review transaction history

## 📞 Support & Maintenance

### Regular Maintenance Tasks
- Daily database backup
- Weekly stock reconciliation
- Monthly financial close
- Quarterly system review
- Annual data archival

### Performance Optimization
- Regular database optimization
- Clear old logs
- Index maintenance
- Cache management

## 📝 License & Credits

This ERP system is designed for concrete block manufacturing plants. It includes comprehensive features for managing all aspects of the business from production to accounting.

**Default Login:**
- Username: admin
- Password: password

**Important**: Change the default password immediately after first login!

## 🔮 Future Enhancements

Potential additions for future versions:
- Multi-currency support
- Email notifications
- SMS integration
- Barcode scanning
- Mobile app
- Advanced analytics
- Cloud backup
- Multi-location support
- API integration
- Document management

## 📊 System Requirements

**Minimum:**
- PHP 7.4+
- MySQL 5.7+
- 2GB RAM
- 500MB disk space

**Recommended:**
- PHP 8.0+
- MySQL 8.0+
- 4GB RAM
- 1GB disk space
- SSD storage

---

**Version**: 2.0 Full ERP
**Last Updated**: 2024
**Status**: Production Ready

For issues or questions, refer to the troubleshooting section or contact your system administrator.
