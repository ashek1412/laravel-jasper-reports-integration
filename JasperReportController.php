<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPJasper\PHPJasper;
use Carbon\Carbon;

class JasperReportController extends Controller
{
    /**
     * JasperReports configuration
     */
    private $jasperConfig;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->jasperConfig = [
            'jdbc_dir' => base_path('vendor/geekcom/phpjasper/bin/jasperstarter/jdbc'),
            'reports_dir' => public_path('storage/reports/'),
            'temp_dir' => public_path('storage/reports/temp'),
            'resources_dir' => public_path('storage/reports/resources'),
        ];

        // Ensure directories exist
        $this->ensureDirectoriesExist();
    }

    /**
     * Generate gross with VAT report
     */
    public function generateGrossWithVatReport(Request $request)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'from_date' => 'required|date',
                'to_date' => 'required|date|after:from_date',
                'customer_id' => 'required|string',
                'format' => 'nullable|in:pdf,xlsx,docx,csv',
            ]);

            // Generate the report
            $reportPath = $this->generateReport(
                'gross_with_vat',
                [
                    'from' => Carbon::parse($validated['from_date'])->format('Y-m-d'),
                    'to' => Carbon::parse($validated['to_date'])->format('Y-m-d'),
                    'xcus' => $validated['customer_id'],
                ],
                $validated['format'] ?? 'pdf'
            );

            // Return download response
            return response()->download($reportPath)->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            Log::error('Gross VAT Report Generation Failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate report',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred'
            ], 500);
        }
    }

    /**
     * Generate report and return as inline view (PDF only)
     */
    public function viewReport(Request $request)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'report_name' => 'required|string',
                'from_date' => 'required|date',
                'to_date' => 'required|date|after:from_date',
                'customer_id' => 'nullable|string',
            ]);

            // Generate the report
            $reportPath = $this->generateReport(
                $validated['report_name'],
                [
                    'from' => Carbon::parse($validated['from_date'])->format('Y-m-d'),
                    'to' => Carbon::parse($validated['to_date'])->format('Y-m-d'),
                    'xcus' => $validated['customer_id'] ?? null,
                ],
                'pdf'
            );

            // Return inline view
            return response()->file($reportPath, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . basename($reportPath) . '"'
            ]);

        } catch (\Exception $e) {
            Log::error('Report View Failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to view report',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred'
            ], 500);
        }
    }

    /**
     * Generate custom report with dynamic parameters
     */
    public function generateCustomReport(Request $request)
    {
        try {
            // Validate base request
            $validated = $request->validate([
                'report_name' => 'required|string',
                'format' => 'nullable|in:pdf,xlsx,docx,csv',
                'params' => 'nullable|array',
            ]);

            // Generate the report with custom parameters
            $reportPath = $this->generateReport(
                $validated['report_name'],
                $validated['params'] ?? [],
                $validated['format'] ?? 'pdf'
            );

            // Return download response
            return response()->download($reportPath)->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            Log::error('Custom Report Generation Failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate custom report',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred'
            ], 500);
        }
    }

    /**
     * Core report generation method
     */
    private function generateReport(string $reportName, array $params = [], string $format = 'pdf'): string
    {
        // Validate report template exists
        $inputPath = $this->jasperConfig['reports_dir'] . '/' . $reportName . '.jrxml';
        if (!file_exists($inputPath)) {
            throw new \Exception("Report template not found: {$reportName}.jrxml");
        }

        // Generate unique output filename
        $timestamp = now()->format('YmdHis');
        $outputFilename = "{$reportName}_{$timestamp}";
        $outputPath = $this->jasperConfig['temp_dir'] . '/' . $outputFilename;

        // Initialize PHPJasper
        $jasper = new PHPJasper;

        // Build options array
        $options = $this->buildReportOptions($format, $params);

        try {
            // Process the report
            $output = $jasper->process(
                $inputPath,
                $outputPath,
                $options
            )->execute();

            // Check if output was successful
            if ($output) {
                Log::warning('JasperReport execution output: ' . $output);
            }

            // Verify file was created
            $generatedFile = $outputPath . '.' . $format;
            if (!file_exists($generatedFile)) {
                throw new \Exception("Report file was not generated: {$generatedFile}");
            }

            Log::info("Report generated successfully: {$generatedFile}");

            return $generatedFile;

        } catch (\Exception $e) {
            Log::error('JasperReport Process Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Build report options array
     */
    private function buildReportOptions(string $format, array $params): array
    {
        $options = [
            'format' => [$format],
            'locale' => config('app.locale', 'en'),
            'params' => $params,
        ];

        // Add resources if moneyformatter is needed
        $resourcesPath = $this->jasperConfig['resources_dir'] . '/moneyformatter.jar';
        if (file_exists($resourcesPath)) {
            $options['resources'] = $resourcesPath;
        }

        // Add database connection
        $options['db_connection'] = $this->getDatabaseConnection();

        return $options;
    }

    /**
     * Get database connection configuration
     */
    private function getDatabaseConnection(): array
    {

        // Ensure JDBC driver exists
        $jdbcDriverPath = $this->jasperConfig['jdbc_dir'] . '/mssql-jdbc-13.2.0.jre8.jar';

        if (!file_exists($jdbcDriverPath)) {
            // Try alternative driver names
            $drivers = glob($this->jasperConfig['jdbc_dir'] . '/mssql-jdbc-*.jar');
            if (empty($drivers)) {
                throw new \Exception(
                    "SQL Server JDBC driver not found in: " . $this->jasperConfig['jdbc_dir'] .
                    "\nPlease download from: https://docs.microsoft.com/en-us/sql/connect/jdbc/download-microsoft-jdbc-driver-for-sql-server"
                );
            }
        }

        return [
            'driver' => 'generic',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'aalerpdb'),
            'username' => env('DB_USERNAME', 'atdn'),
            'password' => env('DB_PASSWORD', 'atdn'),
            'jdbc_driver' => 'com.microsoft.sqlserver.jdbc.SQLServerDriver',
            'jdbc_url' => $this->buildJdbcUrl(),
            'jdbc_dir' => $this->jasperConfig['jdbc_dir'],
        ];
    }

    /**
     * Build JDBC URL
     */
    private function buildJdbcUrl(): string
    {
        $host = env('DB_HOST', '127.0.0.1');
        $port = env('DB_PORT', '1433');
        $database = env('DB_DATABASE', 'aalerpdb');
        $encrypt = 'true';
        $trustCert = 'true';

        return "jdbc:sqlserver://{$host}:{$port};databaseName={$database};encrypt={$encrypt};trustServerCertificate={$trustCert}";
    }

    /**
     * Ensure required directories exist
     */
    private function ensureDirectoriesExist(): void
    {
        $directories = [
            $this->jasperConfig['reports_dir'],
            $this->jasperConfig['temp_dir'],
            $this->jasperConfig['resources_dir'],
        ];

        foreach ($directories as $directory) {
            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
                Log::info("Created directory: {$directory}");
            }
        }
    }

    /**
     * Clean up old temporary files (run via scheduled task)
     */
    public function cleanupTempFiles()
    {
        try {
            $tempDir = $this->jasperConfig['temp_dir'];
            $files = glob($tempDir . '/*');
            $now = time();
            $deletedCount = 0;

            foreach ($files as $file) {
                // Delete files older than 24 hours
                if (is_file($file) && ($now - filemtime($file) >= 86400)) {
                    unlink($file);
                    $deletedCount++;
                }
            }

            Log::info("Cleaned up {$deletedCount} temporary report files");

            return response()->json([
                'success' => true,
                'message' => "Cleaned up {$deletedCount} temporary files"
            ]);

        } catch (\Exception $e) {
            Log::error('Temp file cleanup failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Cleanup failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get list of available reports
     */
    public function getAvailableReports()
    {
        try {
            $reportsDir = $this->jasperConfig['reports_dir'];
            $files = glob($reportsDir . '/*.jrxml');
            $reports = [];

            foreach ($files as $file) {
                $reports[] = [
                    'name' => basename($file, '.jrxml'),
                    'path' => $file,
                    'modified' => date('Y-m-d H:i:s', filemtime($file))
                ];
            }

            return response()->json([
                'success' => true,
                'reports' => $reports
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to list reports: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve reports list',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test database connection
     */
    public function testConnection()
    {
        try {
            $jasper = new PHPJasper;

            // Create a simple test report
            $testJrxml = $this->jasperConfig['temp_dir'] . '/connection_test.jrxml';
            $this->createTestReport($testJrxml);


            $output = $this->jasperConfig['temp_dir'] . '/connection_test_' . now()->timestamp;

            $options = [
                'format' => ['pdf'],
                'db_connection' => $this->getDatabaseConnection()
            ];



            $result = $jasper->process($testJrxml, $output, $options)->execute();

            // Clean up test files
            @unlink($testJrxml);
            @unlink($output . '.pdf');

            return response()->json([
                'success' => true,
                'message' => 'Database connection successful',
                'config' => [
                    'host' => env('JASPER_DB_HOST', '127.0.0.1'),
                    'database' => env('JASPER_DB_DATABASE', 'aalerpdb')
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Connection test failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Database connection failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a simple test report for connection testing
     */
    private function createTestReport(string $path): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
        <jasperReport xmlns="http://jasperreports.sourceforge.net/jasperreports"
                      xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                      xsi:schemaLocation="http://jasperreports.sourceforge.net/jasperreports
                      http://jasperreports.sourceforge.net/xsd/jasperreport.xsd"
                      name="connection_test" pageWidth="595" pageHeight="842"
                      columnWidth="555" leftMargin="20" rightMargin="20"
                      topMargin="20" bottomMargin="20">
            <queryString>
                <![CDATA[SELECT 1 as test]]>
            </queryString>
            <field name="test" class="java.lang.Integer"/>
            <title>
                <band height="50">
                    <staticText>
                        <reportElement x="0" y="0" width="200" height="30"/>
                        <text><![CDATA[Connection Test Report]]></text>
                    </staticText>
                </band>
            </title>
        </jasperReport>';

        file_put_contents($path, $xml);
    }
}

