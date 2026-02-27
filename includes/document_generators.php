<?php
/**
 * Document Generators - Utility functions for document processing
 * ============================================
 *
 * Contains functions for filling DOCX templates, converting to PDF,
 * and formatting dates for document templates.
 */

function formatDateForTemplate($dateString)
{
    if (!$dateString)
        return '';
    try {
        $date = new DateTime($dateString);
        return $date->format('F j, Y'); // e.g., "February 23, 2026"
    } catch (Exception $e) {
        return $dateString;
    }
}

function fillDocxTemplate($templatePath, $data)
{
    // ADD THIS DEBUGGING CODE
    error_log("=== fillDocxTemplate START ===");
    error_log("Template path: " . $templatePath);
    error_log("Data keys: " . implode(', ', array_keys($data)));

    // Log the structure of notedList and approvedList specifically
    if (isset($data['notedList'])) {
        error_log("notedList type: " . gettype($data['notedList']));
        error_log("notedList content: " . print_r($data['notedList'], true));
    }
    if (isset($data['approvedList'])) {
        error_log("approvedList type: " . gettype($data['approvedList']));
        error_log("approvedList content: " . print_r($data['approvedList'], true));
    }

    if (!file_exists($templatePath)) {
        throw new Exception("Template file not found: $templatePath");
    }
    $templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);

    // Handle repeating blocks for noted and approved
    if (isset($data['notedList']) && is_array($data['notedList']) && count($data['notedList']) > 0) {
        // Clone the block for each person
        $templateProcessor->cloneBlock('noted', count($data['notedList']), true, true);

        // Set values for each cloned block with bold name and italic title
        foreach ($data['notedList'] as $index => $person) {
            $num = $index + 1;
            $templateProcessor->setValue('noted_name#' . $num, '<w:rPr><w:b/></w:rPr>' . htmlspecialchars($person['name'])); // Bold name
            $templateProcessor->setValue('noted_title#' . $num, '<w:rPr><w:i/></w:rPr>' . htmlspecialchars($person['title']) . "\n\n"); // Italic title with spacing
        }
    } else {
        // If no noted people, remove the entire block
        $templateProcessor->cloneBlock('noted', 0, true, true);
    }

    if (isset($data['approvedList']) && is_array($data['approvedList']) && count($data['approvedList']) > 0) {
        // Clone the block for each person
        $templateProcessor->cloneBlock('approved', count($data['approvedList']), true, true);

        // Set values for each cloned block with bold name and italic title
        foreach ($data['approvedList'] as $index => $person) {
            $num = $index + 1;
            $templateProcessor->setValue('approved_name#' . $num, '<w:rPr><w:b/></w:rPr>' . htmlspecialchars($person['name'])); // Bold name
            $templateProcessor->setValue('approved_title#' . $num, '<w:rPr><w:i/></w:rPr>' . htmlspecialchars($person['title']) . "\n\n"); // Italic title with spacing
        }
    } else {
        // If no approved people, remove the entire block
        $templateProcessor->cloneBlock('approved', 0, true, true);
    }

    // Remove the list arrays from data to prevent double processing
    unset($data['notedList'], $data['approvedList']);

    // Special handling for "from" field - format with bold name and italic title
    if (isset($data['from']) && isset($data['from_title'])) {
        $formattedFrom = '<w:rPr><w:b/></w:rPr>' . htmlspecialchars($data['from']) . "\n" . '<w:rPr><w:i/></w:rPr>' . htmlspecialchars($data['from_title']); // Bold name, italic title
        $templateProcessor->setValue('from', $formattedFrom);
        unset($data['from'], $data['from_title']);
    }

    // Replace simple placeholders
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            if ($key === 'objectives' || $key === 'ilos') {
                // Simple bulleted list for arrays of strings
                $value = implode("\n• ", array_map('htmlspecialchars', $value));
            } elseif ($key === 'program') {
                // Format program schedule
                $lines = [];
                foreach ($value as $item) {
                    $lines[] = htmlspecialchars("{$item['start']} - {$item['end']}: {$item['act']}");
                }
                $value = implode("\n", $lines);
            } elseif ($key === 'schedule') {
                // Format schedule summaries as date/time list
                $lines = [];
                foreach ($value as $item) {
                    if (!empty($item['date']) && !empty($item['time'])) {
                        $lines[] = htmlspecialchars("{$item['date']} at {$item['time']}");
                    } elseif (!empty($item['date'])) {
                        $lines[] = htmlspecialchars($item['date']);
                    } elseif (!empty($item['time'])) {
                        $lines[] = htmlspecialchars($item['time']);
                    }
                }
                $value = implode("\n", $lines);
            } elseif ($key === 'budget') {
                // Format budget table as text
                $lines = [];
                foreach ($value as $item) {
                    $lines[] = htmlspecialchars("{$item['name']} - {$item['size']} - Qty: {$item['qty']} - Price: ₱{$item['price']} - Total: ₱{$item['total']}");
                }
                $value = implode("\n", $lines);
            } else {
                $value = implode(", ", array_map('htmlspecialchars', $value));
            }
        } elseif (is_string($value)) {
            $value = htmlspecialchars($value);
        }
        $templateProcessor->setValue($key, $value);
    }

    // Generate unique filename for filled document
    $outputDir = ROOT_PATH . 'uploads/';
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    // Use title in filename for better identification
    $title = $data['title'] ?? $data['eventName'] ?? $data['subject'] ?? 'Untitled';
    $safeTitle = preg_replace('/[^A-Za-z0-9\-_]/', '_', $title);
    $safeTitle = substr($safeTitle, 0, 30); // Shorter limit for initial files
    $outputPath = $outputDir . 'doc_' . $safeTitle . '_' . uniqid() . '.docx';
    $templateProcessor->saveAs($outputPath);
    return $outputPath;
}

