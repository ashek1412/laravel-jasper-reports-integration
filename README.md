# laravel-jasper-reports-integration


A Laravel controller for generating reports using JRXML templates with support for multiple output formats (PDF, Excel, CSV, DOCX).

## Features

- Generate PDF, Excel, CSV, and DOCX reports from JRXML templates
- Support for custom parameters and dynamic report generation
- Database connection management (SQL Server - easily adaptable for other databases)
- Temporary file cleanup and management
- Report template validation
- Inline PDF viewing
- Connection testing utilities
- No JasperReports Server required - uses JRXML files directly

## Requirements

- PHP 7.4 or higher
- Laravel 8.x or higher
- Java Runtime Environment (JRE) 8 or higher
- PHPJasper package (uses JasperStarter internally)
- SQL Server JDBC Driver (or your database's JDBC driver)
- JRXML report templates

## Installation

### 1. Install Java Runtime Environment

Ensure you have Java 8 or higher installed:
```bash
java -version
```

### 2. Install PHPJasper

```bash
composer require geekcom/phpjasper
```
*Note: PHPJasper automatically includes JasperStarter, so no separate download is needed*

### 3. Download JDBC Driver

For SQL Server, download the Microsoft JDBC Driver:
- Visit: https://docs.microsoft.com/en-us/sql/connect/jdbc/download-microsoft-jdbc-driver-for-sql-server
- Extract and place the `.jar` file in `vendor/geekcom/phpjasper/bin/jasperstarter/jdbc/`
- File should be named something like `mssql-jdbc-XX.X.X.jreX.jar`

For other databases, download the appropriate JDBC driver:
- **MySQL**: `mysql-connector-java-X.X.XX.jar`
- **PostgreSQL**: `postgresql-XX.X.X.jar`
- **Oracle**: `ojdbc8.jar` or similar

### 4. Directory Structure and JRXML Templates

Create the following directories in your Laravel public storage:

```
public/storage/reports/
├── export/
│   ├── temp/                    # Temporary generated files
│   ├── your_report.jrxml        # Your JRXML report templates
│   ├── gross_with_vat.jrxml     # Example VAT report template
│   └── custom_report.jrxml      # Custom report templates
└── resources/
    ├── moneyformatter.jar       # Optional: Custom Java resources
    └── images/                  # Report images/logos
        └── company_logo.png
```

**Important**: Place your `.jrxml` report template files in the `public/storage/reports/export/` directory. These are the report designs created with JasperSoft Studio or other JRXML designers.

### 5. Environment Configuration

Add these environment variables to your `.env` file:

```env
DB_CONNECTION=sqlsrv
DB_HOST=127.0.0.1
DB_PORT=1433
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

## Creating JRXML Templates

### Using JasperSoft Studio (Recommended)

1. Download JasperSoft Studio Community Edition (free) from [Jaspersoft](https://community.jaspersoft.com/project/jaspersoft-studio)
2. Create a new report project
3. Design your report with:
   - **Parameters**: Use `$P{parameter_name}` syntax
   - **Fields**: Map to your database columns
   - **Queries**: Write SQL queries with parameter placeholders
4. Save as `.jrxml` file
5. Place the `.jrxml` file in your reports directory

### JRXML Template Requirements

Your JRXML templates should include:

```xml
<!-- Parameters used by the controller -->
<parameter name="from" class="java.lang.String"/>
<parameter name="to" class="java.lang.String"/>  
<parameter name="xcus" class="java.lang.String"/>

<!-- SQL Query with parameters -->
<queryString>
    <![CDATA[
        SELECT column1, column2, column3 
        FROM your_table 
        WHERE date_column BETWEEN $P{from} AND $P{to}
        AND customer_id = $P{xcus}
    ]]>
</queryString>
```

### Alternative JRXML Editors

- **JasperSoft Studio** - Full-featured, free IDE
- **JasperReports Library** - Programmatic report creation
- **Third-party tools** - Various online and desktop editors
- **Manual XML editing** - For simple reports (not recommended for complex layouts)

## Usage

### Basic Report Generation

```php
// Generate a gross VAT report
POST /reports/gross-with-vat
{
    "from_date": "2024-01-01",
    "to_date": "2024-12-31",
    "customer_id": "CUST001",
    "format": "pdf"
}
```

### View Report Inline

```php
// View report in browser (PDF only)
GET /reports/view
{
    "report_name": "gross_with_vat",
    "from_date": "2024-01-01",
    "to_date": "2024-12-31",
    "customer_id": "CUST001"
}
```

### Custom Report Generation

```php
// Generate custom report with dynamic parameters
POST /reports/custom
{
    "report_name": "custom_report",
    "format": "xlsx",
    "params": {
        "custom_param1": "value1",
        "custom_param2": "value2"
    }
}
```

### Available Endpoints

- `POST /reports/gross-with-vat` - Generate gross VAT report
- `GET /reports/view` - View report inline (PDF)
- `POST /reports/custom` - Generate custom report
- `GET /reports/cleanup` - Clean temporary files
- `GET /reports/available` - List available report templates
- `GET /reports/test-connection` - Test database connection

## Route Configuration

Add these routes to your `routes/web.php` or `routes/api.php`:

```php
Route::prefix('reports')->group(function () {
    Route::post('/gross-with-vat', [JasperReportController::class, 'generateGrossWithVatReport']);
    Route::get('/view', [JasperReportController::class, 'viewReport']);
    Route::post('/custom', [JasperReportController::class, 'generateCustomReport']);
    Route::get('/cleanup', [JasperReportController::class, 'cleanupTempFiles']);
    Route::get('/available', [JasperReportController::class, 'getAvailableReports']);
    Route::get('/test-connection', [JasperReportController::class, 'testConnection']);
});
```

## Configuration

The controller uses the following configuration:

```php
private $jasperConfig = [
    'jdbc_dir' => base_path('vendor/geekcom/phpjasper/bin/jasperstarter/jdbc'),
    'reports_dir' => public_path('storage/reports/accounts/export'),
    'temp_dir' => public_path('storage/reports/accounts/export/temp'),
    'resources_dir' => public_path('storage/reports/resources'),
];
```

## Supported Formats

- PDF
- Excel (XLSX)
- Word (DOCX)
- CSV

## Security Considerations

- Ensure report templates are stored securely
- Validate all user inputs
- Consider rate limiting for report generation
- Regularly clean up temporary files
- Use environment variables for sensitive configuration

## Troubleshooting

### Common Issues

1. **JDBC Driver Not Found**
   - Download the correct JDBC driver for your database
   - Place in `vendor/geekcom/phpjasper/bin/jasperstarter/jdbc/`
   - Verify file permissions and naming

2. **Java Runtime Issues**
   - Ensure Java 8+ is installed: `java -version`
   - Check JAVA_HOME environment variable
   - Verify PATH includes Java binary

3. **JRXML Template Errors**
   - Validate JRXML syntax using JasperSoft Studio
   - Check parameter names match controller expectations
   - Ensure field names match database columns
   - Verify SQL query syntax for your database

4. **Permission Errors**
   - Ensure directories have proper write permissions (755)
   - Check that the web server can access the directories
   - Verify temporary directory is writable

5. **Memory Issues**
   - Increase PHP memory limit for large reports
   - Consider pagination for very large datasets
   - Use `ini_set('memory_limit', '512M')` for complex reports

6. **Database Connection Issues**
   - Use the test connection endpoint to verify database connectivity
   - Check firewall settings
   - Verify database server accepts connections
   - Confirm JDBC URL format is correct

7. **Report Generation Timeouts**
   - Increase PHP execution time: `set_time_limit(300)`
   - Optimize JRXML queries for better performance
   - Consider asynchronous report generation for large reports

## Contributing

1. Fork the repository
2. Create a feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Acknowledgments

- [PHPJasper](https://github.com/geekcom/PHPJasper) for the JasperReports integration
- [JasperReports](https://community.jaspersoft.com/) for the reporting engine