// Convert DOCX to PDF using CloudConvert
function convertDocxToPdf($docxPath)
{
    $logFile = __DIR__ . '/../conversion.log';
    $log = date('Y-m-d H:i:s') . " - Starting conversion for: " . $docxPath . "\n";
    file_put_contents($logFile, $log, FILE_APPEND);

    $apiKey = $_ENV['CLOUDCONVERT_API_KEY'] ?? null;
    if (!$apiKey) {
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - ERROR: API key not found\n", FILE_APPEND);
        return $docxPath; // Return original path if conversion fails
    }

    file_put_contents($logFile, date('Y-m-d H:i:s') . " - API key found\n", FILE_APPEND);

    if (!file_exists($docxPath)) {
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - ERROR: DOCX file does not exist: $docxPath\n", FILE_APPEND);
        return $docxPath;
    }

    file_put_contents($logFile, date('Y-m-d H:i:s') . " - DOCX file exists, size: " . filesize($docxPath) . "\n", FILE_APPEND);

    try {
        $cloudconvert = new \CloudConvert\CloudConvert([
            'api_key' => $apiKey,
            'sandbox' => false
        ]);

        file_put_contents($logFile, date('Y-m-d H:i:s') . " - CloudConvert client created\n", FILE_APPEND);

        $job = (new \CloudConvert\Models\Job())
            ->addTask(new \CloudConvert\Models\Task('import/upload', 'upload-my-file'))
            ->addTask(
                (new \CloudConvert\Models\Task('convert', 'convert-my-file'))
                    ->set('input', 'upload-my-file')
                    ->set('output_format', 'pdf')
            )
            ->addTask(
                (new \CloudConvert\Models\Task('export/url', 'export-my-file'))
                    ->set('input', 'convert-my-file')
            );

        file_put_contents($logFile, date('Y-m-d H:i:s') . " - Creating job\n", FILE_APPEND);
        $job = $cloudconvert->jobs()->create($job);
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - Job created: " . $job->getId() . "\n", FILE_APPEND);

        $uploadTask = $job->getTasks()->whereName('upload-my-file')[0];
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - Uploading file\n", FILE_APPEND);
        $fileContent = file_get_contents($docxPath);
        $cloudconvert->tasks()->upload($uploadTask, $fileContent, basename($docxPath));
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - File uploaded\n", FILE_APPEND);

        file_put_contents($logFile, date('Y-m-d H:i:s') . " - Waiting for completion\n", FILE_APPEND);
        $cloudconvert->jobs()->wait($job);
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - Job completed, status: " . $job->getStatus() . "\n", FILE_APPEND);

        if ($job->getStatus() === 'finished') {
            $exportTask = $job->getTasks()->whereName('export-my-file')[0];
            $result = $exportTask->getResult();
            if (isset($result->files) && count($result->files) > 0) {
                $fileUrl = $result->files[0]->url;
                file_put_contents($logFile, date('Y-m-d H:i:s') . " - Downloading PDF from: $fileUrl\n", FILE_APPEND);
                $pdfContent = file_get_contents($fileUrl);
                $pdfPath = str_replace('.docx', '.pdf', $docxPath);
                file_put_contents($pdfPath, $pdfContent);
                file_put_contents($logFile, date('Y-m-d H:i:s') . " - PDF saved to: $pdfPath\n", FILE_APPEND);

                // Clean up DOCX
                if (file_exists($docxPath)) {
                    $unlinkResult = unlink($docxPath);
                    if ($unlinkResult) {
                        file_put_contents($logFile, date('Y-m-d H:i:s') . " - DOCX cleaned up successfully\n", FILE_APPEND);
                    } else {
                        file_put_contents($logFile, date('Y-m-d H:i:s') . " - ERROR: Failed to clean up DOCX\n", FILE_APPEND);
                    }
                } else {
                    file_put_contents($logFile, date('Y-m-d H:i:s') . " - WARNING: DOCX file not found for cleanup\n", FILE_APPEND);
                }

                file_put_contents($logFile, date('Y-m-d H:i:s') . " - Conversion successful\n", FILE_APPEND);
                return $pdfPath;
            } else {
                file_put_contents($logFile, date('Y-m-d H:i:s') . " - ERROR: No files in result\n", FILE_APPEND);
            }
        } else {
            file_put_contents($logFile, date('Y-m-d H:i:s') . " - ERROR: Job failed with status: " . $job->getStatus() . "\n", FILE_APPEND);
        }

    } catch (Exception $e) {
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - EXCEPTION: " . $e->getMessage() . "\n", FILE_APPEND);
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - Stack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
    }

    file_put_contents($logFile, date('Y-m-d H:i:s') . " - Returning original path: $docxPath\n", FILE_APPEND);
    return $docxPath; // Return original path if conversion fails
}
?>